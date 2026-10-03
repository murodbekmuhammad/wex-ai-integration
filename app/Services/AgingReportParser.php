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
 * code, without Claude. Only the table inside each page's frame is read, as
 * marked in resources/images/invoice_aging_instructor.png: the rows under the
 * column header and above the page footer. The text is placed by its position
 * on the page, so the page header (factor, client, title, "as of" date) can
 * never end up among the rows, whatever order the PDF stores it in. The rows
 * must add up to the report's grand total, so a layout the parser doesn't
 * understand fails loudly instead of producing wrong numbers.
 */
class AgingReportParser
{
    private const DATE = '\d{1,2}\/\d{1,2}\/\d{4}';

    private const AMOUNT = '-?[\d,]+\.\d{2}';

    /**
     * How far, in points, the column header's labels reach down from its top
     * line; its labels are stacked up to three lines high.
     */
    private const HEADER_HEIGHT = 30;

    /**
     * The height of one line of the column header, in points.
     */
    private const HEADER_LINE = 10;

    /**
     * Text this close vertically, in points, is on the same line.
     */
    private const LINE_TOLERANCE = 2;

    /**
     * parsePdf
     *
     * @param string $contents raw PDF bytes
     * @return array{factor: string|null, title: string|null, client: string|null, as_of: string|null, grand_total: float, invoices: array<int, array{broker: string, invoice: string, load_id: string|null, purchase_date: string, schedule: string, amount: float, paid_date: string|null, balance: float, age: int}>}
     * @throws AgingReportException
     */
    public function parsePdf(string $contents): array
    {
        return $this->parsePages($this->pages($contents));
    }

    /**
     * parsePages
     *
     * Read the report from its pages' text. Invoice rows come only from the
     * table inside each page's frame (see frame); the first page header is
     * read for the report details, which name the sheet but never become
     * rows. A broker's invoices that continue onto the next page stay with
     * that broker.
     *
     * @param array<int, array<int, array{x: float, y: float, text: string}>> $pages each page's text items, y counted up from the bottom
     * @return array{factor: string|null, title: string|null, client: string|null, as_of: string|null, grand_total: float, invoices: array<int, array{broker: string, invoice: string, load_id: string|null, purchase_date: string, schedule: string, amount: float, paid_date: string|null, balance: float, age: int}>}
     * @throws AgingReportException when the pages aren't an aging report or don't add up
     */
    public function parsePages(array $pages): array
    {
        $details = null;
        $lines = [];

        foreach (array_values($pages) as $index => $items) {
            [$pageHeader, $table] = $this->frame($items, $index + 1);
            $details ??= $this->details($pageHeader);
            array_push($lines, ...$this->lines($table));
        }

        return [...($details ?? $this->details([])), ...$this->rows($lines)];
    }

    /**
     * pages
     *
     * Every page's text items with their position.
     *
     * @param string $contents raw PDF bytes
     * @return array<int, array<int, array{x: float, y: float, text: string}>>
     * @throws AgingReportException when the PDF can't be read
     */
    protected function pages(string $contents): array
    {
        try {
            $pages = (new Parser)->parseContent($contents)->getPages();

            return array_map(fn ($page) => array_map(fn (array $item) => [
                'x' => (float) $item[0][4],
                'y' => (float) $item[0][5],
                'text' => (string) $item[1],
            ], $page->getDataTm()), $pages);
        } catch (Exception) {
            throw new AgingReportException('The PDF could not be read.');
        }
    }

    /**
     * frame
     *
     * Split a page into the page header above the column header, and the
     * table: the items below the column header and above the footer (printed
     * date, page number). The column header itself belongs to neither, and
     * must hold the invoice aging columns.
     *
     * @param array<int, array{x: float, y: float, text: string}> $items
     * @param int $page page number, for the error message
     * @return array{0: array<int, array{x: float, y: float, text: string}>, 1: array<int, array{x: float, y: float, text: string}>} the page header items and the table items
     * @throws AgingReportException when the page has no invoice aging column header
     */
    private function frame(array $items, int $page): array
    {
        // Worded labels ("Invoice#", "Days") find the header; number labels ("1-30", "121") may sit a line above them.
        $worded = array_filter($items, fn (array $item) => $this->isColumnLabel($item['text']) && preg_match('/[a-z]/i', $item['text']));
        $wordedTop = $worded ? max(array_column($worded, 'y')) : 0;
        $wordedBottom = $worded ? min(array_filter(array_column($worded, 'y'), fn (float $y) => $y >= $wordedTop - self::HEADER_HEIGHT)) : 0;

        $header = array_filter(
            $worded ? $items : [],
            fn (array $item) => $item['y'] >= $wordedBottom - self::LINE_TOLERANCE
                && $item['y'] <= $wordedTop + self::HEADER_LINE
                && $this->isColumnLabel($item['text']),
        );
        $words = array_merge([], ...array_map(fn (array $item) => $this->words($item['text']), $header));
        $missing = array_filter($this->columns(), fn (string $column) => array_diff($this->words($column), $words) !== []);

        if (! $header || $missing) {
            throw new AgingReportException(sprintf(
                'The table header on page %d doesn\'t have the invoice aging columns (missing: %s). The report layout may have changed.',
                $page,
                implode(', ', $missing ?: $this->columns()),
            ));
        }

        $top = max(array_column($header, 'y'));
        $bottom = min(array_column($header, 'y'));
        $footer = array_filter($items, fn (array $item) => $item['y'] < $bottom && preg_match('/^(Page|Printed:)/', trim($item['text'])));
        $footerTop = $footer ? max(array_column($footer, 'y')) : -INF;

        return [
            array_values(array_filter($items, fn (array $item) => $item['y'] > $top + self::LINE_TOLERANCE)),
            array_values(array_filter($items, fn (array $item) => $item['y'] < $bottom - self::LINE_TOLERANCE && $item['y'] > $footerTop + self::LINE_TOLERANCE)),
        ];
    }

