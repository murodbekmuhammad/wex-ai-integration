<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Smalot\PdfParser\Parser;

/**
 * @class ReserveReportParser
 *
 * @package App\Services
 *
 * Reads a factor's "Reserve Detail Report" PDF into reserve rows in code,
 * without Claude. Only the table inside each page's frame is read, as marked
 * in resources/images/reserved_account_detail_instructor.png: the lines under
 * the two-line column header and above the page footer.
 *
 * The table holds transactions ("9/1/2026 Cash Collection Report#2939"),
 * each followed by its detail lines (one per invoice, with its debtor, fee
 * days, activity type and amounts) and a subtotal. Every detail line becomes
 * a row that carries its transaction's date, type, description and
 * reference; a transaction without detail lines, like the opening "Bal"
 * balance or a "Fee" rebate, is a row of its own. The rows must add up to
 * the report's grand total and end on its reserve balance, so a layout the
 * parser doesn't understand fails loudly instead of producing wrong numbers.
 */
class ReserveReportParser
{
    private const DATE = '\d{1,2}\/\d{1,2}\/\d{4}';

    private const AMOUNT = '\(?-?[\d,]+\.\d{2}\)?';

    /**
     * How far, in points, the column header's labels reach down from its top
     * line; its labels are stacked up to three lines high.
     */
    private const HEADER_HEIGHT = 30;

    /**
     * Text this close vertically, in points, is on the same line.
     */
    private const LINE_TOLERANCE = 2;

    /**
     * The table's columns, left to right, each named by a word of its label
     * on the column header's bottom line. The first four columns hold a
     * transaction's date, type, description and reference on its own line,
     * and the invoice#, PO#, check and debtor on its detail lines.
     */
    private const SLOTS = [
        'invoice' => 'invoice',
        'po' => 'po',
        'check' => 'check',
        'debtor' => 'debtor',
        'buy_date' => 'date',
        'fee_days' => 'days',
        'activity_type' => 'type',
        'check_amount' => 'amount',
        'applied_ar' => 'r',
        'applied_advanced' => 'advanced',
        'applied_fee' => 'fee',
        'reserve_amount' => 'amount',
        'reserve_balance' => 'balance',
    ];

    /**
     * The amount columns, in the table's order.
     */
    public const AMOUNTS = ['check_amount', 'applied_ar', 'applied_advanced', 'applied_fee', 'reserve_amount', 'reserve_balance'];

    /**
     * Amount columns the rows are checked against the grand total for. The
     * fee column is left out: its grand total takes off the "Fee" rebate
     * transactions, which the report prints as a reserve amount.
     */
    private const CHECKED = ['check_amount', 'applied_ar', 'applied_advanced', 'reserve_amount'];

    /**
     * The width of one character of the table's 8 pt Helvetica, in points,
     * to find where a right-aligned amount ends.
     */
    private const CHARACTER_WIDTHS = ['digit' => 4.45, ',' => 2.22, '.' => 2.22, '(' => 2.66, ')' => 2.66, '-' => 2.66];

    /**
     * parsePdf
     *
     * @param string $contents raw PDF bytes
     * @return array{factor: string|null, title: string|null, client: string|null, from: string|null, to: string|null, opening_balance: float|null, closing_balance: float, grand_total: array<string, float|null>, rows: array<int, array<string, string|int|float|null>>}
     * @throws ReserveReportException
     */
    public function parsePdf(string $contents): array
    {
        return $this->parsePages($this->pages($contents));
    }

    /**
     * parsePages
     *
     * Read the report from its pages' text. Rows come only from the table
     * inside each page's frame (see frame); the first page header is read
     * for the report details. A transaction whose detail lines continue onto
     * the next page keeps them.
     *
     * @param array<int, array<int, array{x: float, y: float, text: string}>> $pages each page's text items, y counted up from the bottom
     * @return array{factor: string|null, title: string|null, client: string|null, from: string|null, to: string|null, opening_balance: float|null, closing_balance: float, grand_total: array<string, float|null>, rows: array<int, array<string, string|int|float|null>>}
     * @throws ReserveReportException when the pages aren't a reserve detail report or don't add up
     */
    public function parsePages(array $pages): array
    {
        $details = null;
        $lines = [];

        foreach (array_values($pages) as $index => $items) {
            [$pageHeader, $table, $columns] = $this->frame($items, $index + 1);
            $details ??= $this->details($pageHeader);

            foreach ($this->lines($table) as $line) {
                $lines[] = $this->cells($line, $columns);
            }
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
     * @throws ReserveReportException when the PDF can't be read
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
            throw new ReserveReportException('The PDF could not be read.');
        }
    }

