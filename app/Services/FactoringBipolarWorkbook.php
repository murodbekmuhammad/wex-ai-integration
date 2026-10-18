<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * @class FactoringBipolarWorkbook
 *
 * @package App\Services
 *
 * Builds the FACTORING-BIPOLAR tab from the tabs the invoice aging and
 * payment report agents put in the shared Google Sheet: one line per
 * PAYMENT DETAIL line whose Invoice# is on the AGING tab, with the
 * invoice's broker, load, date and amount from AGING and the payment amount
 * and check date from PAYMENT DETAIL. Payment lines for invoices that aren't
 * on AGING are left out.
 */
class FactoringBipolarWorkbook
{
    /**
     * The tab's columns, in order.
     */
    public const COLUMNS = ['Broker', 'Invoice', 'Load ID', 'Invoice date', 'Age', 'Invoice $', 'Paid $', 'Difference', 'Payment date', 'Payment status'];

    /**
     * The Payment status of every line until the team decides on the rules.
     */
    public const DEFAULT_STATUS = 'short pay';

    private const DATE_FORMAT = 'm/d/yyyy';

    private const MONEY_FORMAT = '"$"#,##0.00';

    private const HEADER_FILL = '1F3864';

    /**
     * tabs
     *
     * The tabs this workbook has, in order. In the shared Google Sheet this
     * is the factoring bipolar agent's tab (see SharedWorkbook).
     *
     * @return array<int, string>
     */
    public static function tabs(): array
    {
        return ['FACTORING-BIPOLAR'];
    }

    /**
     * report
     *
     * Read the shared sheet's AGING and PAYMENT DETAIL tabs and match their
     * lines by invoice number. Dates are Y-m-d.
     *
     * @param string $xlsx the shared sheet's .xlsx bytes
     * @return array{client: string|null, unmatched: int, rows: array<int, array{broker: string|null, invoice: string, load_id: string|null, invoice_date: string|null, age: int|null, invoice_amount: float|null, paid: float|null, difference: float|null, payment_date: string|null, status: string}>}
     * @throws FactoringBipolarException when the sheet lacks the AGING or PAYMENT DETAIL tab
     */
    public function report(string $xlsx): array
    {
        $book = $this->load($xlsx);

        try {
            $aging = $this->lines($book->getSheetByName('AGING'), 'AGING', 'the invoice aging agent');
            $payments = $this->lines($book->getSheetByName('PAYMENT DETAIL'), 'PAYMENT DETAIL', 'the payment report agent');
            $client = $this->client($book->getSheetByName('PAYMENT SUMMARY'));
        } finally {
            $book->disconnectWorksheets();
        }

        $invoices = [];

        foreach ($aging as $line) {
            $number = trim((string) ($line['INVOICE'] ?? ''));

            if ($number !== '' && ! isset($invoices[$number])) {
                $invoices[$number] = $line;
            }
        }

        $rows = [];
        $unmatched = 0;

        foreach ($payments as $payment) {
            $number = trim((string) ($payment['INVOICE#'] ?? ''));

            if ($number === '') {
                continue;
            }

            if (! isset($invoices[$number])) {
                $unmatched++;

                continue;
            }

            $invoice = $invoices[$number];
            $invoiceDate = $this->date($invoice['INVOICE DATE'] ?? null);
            $paymentDate = $this->date($payment['CHECK DATE'] ?? null);
            $amount = $this->money($invoice['INVOICE $'] ?? null);
            $paid = $this->money($payment['PAYMENT AMOUNT'] ?? null);

            $rows[] = [
                'broker' => $this->text($invoice['BROKER'] ?? null),
                'invoice' => $number,
                'load_id' => $this->text($invoice['LOAD ID'] ?? null),
                'invoice_date' => $invoiceDate,
                'age' => $invoiceDate && $paymentDate ? (int) round(Carbon::parse($invoiceDate)->diffInDays(Carbon::parse($paymentDate))) : null,
                'invoice_amount' => $amount,
                'paid' => $paid,
                'difference' => $amount !== null && $paid !== null ? round($amount - $paid, 2) : null,
                'payment_date' => $paymentDate,
                'status' => self::DEFAULT_STATUS,
            ];
        }

        return ['client' => $client, 'unmatched' => $unmatched, 'rows' => $rows];
    }

    /**
     * build
     *
     * The workbook's .xlsx bytes, with the FACTORING-BIPOLAR tab only.
     *
     * @param array<int, array{broker: string|null, invoice: string, load_id: string|null, invoice_date: string|null, age: int|null, invoice_amount: float|null, paid: float|null, difference: float|null, payment_date: string|null, status: string}> $rows lines from report()
     * @return string
     */
    public function build(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::tabs()[0]);

