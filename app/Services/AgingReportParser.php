<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Smalot\PdfParser\Parser;

/**
 * @class AgingReportParser
 *
 * @package App\Services
 *
 * Reads a factor's "Funded Detail Aging By Days" PDF into invoice rows in
 * code, without Claude. The rows must add up to the report's grand total, so
 * a layout the parser doesn't understand fails loudly instead of producing
 * wrong numbers.
 */
class AgingReportParser
{
    private const DATE = '\d{1,2}\/\d{1,2}\/\d{4}';

    private const AMOUNT = '-?[\d,]+\.\d{2}';

    /**
     * The page header lines under the footer line: factor, "as of" date, title and client.
     */
    private const PAGE_HEADER_LINES = 4;

    /**
     * parsePdf
     *
     * @param string $contents raw PDF bytes
     * @return array{factor: string|null, title: string|null, client: string|null, as_of: string|null, grand_total: float, invoices: array<int, array{broker: string, invoice: string, load_id: string|null, purchase_date: string, schedule: string, amount: float, paid_date: string|null, balance: float, age: int}>}
     * @throws AgingReportException
     */
    public function parsePdf(string $contents): array
    {
        return $this->parse($this->text($contents));
    }

    /**
     * parse
     *
     * Read the report's text. Invoice data comes only from the table area
     * (see tableArea); the page header is read once for the report details,
     * which name the sheet but never become rows. A broker's invoices that
     * continue onto the next page stay with that broker.
     *
     * @param string $text
     * @return array{factor: string|null, title: string|null, client: string|null, as_of: string|null, grand_total: float, invoices: array<int, array{broker: string, invoice: string, load_id: string|null, purchase_date: string, schedule: string, amount: float, paid_date: string|null, balance: float, age: int}>}
     * @throws AgingReportException when the text isn't an aging report or doesn't add up
     */
    public function parse(string $text): array
    {
        $header = $this->header($text);
        $body = $this->tableArea($text);

        $invoices = [];
        $broker = null;
        $lastWasInvoice = false;

        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim($line);

            if ($invoice = $this->invoiceLine($line)) {
                $invoices[] = ['broker' => $broker ?? 'Unknown', ...$invoice];
                $lastWasInvoice = true;

                continue;
            }

            if ($lastWasInvoice && preg_match('/^[^\s*]+$/', $line)) {
                // The line under an invoice holds its PO#, which is the load id.
                $invoices[count($invoices) - 1]['load_id'] = $line;
            } elseif (preg_match('/^(.+)\(([^()]+)\)$/', $line, $match)) {
                $broker = trim(preg_replace('/\s*\([^()]*@[^()]*\)/', '', $match[1]));
            }

            $lastWasInvoice = false;
        }

        if (! $invoices) {
            throw new AgingReportException('No invoice rows were found. This doesn\'t look like a detail aging report.');
        }

        if (! preg_match('/Grand\s+Total\s+('.self::AMOUNT.')/', $body, $match)) {
            throw new AgingReportException('The report has no grand total to check the invoices against.');
        }

        $grandTotal = $this->number($match[1]);
        $sum = round(array_sum(array_column($invoices, 'balance')), 2);

        if (abs($sum - $grandTotal) > 0.005) {
            throw new AgingReportException(sprintf(
                'The invoices read from the report add up to %s, but its grand total is %s. The report layout may have changed.',
                number_format($sum, 2),
                number_format($grandTotal, 2),
            ));
        }