    /**
     * frame
     *
     * Split a page into the page header above the column header, and the
     * table: the items below the column header and above the footer (printed
     * date, page number). The column header must hold the reserve account
     * detail columns; where each label on its bottom line starts is where
     * its column starts.
     *
     * @param array<int, array{x: float, y: float, text: string}> $items
     * @param int $page page number, for the error message
     * @return array{0: array<int, array{x: float, y: float, text: string}>, 1: array<int, array{x: float, y: float, text: string}>, 2: array<string, float>} the page header items, the table items and each column's left edge
     * @throws ReserveReportException when the page has no reserve account detail column header
     */
    private function frame(array $items, int $page): array
    {
        $labels = array_filter($items, fn (array $item) => $this->isColumnLabel($item['text']));
        $top = $labels ? max(array_column($labels, 'y')) : 0;
        $header = array_filter($labels, fn (array $item) => $item['y'] >= $top - self::HEADER_HEIGHT);
        $words = array_merge([], ...array_map(fn (array $item) => $this->words($item['text']), $header));
        $missing = array_filter($this->columns(), fn (string $column) => array_diff($this->words($column), $words) !== []);

        if (! $header || $missing) {
            throw new ReserveReportException(sprintf(
                'The table header on page %d doesn\'t have the reserve account detail columns (missing: %s). The report layout may have changed.',
                $page,
                implode(', ', $missing ?: $this->columns()),
            ));
        }

        $bottom = min(array_column($header, 'y'));
        $bottomLine = array_values(array_filter($header, fn (array $item) => $item['y'] <= $bottom + self::LINE_TOLERANCE));
        usort($bottomLine, fn (array $a, array $b) => $a['x'] <=> $b['x']);
        $matches = count($bottomLine) === count(self::SLOTS) && collect(array_values(self::SLOTS))
            ->every(fn (string $word, int $index) => in_array($word, $this->words($bottomLine[$index]['text']), true));

        if (! $matches) {
            throw new ReserveReportException("The column labels on page {$page} aren't in the expected order. The report layout may have changed.");
        }

        $footer = array_filter($items, fn (array $item) => $item['y'] < $bottom && preg_match('/^(Page|Printed:)/', trim($item['text'])));
        $footerTop = $footer ? max(array_column($footer, 'y')) : -INF;

        return [
            array_values(array_filter($items, fn (array $item) => $item['y'] > $top + self::LINE_TOLERANCE)),
            array_values(array_filter($items, fn (array $item) => $item['y'] < $bottom - self::LINE_TOLERANCE && $item['y'] > $footerTop + self::LINE_TOLERANCE)),
            array_combine(array_keys(self::SLOTS), array_column($bottomLine, 'x')),
        ];
    }

    /**
     * lines
     *
     * Group text items into lines, top to bottom, with the items of a line
     * in left-to-right order.
     *
     * @param array<int, array{x: float, y: float, text: string}> $items
     * @return array<int, array<int, array{x: float, y: float, text: string}>>
     */
    private function lines(array $items): array
    {
        usort($items, fn (array $a, array $b) => [$b['y'], $a['x']] <=> [$a['y'], $b['x']]);

        $lines = [];
        $lineY = null;

        foreach ($items as $item) {
            $item['text'] = trim($item['text']);

            if ($item['text'] === '') {
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

            return $line;
        }, $lines);
    }

    /**
     * cells
     *
     * Put a line's texts in their columns. Texts left of the buy date column
     * go to the nearest of the first four columns. Right of it, an amount
     * goes to the column it ends in (amounts are right-aligned: they end
     * after their label starts and before the next label starts), a date is
     * the buy date, a whole number the fee days and other text the activity
     * type. A "Grand Total" or "Client Total" label is kept as "total".
     *
     * @param array<int, array{x: float, y: float, text: string}> $line
     * @param array<string, float> $columns each column's left edge
     * @return array<string, string>
     */
    private function cells(array $line, array $columns): array
    {
        $cells = [];
        $text = array_slice($columns, 0, 4);
        $amounts = Arr::only($columns, self::AMOUNTS);
        $rightPart = ($columns['debtor'] + $columns['buy_date']) / 2;

        foreach ($line as $item) {
            if (preg_match('/^(Grand|Client) Total$/', $item['text'])) {
                $column = 'total';
            } elseif ($item['x'] < $rightPart) {
                $column = collect($text)->sortBy(fn (float $x) => abs($x - $item['x']))->keys()->first();
            } elseif (preg_match('/^'.self::AMOUNT.'$/', $item['text'])) {
                $end = $item['x'] + $this->width($item['text']);
                $column = collect($amounts)->filter(fn (float $x) => $x < $end)->keys()->last() ?? 'check_amount';
            } elseif (preg_match('/^'.self::DATE.'$/', $item['text'])) {
                $column = 'buy_date';
            } elseif (preg_match('/^\d+$/', $item['text'])) {
                $column = 'fee_days';
            } else {
                $column = 'activity_type';
            }

            $cells[$column] = isset($cells[$column]) ? "{$cells[$column]} {$item['text']}" : $item['text'];
        }

        return $cells;
    }