        $this->fill($sheet, [
            self::COLUMNS,
            ...array_map(fn (array $row) => [
                $row['broker'],
                $row['invoice'],
                $row['load_id'],
                $this->excelDate($row['invoice_date']),
                $row['age'],
                $row['invoice_amount'],
                $row['paid'],
                $row['difference'],
                $this->excelDate($row['payment_date']),
                $row['status'],
            ], $rows),
        ]);

        $last = Coordinate::stringFromColumnIndex(count(self::COLUMNS));
        $lastRow = count($rows) + 1;
        $this->styleHeader($sheet, "A1:{$last}1");
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$last}{$lastRow}");

        if ($rows) {
            $sheet->getStyle("D2:D{$lastRow}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
            $sheet->getStyle("I2:I{$lastRow}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
            $sheet->getStyle("F2:H{$lastRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        }

        $sheet->getColumnDimension('A')->setWidth(40);

        foreach (range(2, count(self::COLUMNS)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth(15);
        }

        ob_start();
        (new Xlsx($spreadsheet))->save('php://output');
        $xlsx = (string) ob_get_clean();

        $spreadsheet->disconnectWorksheets();

        return $xlsx;
    }

    /**
     * summary
     *
     * The tab's headline figures.
     *
     * @param array{unmatched: int, rows: array<int, array<string, mixed>>} $report
     * @return array{rows: int, invoices: int, unmatched_payments: int, invoiced: float, paid: float, difference: float}
     */
    public function summary(array $report): array
    {
        $rows = collect($report['rows']);

        return [
            'rows' => $rows->count(),
            'invoices' => $rows->pluck('invoice')->unique()->count(),
            'unmatched_payments' => $report['unmatched'],
            'invoiced' => round($rows->sum('invoice_amount'), 2),
            'paid' => round($rows->sum('paid'), 2),
            'difference' => round($rows->sum('difference'), 2),
        ];
    }

    /**
     * lines
     *
     * A tab's lines under its header row, each as its values by upper-case
     * header, so the team moving a column doesn't break the match.
     *
     * @param Worksheet|null $sheet
     * @param string $title the tab's name for messages
     * @param string $agent the agent that writes the tab, for messages
     * @return array<int, array<string, mixed>>
     * @throws FactoringBipolarException when the tab is missing
     */
    private function lines(?Worksheet $sheet, string $title, string $agent): array
    {
        if (! $sheet) {
            throw new FactoringBipolarException("The shared Google Sheet has no {$title} tab. Run {$agent} first.");
        }

        $rows = $sheet->toArray(null, false, false, false);
        $headers = array_map(fn (mixed $header) => mb_strtoupper(trim((string) $header)), array_shift($rows) ?? []);

        return array_map(
            fn (array $row) => array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), null)),
            $rows,
        );
    }

    /**
     * client
     *
     * The client named on the PAYMENT SUMMARY tab.
     *
     * @param Worksheet|null $summary
     * @return string|null
     */
    private function client(?Worksheet $summary): ?string
    {
        foreach (range(1, min(10, $summary?->getHighestDataRow() ?? 0)) as $row) {
            if (trim((string) $summary->getCell("A{$row}")->getValue()) === 'Client') {
                return $this->text($summary->getCell("B{$row}")->getValue());
            }
        }

        return null;
    }

    /**
     * date
     *
     * A date cell as Y-m-d: an Excel serial date, or text such as
     * "10/02/2026" when the team typed it in.
     *
     * @param mixed $value
     * @return string|null
     */
    private function date(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return Carbon::instance(Date::excelToDateTimeObject($value))->toDateString();
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * money
     *
     * An amount cell as a number; text such as "$1,234.50" is read too.
     *
     * @param mixed $value
     * @return float|null
     */
    private function money(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $value = str_replace(['$', ',', ' '], '', (string) $value);

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /**
     * text
     *
     * @param mixed $value
     * @return string|null the trimmed text; null when empty
     */
    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * load
     *
     * Read only the tabs this workbook is built from, without the empty
     * formatted cells of the sheet Google exports.
     *
     * @param string $xlsx workbook bytes
     * @return Spreadsheet
     */
    private function load(string $xlsx): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'sheet');
        file_put_contents($path, $xlsx);

        try {
            /** @var XlsxReader $reader */
            $reader = IOFactory::createReader('Xlsx');

            return $reader->setReadEmptyCells(false)
                ->setLoadSheetsOnly(['AGING', 'PAYMENT DETAIL', 'PAYMENT SUMMARY'])
                ->load($path);
        } finally {
            unlink($path);
        }
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
     * explicit type, so text that starts with "=" stays text instead of
     * becoming a formula.
     *
     * @param Worksheet $sheet
     * @param array<int, array<int, string|int|float|null>> $rows
     * @return void
     */
    private function fill(Worksheet $sheet, array $rows): void
    {
        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                if ($value === null) {
                    continue;
                }

                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 1),
                    $value,
                    is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING,
                );
            }
        }
    }
}
