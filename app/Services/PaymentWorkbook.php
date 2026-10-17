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
 * @class PaymentWorkbook
 *
 * @package App\Services
 *
 * Builds the payment report workbook from a parsed daily payment report: a
 * PAYMENT DETAIL tab with every invoice line and its payor, debtor and
 * check, and a PAYMENT SUMMARY tab with the report's collection summary and
 * the amount totals per payor and per adjust type. It looks like the reserve
 * account workbook (see ReserveWorkbook).
 */
class PaymentWorkbook
{
    /**
     * The PAYMENT DETAIL columns: each row field with its header.
     */
    public const COLUMNS = [
        'payor' => 'Payor',
        'debtor' => 'Debtor',
        'check_date' => 'Check Date',
        'check' => 'Check#',
        'invoice' => 'Invoice#',
        'purchased' => 'Purchased',
        'schedule' => 'Sch#',
        'invoice_amount' => 'Invoice Amount',
        'days' => 'Days',
        'check_amount' => 'Check Amount',
        'payment_amount' => 'Payment Amount',
        'adjust_type' => 'Adjust Type',
        'adjust_amount' => 'Adjust Amount',
        'escrow_amount' => 'Escrow Amount',
        'fee_earned' => 'Fee Earned',
        'taxes_held' => 'Taxes Held',
        'note' => 'Note',
    ];

    /**
     * The amount fields totalled on the PAYMENT SUMMARY tab, with their
     * labels.
     */
    private const TOTALS = [
        'check_amount' => 'Check Amount',
        'payment_amount' => 'Payment Amount',
        'adjust_amount' => 'Adjust Amount',
        'escrow_amount' => 'Escrow Amount',
        'fee_earned' => 'Fee Earned',
        'taxes_held' => 'Taxes Held',
    ];

    private const DATE_FIELDS = ['check_date', 'purchased'];

    private const MONEY_FIELDS = ['invoice_amount', 'check_amount', 'payment_amount', 'adjust_amount', 'escrow_amount', 'fee_earned', 'taxes_held'];

    private const DATE_FORMAT = 'm/d/yyyy';

    private const MONEY_FORMAT = '"$"#,##0.00';

    private const HEADER_FILL = '1F3864';

    /**
     * tabs
     *
     * The tabs this workbook has, in order. In the shared Google Sheet these
     * are the payment report agent's tabs (see SharedWorkbook).
     *
     * @return array<int, string>
     */
    public static function tabs(): array
    {
        return ['PAYMENT DETAIL', 'PAYMENT SUMMARY'];
    }

    /**
     * build
     *
     * The workbook's .xlsx bytes.
     *
     * @param array{factor: string|null, client: string|null, from: string|null, to: string|null, summary: array<string, float>, rows: array<int, array<string, string|int|float|null>>} $report
     * @return string
     */
    public function build(array $report): string
    {
        $spreadsheet = new Spreadsheet;
        $rows = collect($report['rows']);

        $this->detail($spreadsheet->getActiveSheet(), $rows);
        $this->summarySheet($spreadsheet->createSheet(), $report, $rows);
        $spreadsheet->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($spreadsheet))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * summary
     *
     * The report's headline figures.
     *
     * @param array{rows: array<int, array<string, mixed>>} $report
     * @return array{rows: int, checks: int, payors: int, collected: float, payments: float, adjustments: float, escrow: float, fees: float}
     */
    public function summary(array $report): array
    {
        $rows = collect($report['rows']);

        return [
            'rows' => $rows->count(),
            'checks' => $rows->whereNotNull('check_amount')->count(),
            'payors' => $rows->pluck('payor')->filter()->unique()->count(),
            'collected' => round($rows->sum('check_amount'), 2),
            'payments' => round($rows->sum('payment_amount'), 2),
            'adjustments' => round($rows->sum('adjust_amount'), 2),
            'escrow' => round($rows->sum('escrow_amount'), 2),
            'fees' => round($rows->sum('fee_earned'), 2),
        ];
    }

    /**
     * detail
     *
     * The PAYMENT DETAIL tab: one line per invoice line of the report.
     *
     * @param Worksheet $sheet
     * @param Collection<int, array<string, string|int|float|null>> $rows
     * @return void
     */
    private function detail(Worksheet $sheet, Collection $rows): void
    {
        $sheet->setTitle('PAYMENT DETAIL');

        $this->fill($sheet, [
            array_values(self::COLUMNS),
            ...$rows->map(fn (array $row) => array_map(
                fn (string $field) => in_array($field, self::DATE_FIELDS, true) ? $this->excelDate($row[$field]) : $row[$field],
                array_keys(self::COLUMNS),
            ))->all(),
        ]);

        $last = Coordinate::stringFromColumnIndex(count(self::COLUMNS));
        $lastRow = $rows->count() + 1;
        $this->styleHeader($sheet, "A1:{$last}1");
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$last}{$lastRow}");

