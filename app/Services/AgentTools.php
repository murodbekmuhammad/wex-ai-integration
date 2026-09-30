<?php

namespace App\Services;

use Anthropic\Core\Exceptions\APIException;
use App\Mail\TableSheetLink;
use App\Models\PdfDocument;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * @class AgentTools
 *
 * @package App\Services
 *
 * The actions the report agent can take on the user's behalf: collecting
 * PDFs from Gmail, finding collected PDFs, building a table or the aging
 * report workbook from them, uploading to Google Sheets and emailing the link. Every lookup is scoped to the user, and email
 * only goes to the user's own sign-in address, never one Claude picks.
 */
class AgentTools
{
    /**
     * The most PDFs one find_pdfs call lists.
     */
    public const MAX_LISTED = 50;

    /**
     * How many days back collect_pdfs looks when Claude doesn't say.
     */
    public const DEFAULT_COLLECT_DAYS = 7;

    /**
     * __construct
     *
     * @param TableBuilder $builder
     * @param GoogleSheets $sheets
     * @param PdfCollector $collector
     * @param PdfClassifier $classifier
     * @param AgingReportParser $parser
     * @param AgingWorkbook $workbook
     */
    public function __construct(
        private TableBuilder $builder,
        private GoogleSheets $sheets,
        private PdfCollector $collector,
        private PdfClassifier $classifier,
        private AgingReportParser $parser,
        private AgingWorkbook $workbook,
    ) {}

    /**
     * definitions
     *
     * The tools as Claude sees them. email_sheet_link is only offered when
     * agent email is turned on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_values(array_filter(
            $this->allDefinitions(),
            fn (array $tool) => $tool['name'] !== 'email_sheet_link' || self::canEmail(),
        ));
    }

    /**
     * canEmail
     *
     * Whether the agent may email report links (AGENT_SEND_EMAIL).
     *
     * @return bool
     */
    public static function canEmail(): bool
    {
        return (bool) config('services.anthropic.agent_email');
    }