    /**
     * rows
     *
     * Read the rows from the table's lines and check them against the
     * report's grand total and closing reserve balance.
     *
     * @param array<int, array<string, string>> $lines each line's texts by column (see cells)
     * @return array{opening_balance: float|null, closing_balance: float, grand_total: array<string, float|null>, rows: array<int, array<string, string|int|float|null>>}
     * @throws ReserveReportException when there are no rows or they don't add up
     */
    private function rows(array $lines): array
    {
        $rows = [];
        $transaction = null;
        $hasDetails = false;
        $wrapping = false;
        $grandTotal = null;

        $close = function () use (&$rows, &$transaction, &$hasDetails) {
            if ($transaction !== null && ! $hasDetails) {
                $rows[] = $transaction;
            }
        };

        foreach ($lines as $cells) {
            $wasWrapping = $wrapping;
            $wrapping = false;

            if (isset($cells['total'])) {
                if (str_starts_with($cells['total'], 'Grand')) {
                    $grandTotal = $this->amounts($cells);
                }
            } elseif (preg_match('/^'.self::DATE.'$/', $cells['invoice'] ?? '') && isset($cells['po'])) {
                $close();
                $transaction = [
                    'date' => $this->date($cells['invoice']),
                    'invoice' => null,
                    'type' => $cells['po'],
                    'po' => null,
                    'description' => $cells['check'] ?? null,
                    'check' => null,
                    'reference' => $cells['debtor'] ?? null,
                    'debtor' => null,
                    'buy_date' => null,
                    'fee_days' => null,
                    'activity_type' => null,
                    ...$this->amounts($cells),
                ];
                $hasDetails = false;
            } elseif (isset($cells['fee_days']) || isset($cells['activity_type'])) {
                $rows[] = [
                    'date' => $transaction['date'] ?? null,
                    'invoice' => $cells['invoice'] ?? null,
                    'type' => $transaction['type'] ?? null,
                    'po' => $cells['po'] ?? null,
                    'description' => $transaction['description'] ?? null,
                    'check' => $cells['check'] ?? null,
                    'reference' => $transaction['reference'] ?? null,
                    'debtor' => $cells['debtor'] ?? null,
                    'buy_date' => isset($cells['buy_date']) ? $this->date($cells['buy_date']) : null,
                    'fee_days' => isset($cells['fee_days']) ? (int) $cells['fee_days'] : null,
                    'activity_type' => $cells['activity_type'] ?? null,
                    ...$this->amounts($cells),
                ];
                $hasDetails = true;
                $wrapping = true;
            } elseif ($wasWrapping && $cells && array_diff(array_keys($cells), ['invoice', 'po', 'check', 'debtor']) === []) {
                // A long debtor or invoice# wraps onto the lines under its detail line.
                $last = array_key_last($rows);

                foreach ($cells as $column => $text) {
                    $rows[$last][$column] = $column === 'debtor' ? trim("{$rows[$last][$column]} {$text}") : $rows[$last][$column].$text;
                }

                $wrapping = true;
            }
        }

        $close();

        if (! $rows) {
            throw new ReserveReportException('No reserve rows were found. This doesn\'t look like a reserve account detail report.');
        }

        if ($grandTotal === null) {
            throw new ReserveReportException('The report has no grand total to check the rows against.');
        }

        foreach (self::CHECKED as $column) {
            $sum = round(array_sum(array_column($rows, $column)), 2);

            if (abs($sum - ($grandTotal[$column] ?? 0)) > 0.005) {
                throw new ReserveReportException(sprintf(
                    'The %s read from the report add up to %s, but its grand total is %s. The report layout may have changed.',
                    $this->label($column),
                    number_format($sum, 2),
                    number_format($grandTotal[$column] ?? 0, 2),
                ));
            }
        }

        $closing = Arr::last(array_column($rows, 'reserve_balance'), fn (?float $balance) => $balance !== null);

        if ($closing === null || abs($closing - ($grandTotal['reserve_amount'] ?? 0)) > 0.005) {
            throw new ReserveReportException(sprintf(
                'The last reserve balance read from the report is %s, but its grand total reserve amount is %s. The report layout may have changed.',
                number_format($closing ?? 0, 2),
                number_format($grandTotal['reserve_amount'] ?? 0, 2),
            ));
        }

        $rows = array_map(fn (array $row) => [...$row, 'debtor' => $this->debtorName($row['debtor'])], $rows);
        $opening = Arr::first($rows, fn (array $row) => $row['type'] === 'Bal');

        return [
            'opening_balance' => $opening['reserve_balance'] ?? null,
            'closing_balance' => $closing,
            'grand_total' => Arr::except($grandTotal, 'reserve_balance'),
            'rows' => $rows,
        ];
    }

