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
 * @class ReserveWorkbook
 *
 * @package App\Services
 *
 * Builds the reserve account workbook from a parsed reserve account detail
 * report: a RESERVE DETAIL tab with every row under the report's own column
 * names, and a SUMMARY tab with the opening and closing reserve balance and
 * the totals per transaction type and per activity type. It looks like the
 * aging workbook (see AgingWorkbook).
 */
class ReserveWorkbook
{
    /**
     * The row fields in the order of the report's columns in
     * config/report_types.php.
     */
    public const FIELDS = [
        'date', 'invoice', 'type', 'po', 'description', 'check', 'reference', 'debtor', 'buy_date', 'fee_days',
        'activity_type', 'check_amount', 'applied_ar', 'applied_advanced', 'applied_fee', 'reserve_amount', 'reserve_balance',
    ];

    /**
     * The amount fields totalled on the SUMMARY tab, with their labels.
     */
    private const TOTALS = [
        'check_amount' => 'Check Amount',
        'applied_ar' => 'Applied To A/R',
        'applied_advanced' => 'Applied To Advanced',
        'applied_fee' => 'Applied To Fee',
        'reserve_amount' => 'Reserve Amount',
    ];

    private const DATE_FIELDS = ['date', 'buy_date'];

    private const DATE_FORMAT = 'm/d/yyyy';

    private const MONEY_FORMAT = '"$"#,##0.00';

    private const HEADER_FILL = '1F3864';

    /**
     * build
     *
     * The workbook's .xlsx bytes.
     *
     * @param array{factor: string|null, client: string|null, from: string|null, to: string|null, opening_balance: float|null, closing_balance: float, rows: array<int, array<string, string|int|float|null>>} $report
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
     * @param array{opening_balance: float|null, closing_balance: float, rows: array<int, array<string, mixed>>} $report
     * @return array{rows: int, debtors: int, opening_balance: float|null, closing_balance: float, reserve_change: float}
     */
    public function summary(array $report): array
    {
        $rows = collect($report['rows']);

        return [
            'rows' => $rows->count(),
            'debtors' => $rows->pluck('debtor')->filter()->unique()->count(),
            'opening_balance' => $report['opening_balance'],
            'closing_balance' => $report['closing_balance'],
            'reserve_change' => round($report['closing_balance'] - ($report['opening_balance'] ?? 0), 2),
        ];
    }

    /**
     * detail
     *
     * The RESERVE DETAIL tab: the report's columns and one line per row.
     *
     * @param Worksheet $sheet
     * @param Collection<int, array<string, string|int|float|null>> $rows
     * @return void
     */
    private function detail(Worksheet $sheet, Collection $rows): void
    {
        $sheet->setTitle('RESERVE DETAIL');
        $columns = config('report_types.reserve_account_detail');

        $this->fill($sheet, [
            $columns,
            ...$rows->map(fn (array $row) => array_map(
                fn (string $field) => in_array($field, self::DATE_FIELDS, true) ? $this->excelDate($row[$field]) : $row[$field],
                self::FIELDS,
            ))->all(),
        ]);

        $last = Coordinate::stringFromColumnIndex(count($columns));
        $lastRow = $rows->count() + 1;
        $this->styleHeader($sheet, "A1:{$last}1");
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$last}{$lastRow}");

        if ($rows->isNotEmpty()) {
            foreach (self::DATE_FIELDS as $field) {
                $column = $this->column($field);
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
            }

            $sheet->getStyle($this->column('check_amount')."2:{$last}{$lastRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        }

        foreach (range(1, count($columns)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }
    }

    /**
     * summarySheet
     *
     * The SUMMARY tab: the report details, the opening and closing reserve
     * balance, and the amount totals per transaction type and per activity
     * type, each with a total line.
     *
     * @param Worksheet $sheet
     * @param array{factor: string|null, client: string|null, from: string|null, to: string|null, opening_balance: float|null, closing_balance: float} $report
     * @param Collection<int, array<string, string|int|float|null>> $rows
     * @return void
     */
    private function summarySheet(Worksheet $sheet, array $report, Collection $rows): void
    {
        $sheet->setTitle('SUMMARY');
        $period = $report['from'] && $report['to']
            ? Carbon::parse($report['from'])->format('M j, Y').' – '.Carbon::parse($report['to'])->format('M j, Y')
            : null;

        $this->fill($sheet, [
            ['Reserve account summary'],
            ['Factor', $report['factor']],
            ['Client', $report['client']],
            ['Period', $period],
            ['Opening reserve balance', $report['opening_balance']],
            ['Closing reserve balance', $report['closing_balance']],
        ]);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2:A6')->getFont()->setBold(true);
        $sheet->getStyle('B5:B6')->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

        $next = $this->totals($sheet, 8, 'Transaction type', $rows, 'type');
        $this->totals($sheet, $next + 1, 'Activity type', $rows, 'activity_type');

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
     * The RESERVE DETAIL column letter of a row field.
     *
     * @param string $field
     * @return string
     */
    private function column(string $field): string
    {
        return Coordinate::stringFromColumnIndex(array_search($field, self::FIELDS, true) + 1);
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