    /**
     * allDefinitions
     *
     * @return array<int, array<string, mixed>>
     */
    private function allDefinitions(): array
    {
        return [
            [
                'name' => 'collect_pdfs',
                'description' => 'Fetch new PDF attachments from the user\'s Gmail for the last few days into the app and tag their report types. Returns how many new PDFs were collected.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'days' => ['type' => 'integer', 'description' => 'How many days back to look, 1 to 31. Defaults to '.self::DEFAULT_COLLECT_DAYS.'.'],
                    ],
                ],
            ],
            [
                'name' => 'create_aging_report',
                'description' => 'Create the factoring aging report as a new Google Sheet from an invoice aging PDF: DASH BOARD, AGING, Aging 1+/30+/45+/60+/90+ and a per-broker "data" tab. The PDF is read by code and checked against its grand total, so the figures are exact. Uses the newest invoice aging PDF unless a document_id is given. Returns the table id to email, the sheet link and the headline figures.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'document_id' => ['type' => 'integer', 'description' => 'An invoice aging PDF id from find_pdfs; omit for the newest.'],
                    ],
                ],
            ],
            [
                'name' => 'find_pdfs',
                'description' => 'List the PDFs already collected from the user\'s mailbox that were emailed between two dates, newest first, optionally only one report type or only certain senders. Returns each PDF\'s id, filename, sender, subject, date and report type (null when unknown), up to '.self::MAX_LISTED.' PDFs.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD.'],
                        'to' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD.'],
                        'report_type' => ['anyOf' => [['type' => 'string', 'enum' => array_keys(config('report_types'))], ['type' => 'null']]],
                        'senders' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Sender email addresses; empty means any sender.'],
                    ],
                    'required' => ['from', 'to'],
                ],
            ],
            [
                'name' => 'build_table',
                'description' => 'Read the given PDFs in full and build one table from them as the instructions describe, including any calculations such as totals. The table is saved for the user. Returns its id, title, summary, columns, row count, last row and any warnings about totals that don\'t add up.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'document_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Ids from find_pdfs, at most '.PdfAnalyst::MAX_DOCUMENTS.'.'],
                        'instructions' => ['type' => 'string', 'description' => 'What the table should show: which data, which columns, which calculations.'],
                    ],
                    'required' => ['document_ids', 'instructions'],
                ],
            ],
            [
                'name' => 'upload_to_google_sheets',
                'description' => 'Upload a table built with build_table to the user\'s Google Drive as a Google Sheet. Returns the sheet\'s link.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'table_id' => ['type' => 'integer'],
                    ],
                    'required' => ['table_id'],
                ],
            ],
            [
                'name' => 'email_sheet_link',
                'description' => 'Email the Google Sheet link of a table or aging report to the user\'s own email address, with a short note. A table from build_table must be uploaded with upload_to_google_sheets first.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'table_id' => ['type' => 'integer'],
                        'note' => ['type' => 'string', 'description' => 'A few plain-text sentences about what the table shows.'],
                    ],
                    'required' => ['table_id', 'note'],
                ],
            ],
        ];
    }

    /**
     * label
     *
     * What the user sees while a tool runs.
     *
     * @param string $name
     * @return string
     */
    public function label(string $name): string
    {
        return match ($name) {
            'collect_pdfs' => 'Collecting new PDFs from Gmail…',
            'create_aging_report' => 'Reading the aging report and creating the Google Sheet…',
            'find_pdfs' => 'Finding PDFs…',
            'build_table' => 'Reading the PDFs and building the table…',
            'upload_to_google_sheets' => 'Uploading to Google Sheets…',
            'email_sheet_link' => 'Emailing the link…',
            default => "Running {$name}…",
        };
    }

    /**
     * run
     *
     * Run one tool. Failures are returned as errors for Claude to read and
     * work around, not thrown.
     *
     * @param User $user
     * @param string $name
     * @param array<string, mixed> $input
     * @return array{content: string, is_error: bool, progress: string} content goes back to Claude, progress to the user
     */
    public function run(User $user, string $name, array $input): array
    {
        try {
            return match ($name) {
                'collect_pdfs' => $this->collectPdfs($user, $input),
                'create_aging_report' => $this->createAgingReport($user, $input),
                'find_pdfs' => $this->findPdfs($user, $input),
                'build_table' => $this->buildTable($user, $input),
                'upload_to_google_sheets' => $this->uploadToGoogleSheets($user, $input),
                'email_sheet_link' => self::canEmail()
                    ? $this->emailSheetLink($user, $input)
                    : $this->error('Emailing is turned off. The sheet link is shown to the user on screen.'),
                default => $this->error("There is no tool called {$name}."),
            };
        } catch (AgentToolException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * collectPdfs
     *
     * Fetch the last few days' PDFs from Gmail, then tag the ones that
     * haven't been checked yet with their report type.
     *
     * @param User $user
     * @param array<string, mixed> $input
     * @return array{content: string, is_error: bool, progress: string}
     * @throws AgentToolException
     */
    private function collectPdfs(User $user, array $input): array
    {
        $input = $this->validate($input, ['days' => ['nullable', 'integer', 'min:1', 'max:31']]);
        $from = now()->subDays($input['days'] ?? self::DEFAULT_COLLECT_DAYS)->startOfDay();
        $until = now();

        try {
            $collected = $this->collector->collect($user, [], $from, $until, config('services.google.pdf_scan_limit'));
        } catch (AuthenticationException $e) {
            throw new AgentToolException($e->getMessage());
        } catch (RequestException $e) {
            report($e);

            throw new AgentToolException('Gmail could not be reached right now.');
        }

        $pending = $user->pdfDocuments()
            ->whereBetween('sent_at', [$from, $until])
            ->whereNull('classified_at')
            ->get();

        $this->classifier->classifyPending($pending);

        return [
            'content' => $this->json(['collected' => $collected, 'from' => $from->toDateString(), 'to' => $until->toDateString()]),
            'is_error' => false,
            'progress' => "Collected {$collected} new PDF(s).",
        ];
    }

    /**
     * createAgingReport
     *
     * Read an invoice aging PDF in code, build the factoring workbook from
     * it, upload it as a new Google Sheet and save it with the user's tables.
     *
     * @param User $user
     * @param array<string, mixed> $input
     * @return array{content: string, is_error: bool, progress: string}
     * @throws AgentToolException
     */
    private function createAgingReport(User $user, array $input): array
    {
        $input = $this->validate($input, ['document_id' => ['nullable', 'integer']]);

        $document = $user->pdfDocuments()
            ->where('report_type', 'invoice_aging')
            ->when($input['document_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first() ?? throw new AgentToolException('No invoice aging PDF was found. Collect PDFs first, or pass the id of an invoice aging PDF.');

        $contents = $document->contents() ?? throw new AgentToolException("The file {$document->filename} is missing from storage.");

        try {
            $report = $this->parser->parsePdf($contents);
        } catch (AgingReportException $e) {
            throw new AgentToolException("{$document->filename}: {$e->getMessage()}");
        }

        $asOf = $report['as_of'] ? now()->parse($report['as_of'])->format('M j, Y') : $document->sent_at->format('M j, Y');
        $title = trim("Aging report {$report['client']}").", as of {$asOf}";
        $summary = $this->workbook->summary($report);

        try {
            $sheet = $this->sheets->upload($user, $title, $this->workbook->build($report));
        } catch (AuthenticationException|AuthorizationException $e) {
            throw new AgentToolException($e->getMessage());
        } catch (RequestException $e) {
            report($e);

            throw new AgentToolException('Google Drive could not take the file right now.');
        }

        $table = $user->reportTables()->create([
            'title' => $title,
            'request' => "Aging report from {$document->filename}",
            'summary' => sprintf('%d invoices from %d brokers, open balance $%s.', $summary['invoices'], $summary['brokers'], number_format($summary['balance'], 2)),
            'columns' => ['Broker', 'Invoice', 'Load ID', 'Invoice date', 'Age', 'Invoice $', 'Paid $', 'Balance $', 'Payment date'],
            'rows' => collect($report['invoices'])->map(fn (array $invoice) => [
                $invoice['broker'], $invoice['invoice'], $invoice['load_id'], $invoice['purchase_date'], $invoice['age'],
                $invoice['amount'], round($invoice['amount'] - $invoice['balance'], 2), $invoice['balance'], $invoice['paid_date'],
            ])->all(),
            'warnings' => [],
            'pdf_document_ids' => [$document->id],
            'google_sheet_id' => $sheet['id'],
            'google_sheet_url' => $sheet['url'],
        ]);

        return [
            'content' => $this->json([
                'table_id' => $table->id,
                'google_sheet_url' => $sheet['url'],
                'source' => ['filename' => $document->filename, 'factor' => $report['factor'], 'report' => $report['title'], 'as_of' => $report['as_of']],
                ...$summary,
            ]),
            'is_error' => false,
            'progress' => sprintf('Created “%s” (%d invoices, $%s open): %s', $title, $summary['invoices'], number_format($summary['balance'], 2), $sheet['url']),
        ];
    }

    /**
     * findPdfs
     *
     * @param User $user
     * @param array<string, mixed> $input
     * @return array{content: string, is_error: bool, progress: string}
     * @throws AgentToolException
     */
    private function findPdfs(User $user, array $input): array
    {
        $input = $this->validate($input, [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'report_type' => ['nullable', 'string', Rule::in(array_keys(config('report_types')))],
            'senders' => ['nullable', 'array'],
            'senders.*' => ['string', 'max:255'],
        ]);

        $documents = $user->pdfDocuments()
            ->when($input['senders'] ?? null, fn ($query, $senders) => $query->whereIn('sender_email', $senders))
            ->when($input['report_type'] ?? null, fn ($query, $type) => $query->where('report_type', $type))
            ->whereBetween('sent_at', [now()->parse($input['from'])->startOfDay(), now()->parse($input['to'])->endOfDay()])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::MAX_LISTED)
            ->get();

        $list = $documents->map(fn (PdfDocument $document) => [
            'id' => $document->id,
            'filename' => $document->filename,
            'sender' => $document->sender_email,
            'subject' => $document->subject,
            'sent_at' => $document->sent_at->toDateTimeString(),
            'report_type' => $document->report_type,
        ]);

        return [
            'content' => $this->json(['count' => $list->count(), 'documents' => $list->all()]),
            'is_error' => false,
            'progress' => "Found {$list->count()} PDF(s).",
        ];
    }

    /**
     * buildTable
     *
     * @param User $user
     * @param array<string, mixed> $input
     * @return array{content: string, is_error: bool, progress: string}
     * @throws AgentToolException
     */
    private function buildTable(User $user, array $input): array
    {
        $input = $this->validate($input, [
            'document_ids' => ['required', 'array', 'min:1', 'max:'.PdfAnalyst::MAX_DOCUMENTS],
            'document_ids.*' => ['integer'],
            'instructions' => ['required', 'string', 'max:4000'],
        ]);

        $documents = $user->pdfDocuments()
            ->whereIn('id', $input['document_ids'])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get();

        if ($documents->isEmpty()) {
            throw new AgentToolException('None of these PDF ids belong to the user. Use ids from find_pdfs.');
        }

        try {
            $built = $this->builder->build($documents, $input['instructions']);
        } catch (TableBuildException $e) {
            throw new AgentToolException($e->getMessage());
        } catch (APIException $e) {
            if (ClaudeErrors::shouldReport($e)) {
                report($e);
            }

            throw new AgentToolException(ClaudeErrors::describe($e));
        }

        $table = $user->reportTables()->create([
            ...$built,
            'request' => $input['instructions'],
            'pdf_document_ids' => $documents->pluck('id')->all(),
        ]);

        return [
            'content' => $this->json([
                'table_id' => $table->id,
                'title' => $table->title,
                'summary' => $table->summary,
                'columns' => $table->columns,
                'row_count' => count($table->rows),
                'last_row' => end($built['rows']),
                'warnings' => $table->warnings,
            ]),
            'is_error' => false,
            'progress' => sprintf('Built “%s” with %d row(s)%s.', $table->title, count($table->rows), $table->warnings ? ', but its totals need checking' : ''),
        ];
    }

    /**
     * uploadToGoogleSheets
     *
     * @param User $user
     * @param array<string, mixed> $input
     * @return array{content: string, is_error: bool, progress: string}
     * @throws AgentToolException
     */
    private function uploadToGoogleSheets(User $user, array $input): array
    {
        $input = $this->validate($input, ['table_id' => ['required', 'integer']]);
        $table = $user->reportTables()->find($input['table_id']) ?? throw new AgentToolException('There is no table with this id.');

        try {
            $url = $this->sheets->publish($user, $table);
        } catch (AuthenticationException|AuthorizationException $e) {
            throw new AgentToolException($e->getMessage());
        } catch (RequestException $e) {
            report($e);

            throw new AgentToolException('Google Drive could not take the file right now.');
        }

        return [
            'content' => $this->json(['google_sheet_url' => $url]),
            'is_error' => false,
            'progress' => "Uploaded: {$url}",
        ];
    }

    /**
     * emailSheetLink
     *
     * Email the link to the user's own sign-in address.
     *
     * @param User $user
     * @param array<string, mixed> $input
     * @return array{content: string, is_error: bool, progress: string}
     * @throws AgentToolException
     */
    private function emailSheetLink(User $user, array $input): array
    {
        $input = $this->validate($input, [
            'table_id' => ['required', 'integer'],
            'note' => ['required', 'string', 'max:4000'],
        ]);
        $table = $user->reportTables()->find($input['table_id']) ?? throw new AgentToolException('There is no table with this id.');

        if (! $table->google_sheet_url) {
            throw new AgentToolException('This table isn\'t in Google Sheets yet. Call upload_to_google_sheets first.');
        }

        try {
            Mail::to($user->email)->send(new TableSheetLink($table, $input['note']));
        } catch (TransportExceptionInterface $e) {
            report($e);

            throw new AgentToolException('The email could not be sent. The mail server rejected it or could not be reached.');
        }

        return [
            'content' => "Sent to {$user->email}.",
            'is_error' => false,
            'progress' => "Sent to {$user->email}.",
        ];
    }

    /**
     * validate
     *
     * Check Claude's tool input against the rules.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $rules
     * @return array<string, mixed> the validated input
     * @throws AgentToolException when the input breaks a rule
     */
    private function validate(array $input, array $rules): array
    {
        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            throw new AgentToolException('Invalid input: '.implode(' ', $validator->errors()->all()));
        }

        return $validator->validated();
    }

    /**
     * error
     *
     * @param string $message
     * @return array{content: string, is_error: bool, progress: string}
     */
    private function error(string $message): array
    {
        return ['content' => $message, 'is_error' => true, 'progress' => $message];
    }

    /**
     * json
     *
     * @param array<string, mixed> $data
     * @return string
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
