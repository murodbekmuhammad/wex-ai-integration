<?php

/*
|--------------------------------------------------------------------------
| Agents
|--------------------------------------------------------------------------
|
| The agents listed on the home page. Each one is the report agent given a
| fixed task and only the tools that task needs. Add an entry here to add an
| agent; tool names come from App\Services\AgentTools. report_type is the
| config/report_types.php key of the PDFs the agent reads, which the user
| can pick from on the Agent settings page.
|
*/

return [
    'invoice_aging' => [
        'name' => 'Invoice aging agent',
        'report_type' => 'invoice_aging',
        'description' => 'Collects new PDFs from Gmail and turns the newest invoice aging report into your factoring Google Sheet: dashboard, aging list, 1+ to 90+ tabs and a broker summary. Each run updates the same sheet and keeps your team’s notes.',
        'task' => 'Collect new PDFs from Gmail and create the aging report Google Sheet from the newest invoice aging report.',
        'tools' => ['collect_pdfs', 'find_pdfs', 'create_aging_report'],
    ],
    'reserve_account' => [
        'name' => 'Reserve account agent',
        'report_type' => 'reserve_account_detail',
        'description' => 'Collects new PDFs from Gmail and turns the newest reserve account detail report into a Google Sheet: every reserve transaction line and a summary of totals by type, with the opening and closing reserve balance. Each run updates the same sheet.',
        'task' => 'Collect new PDFs from Gmail and create the reserve account Google Sheet from the newest reserve account detail report.',
        'tools' => ['collect_pdfs', 'find_pdfs', 'create_reserve_report'],
    ],
];
