<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * @class AgingWorkbook
 *
 * @package App\Services
 *
 * Builds the factoring workbook from a parsed aging report, laid out like the
 * team's "FACTORING" Google Sheet: a DASH BOARD, the AGING list, one tab per
 * age threshold and a per-broker summary. Columns the report has no data for,
 * such as notes and follow-ups, are left empty for the team to fill in, and
 * tabs that need other reports (charge-backs, payments) are added with their
 * column headers only.
 */
class AgingWorkbook
{
    /**
     * The AGING tab's columns, in the team's order. "Email" and "Call" each
     * span two columns.
     */
    public const AGING_COLUMNS = [
        'BROKER', 'INVOICE', 'LOAD ID', 'INVOICE DATE', 'AGE', 'INVOICE $', 'PURCHASED $', 'PAID $',
        'DIFFERENCE', 'SCHEDULE DATE', 'PAYMENT DATE', 'PAY STATUS', 'WHO?', 'LOAD STATUS', 'C.B STATUS',
        'RESOLVED', 'Email', null, 'Call', null, 'Last Follow up', 'FACTORING NOTE', 'DISPATCH NOTE',
        'ACCOUNTING NOTE', 'Driver name', 'Save board', 'Invoice Month', 'Payment Month', 'Accountant',
    ];

    /**
     * Age thresholds that get their own tab, e.g. "Aging 30+".
     */
    public const THRESHOLDS = [1, 30, 45, 60, 90];

    /**
     * Tabs placed right after AGING that the aging report has no data for
     * yet, with the team's column headers.
     */
    public const HEADER_ONLY_TABS_AFTER_AGING = [
        'CHARGES OLD' => ['#', 'Customer', 'Purchased', 'Invoice Amount', 'Posted', 'Paid On', 'Amount Paid', 'Amount Charged', 'Issue', 'Status', 'Factorin Note', 'Customer Note', 'Dispatch Note', 'Accounting Note'],
        'Sheet16' => ['Payor/Check#', 'Invoice#', 'Purchased', 'Sch#', 'Invoice Amount', 'Days', 'Check Amount', 'Payment Amount', 'Adjust Type', 'Adjust Amount', 'Escrow Amount', 'Fee Earned', 'Taxes Held', 'Payment date'],
    ];

    /**
     * Tabs placed after the age threshold tabs that have no data yet.
     */
    public const HEADER_ONLY_TABS_AFTER_THRESHOLDS = [
        'validation' => ['charge back status', 'Load status'],
        'Accsaveboard' => ['LOAD ID', 'Driver name', 'INVOICE DATE', 'INVOICE $', 'PAID $', 'DIFFERENCE', 'C.B STATUS', 'Note'],
    ];

    /**
     * Aging columns on the factor's report, as [label, first day, last day].
     */
    public const BUCKETS = [['1-30 Days', 0, 30], ['31-60 Days', 31, 60], ['61-90 Days', 61, 90], ['91-120 Days', 91, 120], ['121+ Days', 121, PHP_INT_MAX]];

    private const DATE_FORMAT = 'm/d/yyyy';

    private const MONEY_FORMAT = '"$"#,##0.00';

    private const HEADER_FILL = '1F3864';

    /**
     * build
     *
     * The workbook's .xlsx bytes.
     *
     * @param array{factor: string|null, title: string|null, client: string|null, as_of: string|null, grand_total: float, invoices: array<int, array<string, mixed>>} $report
     * @return string
     */
    public function build(array $report): string
    {
        $spreadsheet = new Spreadsheet;
        $invoices = collect($report['invoices'])->sortByDesc('age')->values();

        $this->dashboard($spreadsheet->getActiveSheet(), $report, $invoices);
        $this->aging($spreadsheet->createSheet(), 'AGING', $invoices, 1);

        foreach (self::HEADER_ONLY_TABS_AFTER_AGING as $title => $columns) {
            $this->headerOnly($spreadsheet->createSheet(), $title, $columns);
        }

        foreach (self::THRESHOLDS as $days) {
            $sheet = $spreadsheet->createSheet();
            $matching = $invoices->where('age', '>=', $days)->values();
            $this->fill($sheet, [['Invoices', $matching->count(), null, null, null, $matching->sum('balance')]]);
            $sheet->mergeCells('B1:E1');
            $sheet->mergeCells('F1:L1');
            $sheet->getStyle('A1:L1')->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle('F1')->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $this->aging($sheet, "Aging {$days}+", $matching, 2);
        }

        foreach (self::HEADER_ONLY_TABS_AFTER_THRESHOLDS as $title => $columns) {
            $this->headerOnly($spreadsheet->createSheet(), $title, $columns);
        }

        $this->brokers($spreadsheet->createSheet(), $invoices);

        $spreadsheet->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($spreadsheet))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * summary
     *
     * The report's headline figures, for the dashboard and the agent.
     *
     * @param array{invoices: array<int, array<string, mixed>>} $report
     * @return array{invoices: int, brokers: int, invoiced: float, paid: float, balance: float, buckets: array<string, array{invoices: int, amount: float}>, statuses: array<string, float>, top_brokers: array<string, float>}
     */
    public function summary(array $report): array
    {
        $invoices = collect($report['invoices']);

        return [
            'invoices' => $invoices->count(),
            'brokers' => $invoices->pluck('broker')->unique()->count(),
            'invoiced' => round($invoices->sum('amount'), 2),
            'paid' => round($invoices->sum(fn (array $invoice) => $this->paid($invoice)), 2),
            'balance' => round($invoices->sum('balance'), 2),
            'buckets' => collect(self::BUCKETS)->mapWithKeys(function (array $bucket) use ($invoices) {
                $inBucket = $invoices->whereBetween('age', [$bucket[1], $bucket[2]]);

                return [$bucket[0] => ['invoices' => $inBucket->count(), 'amount' => round($inBucket->sum('balance'), 2)]];
            })->all(),
            'statuses' => $invoices->groupBy(fn (array $invoice) => $this->payStatus($invoice))
                ->map(fn (Collection $group) => round($group->sum('balance'), 2))
                ->all(),
            'top_brokers' => $invoices->groupBy('broker')
                ->map(fn (Collection $group) => round($group->sum('balance'), 2))
                ->sortDesc()
                ->take(10)
                ->all(),
        ];
    }

