<?php

/*
|--------------------------------------------------------------------------
| Agents
|--------------------------------------------------------------------------
|
| The agents listed on the home page. Each one is the report agent given a
| fixed task and only the tools that task needs. Add an entry here to add an
| agent; tool names come from App\Services\AgentTools.
|
*/

return [
    'invoice_aging' => [
        'name' => 'Invoice aging agent',
        'description' => 'Collects new PDFs from Gmail and turns the newest invoice aging report into your factoring Google Sheet: dashboard, aging list, 1+ to 90+ tabs and a broker summary. Each run updates the same sheet and keeps your team’s notes.',
        'task' => 'Collect new PDFs from Gmail and create the aging report Google Sheet from the newest invoice aging report.',
        'tools' => ['collect_pdfs', 'find_pdfs', 'create_aging_report'],
    ],
];
