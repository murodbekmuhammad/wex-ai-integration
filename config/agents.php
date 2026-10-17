<?php

use App\Services\AgingWorkbook;
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
| can pick from on the Agent settings page. Every agent writes into one
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
];
