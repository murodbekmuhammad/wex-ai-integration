<?php

namespace App\Services;

use Exception;
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
     * Read the report's text. Every page repeats a header block (page
     * number, factor, date, title, client, column names) after its rows; it
     * is read once for the report details and otherwise skipped, so a
     * broker's invoices that continue onto the next page stay with that
     * broker.
     *
     * @param string $text
     * @return array{factor: string|null, title: string|null, client: string|null, as_of: string|null, grand_total: float, invoices: array<int, array{broker: string, invoice: string, load_id: string|null, purchase_date: string, schedule: string, amount: float, paid_date: string|null, balance: float, age: int}>}
     * @throws AgingReportException when the text isn't an aging report or doesn't add up
     */
    public function parse(string $text): array
    {
        $header = $this->header($text);
        $body = preg_replace('/Page\s+\d+\s+of\s+\d+.*?Balances/s', "\n", $text);

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