    /**
     * dashboard
     *
     * @param Worksheet $sheet
     * @param array<string, mixed> $report
     * @param Collection<int, array<string, mixed>> $invoices
     * @return void
     */
    private function dashboard(Worksheet $sheet, array $report, Collection $invoices): void
    {
        $sheet->setTitle('DASH BOARD');
        $summary = $this->summary(['invoices' => $invoices->all()]);

        $this->fill($sheet, [
            [null, 'DASH BOARD'],
            [],
            [null, 'Factor', $report['factor']],
            [null, 'Client', $report['client']],
            [null, 'As of', $this->excelDate($report['as_of'])],
            [null, 'Source report', $report['title']],
            [],
            [null, 'Invoices', $summary['invoices']],
            [null, 'Brokers', $summary['brokers']],
            [null, 'Invoiced $', $summary['invoiced']],
            [null, 'Paid $', $summary['paid']],
            [null, 'Open balance $', $summary['balance']],
        ]);
        $sheet->mergeCells('B1:H1');
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('B1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL);
        $sheet->getStyle('B1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B3:B12')->getFont()->setBold(true);
        $sheet->getStyle('C5')->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
        $sheet->getStyle('C10:C12')->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

        $row = 14;
        $row = $this->dashboardTable($sheet, $row, ['Aging', 'Invoices', 'Amount'], collect($summary['buckets'])
            ->map(fn (array $bucket, string $label) => [$label, $bucket['invoices'], $bucket['amount']])->values()->all());
        $row = $this->dashboardTable($sheet, $row + 1, ['Collection Status', 'Amount'], collect($summary['statuses'])
            ->map(fn (float $amount, string $status) => [$status, $amount])->values()->all());
        $this->dashboardTable($sheet, $row + 1, ['Top brokers by balance', 'Amount'], collect($summary['top_brokers'])
            ->map(fn (float $amount, string $broker) => [$broker, $amount])->values()->all());

        $sheet->getColumnDimension('A')->setWidth(3);
        $sheet->getColumnDimension('B')->setWidth(45);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(18);
    }

    /**
     * dashboardTable
     *
     * Write a small titled table starting in column B, with money in its
     * last column.
     *
     * @param Worksheet $sheet
     * @param int $row first row
     * @param array<int, string> $header
     * @param array<int, array<int, string|int|float>> $rows
     * @return int the row after the table
     */
    private function dashboardTable(Worksheet $sheet, int $row, array $header, array $rows): int
    {
        $last = Coordinate::stringFromColumnIndex(count($header) + 1);

        $this->fill($sheet, array_map(fn (array $cells) => [null, ...$cells], [$header, ...$rows]), $row);
        $this->styleHeader($sheet, "B{$row}:{$last}{$row}");
        $sheet->getStyle("{$last}".($row + 1).":{$last}".($row + count($rows)))->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

        return $row + count($rows) + 1;
    }

    /**
     * aging
     *
     * Write the invoice list with the team's columns, starting at the given
     * row.
     *
     * @param Worksheet $sheet
     * @param string $title
     * @param Collection<int, array<string, mixed>> $invoices
     * @param int $headerRow
     * @return void
     */
    private function aging(Worksheet $sheet, string $title, Collection $invoices, int $headerRow): void
    {
        $sheet->setTitle($title);

        $rows = $invoices->map(fn (array $invoice) => [
            $invoice['broker'],
            $invoice['invoice'],
            $invoice['load_id'],
            $this->excelDate($invoice['purchase_date']),
            $invoice['age'],
            $invoice['amount'],
            null,
            $this->paid($invoice),
            $invoice['balance'],
            null,
            $this->excelDate($invoice['paid_date']),
            $this->payStatus($invoice),
            ...array_fill(0, 14, null),
            Carbon::parse($invoice['purchase_date'])->format('F Y'),
            $invoice['paid_date'] ? Carbon::parse($invoice['paid_date'])->format('F Y') : null,
            null,
        ])->all();

        $this->fill($sheet, [self::AGING_COLUMNS, ...$rows], $headerRow);

        $lastColumn = Coordinate::stringFromColumnIndex(count(self::AGING_COLUMNS));
        $lastRow = $headerRow + count($rows);

        $sheet->mergeCells("Q{$headerRow}:R{$headerRow}");
        $sheet->mergeCells("S{$headerRow}:T{$headerRow}");
        $this->styleHeader($sheet, "A{$headerRow}:{$lastColumn}{$headerRow}");
        $sheet->freezePane('C'.($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");

        if ($rows) {
            $first = $headerRow + 1;
            $sheet->getStyle("D{$first}:D{$lastRow}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
            $sheet->getStyle("K{$first}:K{$lastRow}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
            $sheet->getStyle("F{$first}:I{$lastRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        }

        $sheet->getColumnDimension('A')->setWidth(40);

        foreach (range(2, count(self::AGING_COLUMNS)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth(15);
        }
    }

    /**
     * headerOnly
     *
     * A tab with the team's column headers and no rows yet.
     *
     * @param Worksheet $sheet
     * @param string $title
     * @param array<int, string> $columns
     * @return void
     */
    private function headerOnly(Worksheet $sheet, string $title, array $columns): void
    {
        $sheet->setTitle($title);
        $this->fill($sheet, [$columns]);

        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $this->styleHeader($sheet, "A1:{$lastColumn}1");
        $sheet->freezePane('A2');

        foreach (range(1, count($columns)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth(16);
        }
    }

    /**
     * brokers
     *
     * The per-broker summary on the "data" tab, largest balance first.
     *
     * @param Worksheet $sheet
     * @param Collection<int, array<string, mixed>> $invoices
     * @return void
     */
    private function brokers(Worksheet $sheet, Collection $invoices): void
    {
        $sheet->setTitle('data');

        $rows = $invoices->groupBy('broker')
            ->map(fn (Collection $group, string $broker) => [
                $broker,
                $group->count(),
                round($group->sum('balance'), 2),
                $group->min('age'),
                $group->max('age'),
                round($group->avg('age'), 1),
            ])
            ->sortByDesc(fn (array $row) => $row[2])
            ->values()
            ->all();

        $this->fill($sheet, [['Broker', 'Total invoices', 'Total Amount', 'Aging MIN', 'Aging MAX', 'Aging AVG'], ...$rows]);
        $this->styleHeader($sheet, 'A1:F1');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:F'.(count($rows) + 1));
        $sheet->getStyle('C2:C'.(count($rows) + 1))->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $sheet->getColumnDimension('A')->setWidth(45);

        foreach (['B', 'C', 'D', 'E', 'F'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(15);
        }
    }

    /**
     * paid
     *
     * @param array<string, mixed> $invoice
     * @return float
     */
    private function paid(array $invoice): float
    {
        return round($invoice['amount'] - $invoice['balance'], 2);
    }

    /**
     * payStatus
     *
     * @param array<string, mixed> $invoice
     * @return string "Paid", "Short paid" or "Unpaid"
     */
    private function payStatus(array $invoice): string
    {
        return match (true) {
            $invoice['balance'] <= 0 => 'Paid',
            $this->paid($invoice) > 0 => 'Short paid',
            default => 'Unpaid',
        };
    }

    /**
     * excelDate
     *
     * @param string|null $date Y-m-d
     * @return float|null the date as an Excel serial number
     */
    private function excelDate(?string $date): ?float
    {
        return $date ? (float) Date::PHPToExcel(Carbon::parse($date)->startOfDay()) : null;
    }

    /**
     * styleHeader
     *
     * @param Worksheet $sheet
     * @param string $range
     * @return void
     */
    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    /**
     * fill
     *
     * Write rows into a sheet from column A. Every value is written with an
     * explicit type, so text from a PDF that starts with "=" stays text
     * instead of becoming a formula.
     *
     * @param Worksheet $sheet
     * @param array<int, array<int, string|int|float|null>> $rows
     * @param int $firstRow
     * @return void
     */
    private function fill(Worksheet $sheet, array $rows, int $firstRow = 1): void
    {
        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                if ($value === null) {
                    continue;
                }

                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($columnIndex + 1).($firstRow + $rowIndex),
                    $value,
                    is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING,
                );
            }
        }
    }
}
