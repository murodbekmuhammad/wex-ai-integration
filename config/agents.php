<?php

use App\Services\AgingWorkbook;
use App\Services\FactoringBipolarWorkbook;
use App\Services\PaymentWorkbook;
use App\Services\ReserveWorkbook;

/*
|--------------------------------------------------------------------------
| Agents
|--------------------------------------------------------------------------
|
| The agents listed on the home page. Each one is the report agent given a
| fixed task and only the tools that task needs. Add an entry here to add an
| agent; tool names come from App\Services\AgentTools. report_type is the
| config/report_types.php key of the PDFs the agent reads, which the user
| can pick from on the Agent settings page; an agent that runs other agents'
| tools has none and uses those agents' picks. Every agent writes into one
| shared Google Sheet; workbook is the class that lists the agent's tabs
| there, and the tabs follow the order of the agents below.
|
*/

return [
    'invoice_aging' => [
        'name' => 'Invoice aging agent',
        'report_type' => 'invoice_aging',
        'workbook' => AgingWorkbook::class,
        'description' => 'Collects new PDFs from Gmail and turns the newest invoice aging report into the aging tabs of your factoring Google Sheet: dashboard, aging list, 1+ to 90+ tabs and a broker summary. Each run updates the same sheet, keeps your team’s notes and leaves the other agents’ tabs as they are.',
        'task' => 'Collect new PDFs from Gmail and update the aging tabs of the factoring Google Sheet from the newest invoice aging report.',
        'tools' => ['collect_pdfs', 'find_pdfs', 'create_aging_report'],
    ],
    'reserve_account' => [
        'name' => 'Reserve account agent',
        'report_type' => 'reserve_account_detail',
        'workbook' => ReserveWorkbook::class,
        'description' => 'Collects new PDFs from Gmail and turns the newest reserve account detail report into the RESERVE DETAIL and SUMMARY tabs of your factoring Google Sheet, after the aging tabs: every reserve transaction line and a summary of totals by type, with the opening and closing reserve balance. Each run updates the same sheet and leaves the other agents’ tabs as they are.',
        'task' => 'Collect new PDFs from Gmail and update the reserve account tabs of the factoring Google Sheet from the newest reserve account detail report.',
        'tools' => ['collect_pdfs', 'find_pdfs', 'create_reserve_report'],
    ],
    'payment_report' => [
        'name' => 'Payment report agent',
        'report_type' => 'daily_payment_report',
        'workbook' => PaymentWorkbook::class,
        'description' => 'Collects new PDFs from Gmail and turns the newest daily payment report into the PAYMENT DETAIL and PAYMENT SUMMARY tabs of your factoring Google Sheet, after the reserve account tabs: every invoice line with its payor, debtor and check, and a summary with the report’s collection totals and totals by payor and adjust type. Each run updates the same sheet and leaves the other agents’ tabs as they are.',
        'task' => 'Collect new PDFs from Gmail and update the payment report tabs of the factoring Google Sheet from the newest daily payment report.',
        'tools' => ['collect_pdfs', 'find_pdfs', 'create_payment_report'],
    ],
    'factoring_bipolar' => [
        'name' => 'Factoring-Bipolar',
        'report_type' => null,
        'workbook' => FactoringBipolarWorkbook::class,
        'description' => 'Runs the invoice aging agent and then the payment report agent, and builds the FACTORING-BIPOLAR tab of your factoring Google Sheet from their tabs, after the payment tabs: every paid invoice line from the payment report that is on the aging list, with its broker, load, invoice date and amount, the amount paid, the difference, the check date and the days from invoice to payment.',
        'task' => 'Collect new PDFs from Gmail, then one by one: update the aging tabs of the factoring Google Sheet from the newest invoice aging report, update the payment report tabs from the newest daily payment report, and then build the FACTORING-BIPOLAR tab from them. Wait for each step to finish before starting the next.',
        'tools' => ['collect_pdfs', 'find_pdfs', 'create_aging_report', 'create_payment_report', 'create_factoring_bipolar_report'],
    ],
];