    /**
     * lines
     *
     * Put text items back together into lines, top to bottom, with the
     * items of a line in left-to-right order and separated by tabs.
     *
     * @param array<int, array{x: float, y: float, text: string}> $items
     * @return array<int, string>
     */
    private function lines(array $items): array
    {
        usort($items, fn (array $a, array $b) => [$b['y'], $a['x']] <=> [$a['y'], $b['x']]);

        $lines = [];
        $lineY = null;

        foreach ($items as $item) {
            $text = trim($item['text']);

            if ($text === '') {
                continue;
            }

            if ($lineY === null || $lineY - $item['y'] > self::LINE_TOLERANCE) {
                $lines[] = [];
                $lineY = $item['y'];
            }

            $lines[count($lines) - 1][] = $item;
        }

        return array_map(function (array $line) {
            usort($line, fn (array $a, array $b) => $a['x'] <=> $b['x']);

            return implode("\t", array_map(fn (array $item) => trim($item['text']), $line));
        }, $lines);
    }

    /**
     * rows
     *
     * Read the invoices from the table's lines and check them against the
     * report's grand total.
     *
     * @param array<int, string> $lines
     * @return array{grand_total: float, invoices: array<int, array{broker: string, invoice: string, load_id: string|null, purchase_date: string, schedule: string, amount: float, paid_date: string|null, balance: float, age: int}>}
     * @throws AgingReportException when there are no invoices or they don't add up
     */
    private function rows(array $lines): array
    {
        $invoices = [];
        $broker = null;
        $lastWasInvoice = false;
        $grandTotal = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($invoice = $this->invoiceLine($line)) {
                $invoices[] = ['broker' => $broker ?? 'Unknown', ...$invoice];
                $lastWasInvoice = true;

                continue;
            }

            if (preg_match('/^Grand(?:\s+Total)?\s+('.self::AMOUNT.')/', $line, $match)) {
                $grandTotal = $this->number($match[1]);
            } elseif ($lastWasInvoice && preg_match('/^[^\s*]+$/', $line)) {
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

        if ($grandTotal === null) {
            throw new AgingReportException('The report has no grand total to check the invoices against.');
        }

        $sum = round(array_sum(array_column($invoices, 'balance')), 2);

        if (abs($sum - $grandTotal) > 0.005) {
            throw new AgingReportException(sprintf(
                'The invoices read from the report add up to %s, but its grand total is %s. The report layout may have changed.',
                number_format($sum, 2),
                number_format($grandTotal, 2),
            ));
        }

        return ['grand_total' => $grandTotal, 'invoices' => $invoices];
    }

    /**
     * details
     *
     * The factor, title, client and "as of" date from a page header: the
     * factor and title on its first line, the client and date on its second.
     *
     * @param array<int, array{x: float, y: float, text: string}> $items the page header items
     * @return array{factor: string|null, title: string|null, client: string|null, as_of: string|null}
     */
    private function details(array $items): array
    {
        $lines = array_map(fn (string $line) => explode("\t", $line), $this->lines($items));
        $asOf = Arr::first(array_merge([], ...$lines), fn (string $text) => preg_match('/^As Of\s+/', $text));
        $client = $lines[1][0] ?? null;

        return [
            'factor' => $lines[0][0] ?? null,
            'title' => isset($lines[0][1]) ? end($lines[0]) : null,
            'client' => $client !== null && $client !== $asOf ? trim(preg_replace('/\([^()]*\)$/', '', $client)) : null,
            'as_of' => $asOf ? $this->date(preg_replace('/^As Of\s+/', '', $asOf), 'F j, Y') : null,
        ];
    }

    /**
     * isColumnLabel
     *
     * Whether the text is part of the column header: every word in it is a
     * word of an invoice aging column name, like "Debtor..", "Invoice#" or
     * "1-30".
     *
     * @param string $text
     * @return bool
     */
    private function isColumnLabel(string $text): bool
    {
        $words = $this->words($text);

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