    /**
     * amounts
     *
     * The amount columns of a line, null where the line has none.
     *
     * @param array<string, string> $cells
     * @return array<string, float|null>
     */
    private function amounts(array $cells): array
    {
        return collect(self::AMOUNTS)
            ->mapWithKeys(fn (string $column) => [$column => isset($cells[$column]) ? $this->number($cells[$column]) : null])
            ->all();
    }

    /**
     * debtorName
     *
     * The debtor's name, e.g. "RXO INC (MASTER)" from "RXO INC (MASTER)
     * (carrierpaperwork@rxo.com) (K41587)", without its email and debtor
     * code.
     *
     * @param string|null $debtor
     * @return string|null
     */
    private function debtorName(?string $debtor): ?string
    {
        if ($debtor === null) {
            return null;
        }

        $name = preg_replace('/\s*\([^()]*\)$/', '', preg_replace('/\s+/', ' ', trim($debtor)));
        $name = trim(preg_replace('/\s*\([^()]*@[^()]*\)/', '', $name));

        return $name === '' ? null : $name;
    }

    /**
     * details
     *
     * The factor, title, client and period from a page header: the factor
     * and title on its first line, the client and period ("September 1,
     * 2026 Thru September 30, 2026") on its second.
     *
     * @param array<int, array{x: float, y: float, text: string}> $items the page header items
     * @return array{factor: string|null, title: string|null, client: string|null, from: string|null, to: string|null}
     */
    private function details(array $items): array
    {
        $lines = array_map(fn (array $line) => array_column($line, 'text'), $this->lines($items));
        $client = $lines[1][0] ?? null;
        $period = isset($lines[1][1]) ? end($lines[1]) : null;
        preg_match('/^(.+?)\s+Thru\s+(.+)$/i', (string) $period, $dates);

        return [
            'factor' => $lines[0][0] ?? null,
            'title' => isset($lines[0][1]) ? end($lines[0]) : null,
            'client' => $client !== null ? trim(preg_replace(['/^Client:\s*/i', '/\s*\([^()]*\)$/'], '', $client)) : null,
            'from' => $dates ? $this->date($dates[1], 'F j, Y') : null,
            'to' => $dates ? $this->date($dates[2], 'F j, Y') : null,
        ];
    }

    /**
     * isColumnLabel
     *
     * Whether the text is part of the column header: it has a letter, and
     * every word in it is a word of a reserve account detail column name,
     * like "Description..", "Invoice#.." or "A/R".
     *
     * @param string $text
     * @return bool
     */
    private function isColumnLabel(string $text): bool
    {
        $words = $this->words($text);

        return preg_match('/[a-z]/i', $text)
            && $words !== []
            && array_diff($words, array_merge(...array_map($this->words(...), $this->columns()))) === [];
    }

    /**
     * columns
     *
     * The reserve account detail column names from config/report_types.php.
     *
     * @return array<int, string>
     */
    private function columns(): array
    {
        return array_map('strval', Arr::flatten(config('report_types.reserve_account_detail')));
    }

    /**
     * label
     *
     * An amount column's name for messages, e.g. "Applied To A/R".
     *
     * @param string $column
     * @return string
     */
    private function label(string $column): string
    {
        return $this->columns()[11 + array_search($column, self::AMOUNTS, true)] ?? $column;
    }

    /**
     * words
     *
     * The lowercase words of a text, with "#" as a word of its own, so
     * "Invoice#" and "Invoice #" read the same.
     *
     * @param string $text
     * @return array<int, string>
     */
    private function words(string $text): array
    {
        preg_match_all('/[a-z0-9]+|#/', mb_strtolower($text), $matches);

        return $matches[0];
    }

    /**
     * width
     *
     * How wide an amount is printed, in points.
     *
     * @param string $amount e.g. "(1,600.00)"
     * @return float
     */
    private function width(string $amount): float
    {
        return array_sum(array_map(
            fn (string $character) => self::CHARACTER_WIDTHS[ctype_digit($character) ? 'digit' : $character] ?? self::CHARACTER_WIDTHS['digit'],
            str_split($amount),
        ));
    }

    /**
     * date
     *
     * @param string $value
     * @param string $format
     * @return string Y-m-d
     */
    private function date(string $value, string $format = 'n/j/Y'): string
    {
        return Carbon::createFromFormat($format, trim($value))->toDateString();
    }

    /**
     * number
     *
     * @param string $value e.g. "2,700.00", or "(1.87)" for a negative amount
     * @return float
     */
    private function number(string $value): float
    {
        $number = (float) str_replace([',', '(', ')'], '', $value);

        return str_starts_with(trim($value), '(') ? -$number : $number;
    }
}
