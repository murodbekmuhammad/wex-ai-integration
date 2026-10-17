<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Smalot\PdfParser\Parser;

/**
 * @class PaymentReportParser
 *
 * @package App\Services
 *
 * Reads a factor's daily payment report ("Collection Report Summary") PDF
 * into payment rows in code, without Claude. Only the table inside each
 * page's frame is read, as marked in resources/images/payment_report.png:
 * the lines under the column header and above the page footer.
 *
 * The table is made of payor blocks: the payor's name, and the debtor's name
 * with its code when they differ ("UNKNOWN DEBTOR" paid for "TRANSLOOP
 * LOGISTICS LLC (K33857)"), then each check ("10/13/2026 C24634964" and its
 * amount), its invoice lines and a subtotal. Every invoice line becomes a row
 * that carries its payor, debtor and check; the check amount is only on the
 * check's first row, so the column adds up to the checks. A line of text
 * under an adjustment is kept as that row's note. The last page ends with
 * the grand total and the report's collection summary ("Total Amount
 * Collected", "Applied to A/R"…). The rows must add up to the grand total,
 * so a layout the parser doesn't understand fails loudly instead of
 * producing wrong numbers.
 */
class PaymentReportParser
{
    private const DATE = '\d{1,2}\/\d{1,2}\/\d{4}';

    private const AMOUNT = '\(?-?[\d,]+\.\d{2}\)?';

    /**
     * How far, in points, the column header's labels reach down from its top
     * line; its labels are stacked up to two lines high.
     */
    private const HEADER_HEIGHT = 30;

    /**
     * Text this close vertically, in points, is on the same line.
     */
    private const LINE_TOLERANCE = 2;

    /**
     * A name line less than this far, in points, under the one before it
     * continues the same name; the payor's and the debtor's names are
     * further apart.
     */
    private const NAME_LINE_GAP = 11;

    /**
     * The table's columns, left to right, each named by a word of its label
     * on the column header's bottom line.
     */
    private const SLOTS = [
        'payor' => 'payor',
        'invoice' => 'invoice',
        'purchased' => 'purchased',
        'schedule' => 'sch',
        'invoice_amount' => 'amount',
        'days' => 'days',
        'check_amount' => 'amount',
        'payment_amount' => 'amount',
        'adjust_type' => 'type',
        'adjust_amount' => 'amount',
        'escrow_amount' => 'amount',
        'fee_earned' => 'earned',
        'taxes_held' => 'held',
    ];

    /**
     * The amount columns, in the table's order.
     */
    public const AMOUNTS = ['invoice_amount', 'check_amount', 'payment_amount', 'adjust_amount', 'escrow_amount', 'fee_earned', 'taxes_held'];

    /**
     * Amount columns the rows are checked against the grand total for.
     */
    private const CHECKED = ['check_amount', 'payment_amount', 'adjust_amount', 'escrow_amount', 'fee_earned', 'taxes_held'];

    /**
     * Columns whose numbers are right-aligned under their label.
     */
    private const RIGHT_ALIGNED = ['schedule', 'invoice_amount', 'days', 'check_amount', 'payment_amount', 'adjust_amount', 'escrow_amount', 'fee_earned', 'taxes_held'];

    /**
     * The width of one character of the table's 8 pt Helvetica, in points,
     * to find where a right-aligned number ends.
     */
    private const CHARACTER_WIDTHS = ['digit' => 4.45, ',' => 2.22, '.' => 2.22, '(' => 2.66, ')' => 2.66, '-' => 2.66];