        if ($rows->isNotEmpty()) {
            foreach (self::DATE_FIELDS as $field) {
                $column = $this->column($field);
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
            }

            foreach (self::MONEY_FIELDS as $field) {
                $column = $this->column($field);
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            }
        }

        foreach (range(1, count(self::COLUMNS)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }
    }

    /**
     * summarySheet
     *
     * The PAYMENT SUMMARY tab: the report details, the report's collection
     * summary, and the amount totals per payor and, for adjusted lines, per
     * adjust type, each with a total line.
     *
     * @param Worksheet $sheet
     * @param array{factor: string|null, client: string|null, from: string|null, to: string|null, summary: array<string, float>} $report
     * @param Collection<int, array<string, string|int|float|null>> $rows
     * @return void
     */
    private function summarySheet(Worksheet $sheet, array $report, Collection $rows): void
    {
        $sheet->setTitle('PAYMENT SUMMARY');
        $period = $report['from'] && $report['to']
            ? Carbon::parse($report['from'])->format('M j, Y').' – '.Carbon::parse($report['to'])->format('M j, Y')
            : null;

        $this->fill($sheet, [
            ['Payment report summary'],
            ['Factor', $report['factor']],
            ['Client', $report['client']],
            ['Period', $period],
        ]);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2:A4')->getFont()->setBold(true);

        $next = 6;

        if ($report['summary']) {
            $collection = [['Collection summary', 'Amount'], ...array_map(fn (string $label, float $amount) => [$label, $amount], array_keys($report['summary']), $report['summary'])];
            $this->fill($sheet, $collection, $next);
            $lastRow = $next + count($collection) - 1;
            $this->styleHeader($sheet, "A{$next}:B{$next}");
            $sheet->getStyle('B'.($next + 1).":B{$lastRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $next = $lastRow + 2;
        }

        $next = $this->totals($sheet, $next, 'Payor', $rows, 'payor');
        $adjusted = $rows->whereNotNull('adjust_type');

        if ($adjusted->isNotEmpty()) {
            $this->totals($sheet, $next + 1, 'Adjust type', $adjusted, 'adjust_type');
        }

        foreach (range(1, count(self::TOTALS) + 2) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }
    }

    /**
     * totals
     *
     * A table of the amount totals per value of a field, with a total line.
     *
     * @param Worksheet $sheet
     * @param int $firstRow
     * @param string $label the first column's header
     * @param Collection<int, array<string, string|int|float|null>> $rows
     * @param string $field the field to group by
     * @return int the first row below the table
     */
    private function totals(Worksheet $sheet, int $firstRow, string $label, Collection $rows, string $field): int
    {
        $sum = fn (Collection $group) => array_map(
            fn (string $amount) => round($group->sum(fn (array $row) => $row[$amount] ?? 0), 2),
            array_keys(self::TOTALS),
        );

        $lines = $rows->groupBy(fn (array $row) => $row[$field] ?? '(none)')
            ->map(fn (Collection $group, string|int $value) => [(string) $value, $group->count(), ...$sum($group)])
            ->values();

        $table = [[$label, 'Rows', ...array_values(self::TOTALS)], ...$lines->all(), ['Total', $rows->count(), ...$sum($rows)]];
        $this->fill($sheet, $table, $firstRow);

        $last = Coordinate::stringFromColumnIndex(count(self::TOTALS) + 2);
        $lastRow = $firstRow + count($table) - 1;
        $this->styleHeader($sheet, "A{$firstRow}:{$last}{$firstRow}");
        $sheet->getStyle("A{$lastRow}:{$last}{$lastRow}")->getFont()->setBold(true);
        $sheet->getStyle('C'.($firstRow + 1).":{$last}{$lastRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

        return $lastRow + 1;
    }

    /**
     * column
     *
     * The PAYMENT DETAIL column letter of a row field.
     *
     * @param string $field
     * @return string
     */
    private function column(string $field): string
    {
        return Coordinate::stringFromColumnIndex(array_search($field, array_keys(self::COLUMNS), true) + 1);
    }

    /**
     * excelDate
     *
     * @param string|null $date Y-m-d
     * @return float|null the Excel serial date
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