        return [...$header, 'grand_total' => $grandTotal, 'invoices' => $invoices];
    }

    /**
     * text
     *
     * @param string $contents raw PDF bytes
     * @return string
     * @throws AgingReportException when the PDF can't be read
     */
    protected function text(string $contents): string
    {
        try {
            return (new Parser)->parseContent($contents)->getText();
        } catch (Exception) {
            throw new AgingReportException('The PDF could not be read.');
        }
    }

    /**
     * tableArea
     *
     * Only the table inside each page's frame, as marked in
     * resources/images/invoice_aging_instructor.png: the rows under the
     * column header. Everything outside it is dropped: the footer (printed
     * date, page number), the page header above the table (factor, "as of"
     * date, title, client) and the column header row itself, which must hold
     * the invoice aging columns. The parser reads every page's footer and
     * header as one block after that page's rows; a page header line found
     * anywhere else in the text, such as the client's name, is dropped too,
     * so it can never be read as a broker.
     *
     * @param string $text
     * @return string
     * @throws AgingReportException when a page's column header is missing or isn't the invoice aging one
     */
    private function tableArea(string $text): string
    {
        $lines = preg_split('/\R/', $text);
        $count = count($lines);
        $rows = [];
        $pageHeader = [];

        for ($i = 0; $i < $count; $i++) {
            if (! preg_match('/^Page\s+(\d+)\s+of\s+\d+/', trim($lines[$i]), $page)) {
                $rows[] = $lines[$i];

                continue;
            }

            foreach (array_slice($lines, $i + 1, self::PAGE_HEADER_LINES) as $line) {
                $pageHeader[trim($line)] = true;
            }

            $i += self::PAGE_HEADER_LINES;
            $labels = [];

            while ($i + 1 < $count && $this->isColumnLabel($lines[$i + 1])) {
                $labels = [...$labels, ...$this->words($lines[++$i])];
            }

            $missing = array_filter(
                $this->columns(),
                fn (string $column) => array_diff($this->words($column), $labels) !== [],
            );

            if (! $labels || $missing) {
                throw new AgingReportException(sprintf(
                    'The table header on page %d doesn\'t have the invoice aging columns (missing: %s). The report layout may have changed.',
                    $page[1],
                    implode(', ', $missing ?: $this->columns()),
                ));
            }
        }

        unset($pageHeader['']);

        return implode("\n", array_filter($rows, fn (string $line) => ! isset($pageHeader[trim($line)])));
    }

    /**
     * isColumnLabel
     *
     * Whether the line is part of the column header: every word in it is a
     * word of an invoice aging column name, like "Debtor..", "Invoice#" or
     * "1-30".
     *
     * @param string $line
     * @return bool
     */
    private function isColumnLabel(string $line): bool
    {
        $words = $this->words($line);

        return $words !== [] && array_diff($words, array_merge(...array_map($this->words(...), $this->columns()))) === [];
    }

    /**
     * columns
     *
     * The invoice aging column names from config/report_types.php.
     *
     * @return array<int, string>
     */
    private function columns(): array
    {
        return array_map('strval', Arr::flatten(config('report_types.invoice_aging')));
    }

    /**
     * words
     *
     * The lowercase words of a text, with "#" and "+" as words of their own,
     * so "Invoice#" and "Invoice #" read the same.
     *
     * @param string $text
     * @return array<int, string>
     */
    private function words(string $text): array
    {
        preg_match_all('/[a-z0-9]+|[#+]/', mb_strtolower($text), $matches);

        return $matches[0];
    }

    /**
     * header
     *
     * The factor, title, client and "as of" date from the first page header.
     *
     * @param string $text
     * @return array{factor: string|null, title: string|null, client: string|null, as_of: string|null}
     */
    private function header(string $text): array
    {
        preg_match('/Page\s+\d+\s+of\s+\d+[^\n]*\n\s*(.+?)\s*\n\s*As Of\s+(.+?)\s*\n\s*(.+?)\s*\n\s*(.+?)\s*\n/s', $text, $match);

        return [
            'factor' => $match[1] ?? null,
            'title' => $match[3] ?? null,
            'client' => isset($match[4]) ? trim(preg_replace('/\([^()]*\)$/', '', $match[4])) : null,
            'as_of' => isset($match[2]) ? $this->date($match[2], 'F j, Y') : null,
        ];
    }

    /**
     * invoiceLine
     *
     * Read one invoice row: an optional flag, invoice#, purchase date,
     * schedule#, invoice amount, an optional paid date, the balance, its age
     * in days and the balance again under its aging column.
     *
     * @param string $line
     * @return array{invoice: string, load_id: null, purchase_date: string, schedule: string, amount: float, paid_date: string|null, balance: float, age: int}|null
     */
    private function invoiceLine(string $line): ?array
    {
        $date = self::DATE;
        $amount = self::AMOUNT;

        if (! preg_match("/^(?:\\S\\t)?(\\S+)\\t({$date})\\t(\\S+)\\t({$amount})\\t(?:({$date})\\t)?({$amount})\\t(\\d+)\\t{$amount}$/", $line, $match)) {
            return null;
        }

        return [
            'invoice' => $match[1],
            'load_id' => null,
            'purchase_date' => $this->date($match[2], 'n/j/Y'),
            'schedule' => $match[3],
            'amount' => $this->number($match[4]),
            'paid_date' => $match[5] ? $this->date($match[5], 'n/j/Y') : null,
            'balance' => $this->number($match[6]),
            'age' => (int) $match[7],
        ];
    }

    /**
     * date
     *
     * @param string $value
     * @param string $format
     * @return string Y-m-d
     */
    private function date(string $value, string $format): string
    {
        return Carbon::createFromFormat($format, trim($value))->toDateString();
    }

    /**
     * number
     *
     * @param string $value e.g. "2,700.00"
     * @return float
     */
    private function number(string $value): float
    {
        return (float) str_replace(',', '', $value);
    }
}