    /**
     * parsePdf
     *
     * @param string $contents raw PDF bytes
     * @return array{factor: string|null, title: string|null, client: string|null, from: string|null, to: string|null, grand_total: array<string, float|null>, summary: array<string, float>, rows: array<int, array<string, string|int|float|null>>}
     * @throws PaymentReportException
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
     * for the report details.
     *
     * @param array<int, array<int, array{x: float, y: float, text: string}>> $pages each page's text items, y counted up from the bottom
     * @return array{factor: string|null, title: string|null, client: string|null, from: string|null, to: string|null, grand_total: array<string, float|null>, summary: array<string, float>, rows: array<int, array<string, string|int|float|null>>}
     * @throws PaymentReportException when the pages aren't a daily payment report or don't add up
     */
    public function parsePages(array $pages): array
    {
        $details = null;
        $lines = [];

        foreach (array_values($pages) as $index => $items) {
            [$pageHeader, $table, $columns] = $this->frame($items, $index + 1);
            $details ??= $this->details($pageHeader);

            foreach ($this->lines($table) as $line) {
                $lines[] = ['page' => $index, 'y' => $line[0]['y'], 'cells' => $this->cells($line, $columns)];
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
     * @throws PaymentReportException when the PDF can't be read
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
            throw new PaymentReportException('The PDF could not be read.');
        }
    }

    /**
     * frame
     *
     * Split a page into the page header above the column header, and the
     * table: the items below the column header and above the footer (printed
     * date, page number). The column header must hold the daily payment
     * report columns; where each label on its bottom line starts is where its
     * column starts.
     *
     * @param array<int, array{x: float, y: float, text: string}> $items
     * @param int $page page number, for the error message
     * @return array{0: array<int, array{x: float, y: float, text: string}>, 1: array<int, array{x: float, y: float, text: string}>, 2: array<string, float>} the page header items, the table items and each column's left edge
     * @throws PaymentReportException when the page has no daily payment report column header
     */
    private function frame(array $items, int $page): array
    {
        $labels = array_filter($items, fn (array $item) => $this->isColumnLabel($item['text']));
        $top = $labels ? max(array_column($labels, 'y')) : 0;
        $header = array_filter($labels, fn (array $item) => $item['y'] >= $top - self::HEADER_HEIGHT);
        $words = array_merge([], ...array_map(fn (array $item) => $this->words($item['text']), $header));
        $missing = array_filter($this->columns(), fn (string $column) => array_diff($this->words($column), $words) !== []);

        if (! $header || $missing) {
            throw new PaymentReportException(sprintf(
                'The table header on page %d doesn\'t have the daily payment report columns (missing: %s). The report layout may have changed.',
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
            throw new PaymentReportException("The column labels on page {$page} aren't in the expected order. The report layout may have changed.");
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
     * Put a line's texts in their columns. Text near the left edge is the
     * payor column (a name, or a check's date and number), and text that
     * starts in the Invoice# column is the invoice#, even when it's all
     * digits. Further right, a date is the purchase date, a number goes to
     * the column it ends in (numbers are right-aligned: they end after their
     * label starts and before the next label starts), and other text is the adjust type, or a "label" right of
     * the adjust amount column (the collection summary's labels).
     *
     * @param array<int, array{x: float, y: float, text: string}> $line
     * @param array<string, float> $columns each column's left edge
     * @return array<string, string>
     */
    private function cells(array $line, array $columns): array
    {
        $cells = [];
        $numbers = Arr::only($columns, self::RIGHT_ALIGNED);
        $payorPart = ($columns['payor'] + $columns['invoice']) / 2;
        $invoicePart = ($columns['invoice'] + $columns['purchased']) / 2;

        foreach ($line as $item) {
            if ($item['x'] < $payorPart) {
                $column = 'payor';
            } elseif ($item['x'] < $invoicePart) {
                $column = 'invoice';
            } elseif (preg_match('/^'.self::DATE.'$/', $item['text'])) {
                $column = 'purchased';
            } elseif (preg_match('/^('.self::AMOUNT.'|\d+)$/', $item['text'])) {
                $end = $item['x'] + $this->width($item['text']);
                $column = collect($numbers)->filter(fn (float $x) => $x < $end)->keys()->last() ?? 'schedule';
            } elseif ($item['x'] < $columns['adjust_amount']) {
                $column = 'adjust_type';
            } else {
                $column = 'label';
            }

            $cells[$column] = isset($cells[$column]) ? "{$cells[$column]} {$item['text']}" : $item['text'];
        }

        return $cells;
    }

    /**
     * rows
     *
     * Read the rows, grand total and collection summary from the table's
     * lines and check the rows against the grand total.
     *
     * @param array<int, array{page: int, y: float, cells: array<string, string>}> $lines each line's page, height and texts by column (see cells)
     * @return array{grand_total: array<string, float|null>, summary: array<string, float>, rows: array<int, array<string, string|int|float|null>>}
     * @throws PaymentReportException when there are no rows or they don't add up
     */
    private function rows(array $lines): array
    {
        $rows = [];
        $names = [];
        $previousName = null;
        $check = null;
        $grandTotal = null;
        $summary = [];

        $closeCheck = function () use (&$rows, &$check, &$names) {
            if ($check !== null && ! $check['used']) {
                $rows[] = $this->row($names, $check, []);
            }

            $check = null;
        };

        foreach ($lines as $line) {
            $cells = $line['cells'];
            $wasName = $previousName;
            $previousName = null;

            if ($grandTotal !== null) {
                $label = trim(implode(' ', Arr::except($cells, self::AMOUNTS)));
                $amount = Arr::first(Arr::only($cells, self::AMOUNTS));

                if ($label !== '' && $amount !== null) {
                    $summary[$label] = $this->number($amount);
                }
            } elseif (isset($cells['payor']) && preg_match('/^('.self::DATE.')\s+(\S.*)$/', $cells['payor'], $match)) {
                $closeCheck();
                $check = [
                    'date' => $this->date($match[1]),
                    'number' => trim($match[2]),
                    'amount' => isset($cells['check_amount']) ? $this->number($cells['check_amount']) : null,
                    'used' => false,
                ];
            } elseif (isset($cells['payor']) && count($cells) === 1) {
                $closeCheck();

                if ($wasName === null) {
                    $names = [$cells['payor']];
                } elseif ($wasName['page'] === $line['page'] && $wasName['y'] - $line['y'] < self::NAME_LINE_GAP) {
                    $names[array_key_last($names)] .= ' '.$cells['payor'];
                } else {
                    $names[] = $cells['payor'];
                }

                $previousName = $line;
            } elseif (isset($cells['invoice'])) {
                $rows[] = $this->row($names, $check, $cells);

                if ($check !== null) {
                    $check['used'] = true;
                }
            } elseif (array_keys($cells) === ['adjust_type'] && $rows) {
                $last = array_key_last($rows);
                $rows[$last]['note'] = trim(($rows[$last]['note'] ?? '').' '.$cells['adjust_type']);
            } elseif ($cells && isset($cells['check_amount']) && array_diff(array_keys($cells), self::AMOUNTS) === []) {
                $closeCheck();
                $grandTotal = $this->amounts($cells);
            }
        }

        $closeCheck();

        if (! $rows) {
            throw new PaymentReportException('No payment rows were found. This doesn\'t look like a daily payment report.');
        }

        if ($grandTotal === null) {
            throw new PaymentReportException('The report has no grand total to check the rows against.');
        }

        foreach (self::CHECKED as $column) {
            $sum = round(array_sum(array_column($rows, $column)), 2);

            if (abs($sum - ($grandTotal[$column] ?? 0)) > 0.005) {
                throw new PaymentReportException(sprintf(
                    'The %s read from the report add up to %s, but its grand total is %s. The report layout may have changed.',
                    $this->label($column),
                    number_format($sum, 2),
                    number_format($grandTotal[$column] ?? 0, 2),
                ));
            }
        }

        return [
            'grand_total' => Arr::except($grandTotal, 'invoice_amount'),
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    /**
     * row
     *
     * One payment row: the payor and debtor of its block, its check, and the
     * invoice line's columns. The check amount is only on the check's first
     * row.
     *
     * @param array<int, string> $names the payor block's names: the payor's, then the debtor's when they differ
     * @param array{date: string, number: string, amount: float|null, used: bool}|null $check
     * @param array<string, string> $cells the invoice line's texts by column; empty for a check without invoice lines
     * @return array<string, string|int|float|null>
     */
    private function row(array $names, ?array $check, array $cells): array
    {
        return [
            'payor' => $this->name($names[0] ?? null),
            'debtor' => $this->name(end($names) ?: null),
            'check_date' => $check['date'] ?? null,
            'check' => $check['number'] ?? null,
            'invoice' => $cells['invoice'] ?? null,
            'purchased' => isset($cells['purchased']) ? $this->date($cells['purchased']) : null,
            'schedule' => $cells['schedule'] ?? null,
            'days' => isset($cells['days']) ? (int) $cells['days'] : null,
            ...$this->amounts($cells),
            'check_amount' => $check !== null && ! $check['used'] ? $check['amount'] : null,
            'adjust_type' => $cells['adjust_type'] ?? null,
            'note' => null,
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
     * name
     *
     * A payor's or debtor's name, e.g. "RXO INC (MASTER)" from "RXO INC
     * (MASTER) (carrierpaperwork@rxo.com) (K41587)", without its email and
     * debtor code.
     *
     * @param string|null $name
     * @return string|null
     */
    private function name(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = preg_replace('/\s*\([^()]*\)$/', '', preg_replace('/\s+/', ' ', trim($name)));
        $name = trim(preg_replace('/\s*\([^()]*@[^()]*\)/', '', $name));

        return $name === '' ? null : $name;
    }

    /**
     * details
     *
     * The factor, title, client and period from a page header: the factor
     * and title on its first line, the client and period ("10/13/2026 thru
     * 10/13/2026") on its second.
     *
     * @param array<int, array{x: float, y: float, text: string}> $items the page header items
     * @return array{factor: string|null, title: string|null, client: string|null, from: string|null, to: string|null}
     */
    private function details(array $items): array
    {
        $lines = array_map(fn (array $line) => array_column($line, 'text'), $this->lines($items));
        $client = $lines[1][0] ?? null;
        $period = isset($lines[1][1]) ? end($lines[1]) : null;
        preg_match('/^(.+?)\s+thru\s+(.+)$/i', (string) $period, $dates);

        return [
            'factor' => $lines[0][0] ?? null,
            'title' => isset($lines[0][1]) ? end($lines[0]) : null,
            'client' => $client !== null ? trim(preg_replace(['/^Client:\s*/i', '/\s*\([^()]*\)$/'], '', $client)) : null,
            'from' => $dates ? $this->periodDate($dates[1]) : null,
            'to' => $dates ? $this->periodDate($dates[2]) : null,
        ];
    }

    /**
     * periodDate
     *
     * A period date written as "10/13/2026" or "October 13, 2026".
     *
     * @param string $value
     * @return string|null Y-m-d, or null when it is neither
     */
    private function periodDate(string $value): ?string
    {
        $value = trim($value);

        return match (true) {
            (bool) preg_match('/^'.self::DATE.'$/', $value) => $this->date($value),
            (bool) preg_match('/^[A-Za-z]+ \d{1,2}, \d{4}$/', $value) => $this->date($value, 'F j, Y'),
            default => null,
        };
    }

    /**
     * isColumnLabel
     *
     * Whether the text is part of the column header: it has a letter, and
     * every word in it is a word of a daily payment report column name, like
     * "Payor/Check#" or "Earned".
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
     * The daily payment report column names from config/report_types.php.
     *
     * @return array<int, string>
     */
    private function columns(): array
    {
        return array_map('strval', Arr::flatten(config('report_types.daily_payment_report')));
    }

    /**
     * label
     *
     * An amount column's name for messages, e.g. "Payment Amount".
     *
     * @param string $column
     * @return string
     */
    private function label(string $column): string
    {
        return $this->columns()[array_search($column, array_keys(self::SLOTS), true)] ?? $column;
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
     * How wide a number is printed, in points.
     *
     * @param string $number e.g. "(1,400.00)"
     * @return float
     */
    private function width(string $number): float
    {
        return array_sum(array_map(
            fn (string $character) => self::CHARACTER_WIDTHS[ctype_digit($character) ? 'digit' : $character] ?? self::CHARACTER_WIDTHS['digit'],
            str_split($number),
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
     * @param string $value e.g. "2,700.00", or "(1,400.00)" for a negative amount
     * @return float
     */
    private function number(string $value): float
    {
        $number = (float) str_replace([',', '(', ')'], '', $value);

        return str_starts_with(trim($value), '(') ? -$number : $number;
    }
}
