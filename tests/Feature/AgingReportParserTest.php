<?php

namespace Tests\Feature;

use App\Services\AgingReportParser;
use Tests\TestCase;

/**
 * @class AgingReportParserTest
 *
 * @package Tests\Feature
 */
class AgingReportParserTest extends TestCase
{
    /**
     * testBrokerHeaderOnOneLineIsReadWithoutCodeAndEmail
     *
     * @return void
     */
    public function test_broker_header_on_one_line_is_read_without_code_and_email(): void
    {
        $report = (new AgingReportParser)->parsePages([$this->page([
            ['ALLEN LUND COMPANY INC (billing@allenlund.com)(AL-LOSANG)'],
            ['(800)811-0083'],
            ...$this->invoice('IN-001', '1,000.00'),
            ['*', '1,000.00'],
            ['Grand', '1,000.00'],
        ])]);

        $this->assertSame('ALLEN LUND COMPANY INC', $report['invoices'][0]['broker']);
        $this->assertSame('PO-IN-001', $report['invoices'][0]['load_id']);
    }

    /**
     * testWrappedBrokerHeaderKeepsTheNameFromEveryLine
     *
     * A long header wraps so its email and code sit alone on the last line,
     * which used to leave the broker name empty.
     *
     * @return void
     */
    public function test_wrapped_broker_header_keeps_the_name_from_every_line(): void
    {
        $report = (new AgingReportParser)->parsePages([$this->page([
            ['ARRIVE LOGISTICS LLC(K20087)'],
            ['(888)861-0650'],
            ...$this->invoice('IN-001', '1,000.00'),
            ['*', '1,000.00'],
            ['COVENANT TRANSPORT SOLUTIONS LLC DBA COVENANT LOGISTICS'],
            ['(ap@covenant.com)(CTSI)'],
            ['(866)398-2884'],
            ...$this->invoice('IN-002', '2,000.00'),
            ['*', '2,000.00'],
            ['Grand', '3,000.00'],
        ])]);

        $this->assertSame(
            ['ARRIVE LOGISTICS LLC', 'COVENANT TRANSPORT SOLUTIONS LLC DBA COVENANT LOGISTICS'],
            array_column($report['invoices'], 'broker'),
        );
    }

    /**
     * page
     *
     * A report page with the column header and the given table lines below
     * it, each line's texts spread left to right.
     *
     * @param array<int, array<int, string>> $lines
     * @return array<int, array{x: float, y: float, text: string}>
     */
    private function page(array $lines): array
    {
        $items = [
            ['x' => 36.0, 'y' => 584.0, 'text' => 'WEX Capital LLC'],
            ['x' => 36.0, 'y' => 570.0, 'text' => 'Client LLC(1)'],
        ];

        foreach (['Client..', 'PO#', '1-30', '31-60', '61-90', '91-120', '121', '+'] as $index => $label) {
            $items[] = ['x' => 36.0 + $index * 60, 'y' => 527.0, 'text' => $label];
        }

        foreach (['Purchase', 'Invoice', 'Paid', 'Days'] as $index => $label) {
            $items[] = ['x' => 170.0 + $index * 60, 'y' => 518.0, 'text' => $label];
        }

        foreach (['Debtor..', 'Invoice#', 'Sch#', 'Date', 'Amount', 'Balances', 'Age'] as $index => $label) {
            $items[] = ['x' => 55.0 + $index * 60, 'y' => 510.0, 'text' => $label];
        }

        foreach ($lines as $row => $texts) {
            foreach ($texts as $column => $text) {
                $items[] = ['x' => 55.0 + $column * 60, 'y' => 480.0 - $row * 12, 'text' => $text];
            }
        }

        $items[] = ['x' => 37.0, 'y' => 9.0, 'text' => 'Printed: '];

        return $items;
    }

    /**
     * invoice
     *
     * An unpaid invoice row and the PO# line under it.
     *
     * @param string $invoice
     * @param string $amount
     * @return array<int, array<int, string>>
     */
    private function invoice(string $invoice, string $amount): array
    {
        return [
            ['S', $invoice, '7/2/2026', '2630798', $amount, $amount, '70', $amount],
            ["PO-{$invoice}"],
        ];
    }
}
