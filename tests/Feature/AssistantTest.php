<?php

namespace Tests\Feature;

use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use App\Models\Email;
use App\Models\PdfDocument;
use App\Models\User;
use App\Services\EmailAssistant;
use App\Services\PdfAnalyst;
use Generator;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

/**
 * @class AssistantTest
 *
 * @package Tests\Feature
 */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fake assistant bound in setUp; records what it was asked.
     */
    private object $fake;

    /**
     * The fake PDF analyst bound in setUp; records what it was asked.
     */
    private object $fakeAnalyst;

    /**
     * setUp
     *
     * Replace the real Claude client with a fake that streams a canned answer.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.key' => 'test-key']);

        $this->fakeAnalyst = new class extends PdfAnalyst
        {
            public ?Collection $documents = null;

            public ?string $question = null;

            /**
             * analyze
             *
             * @param Collection $documents
             * @param string $question
             * @return Generator<int, string>
             */
            public function analyze(Collection $documents, string $question): Generator
            {
                $this->documents = $documents;
                $this->question = $question;

                yield 'The invoices ';
                yield 'total $900.';
            }
        };

        $this->app->instance(PdfAnalyst::class, $this->fakeAnalyst);

        $this->fake = new class extends EmailAssistant
        {
            public ?Collection $emails = null;

            public ?string $question = null;

            /**
             * Thrown instead of answering, to simulate an API failure.
             */
            public ?Throwable $error = null;

            /**
             * ask
             *
             * @param Collection $emails
             * @param string $question
             * @return Generator<int, string>
             * @throws Throwable
             */
            public function ask(Collection $emails, string $question): Generator
            {
                $this->emails = $emails;
                $this->question = $question;

                if ($this->error) {
                    throw $this->error;
                }

                yield 'You have ';
                yield 'two invoices.';
            }
        };

        $this->app->instance(EmailAssistant::class, $this->fake);
    }

    /**
     * testAnswerIsStreamedBack
     *
     * @return void
     */
    public function test_answer_is_streamed_back(): void
    {
        $user = User::factory()->create();
        Email::factory()->count(3)->for($user)->create();

        $response = $this->actingAs($user)->postJson('/ask', ['question' => 'Any invoices?']);

        $response->assertOk();
        $this->assertSame('You have two invoices.', $response->streamedContent());
        $this->assertSame('Any invoices?', $this->fake->question);
        $this->assertCount(3, $this->fake->emails);
    }

    /**
     * testOnlyEmailsForTheSelectedReceiverAreSent
     *
     * @return void
     */
    public function test_only_emails_for_the_selected_receiver_are_sent(): void
    {
        $user = User::factory()->create();
        Email::factory()->count(2)->for($user)->create(['receivers' => ['team@example.com']]);
        Email::factory()->count(4)->for($user)->create(['receivers' => ['me@example.com']]);
        Email::factory()->count(5)->create(); // someone else's mail

        $this->actingAs($user)
            ->postJson('/ask', ['question' => 'Summarize', 'receiver' => 'team@example.com'])
            ->streamedContent();

        $this->assertCount(2, $this->fake->emails);
        $this->assertTrue($this->fake->emails->every(fn (Email $e) => $e->user_id === $user->id));
    }

    /**
     * testOnlyEmailsFromTheSelectedSendersAreSent
     *
     * @return void
     */
    public function test_only_emails_from_the_selected_senders_are_sent(): void
    {
        $user = User::factory()->create();
        Email::factory()->count(2)->for($user)->create(['sender_email' => 'billing@acme.test']);
        Email::factory()->for($user)->create(['sender_email' => 'ops@acme.test']);
        Email::factory()->count(4)->for($user)->create(['sender_email' => 'news@other.test']);

        $this->actingAs($user)
            ->postJson('/ask', ['question' => 'Summarize', 'senders' => ['billing@acme.test', 'ops@acme.test']])
            ->streamedContent();

        $this->assertCount(3, $this->fake->emails);
    }

    /**
     * testPdfAnalysisIsStreamedBack
     *
     * @return void
     */
    public function test_pdf_analysis_is_streamed_back(): void
    {
        $user = User::factory()->create();
        PdfDocument::factory()->count(2)->for($user)->create();

        $response = $this->actingAs($user)->postJson('/analyze', ['question' => 'What do these total?']);

        $response->assertOk();
        $this->assertSame('The invoices total $900.', $response->streamedContent());
        $this->assertSame('What do these total?', $this->fakeAnalyst->question);
        $this->assertCount(2, $this->fakeAnalyst->documents);
    }

    /**
     * testOnlyPdfsFromTheSelectedSendersAreAnalyzed
     *
     * @return void
     */
    public function test_only_pdfs_from_the_selected_senders_are_analyzed(): void
    {
        $user = User::factory()->create();
        PdfDocument::factory()->count(2)->for($user)->create(['sender_email' => 'billing@acme.test']);
        PdfDocument::factory()->count(3)->for($user)->create(['sender_email' => 'ops@acme.test']);
        PdfDocument::factory()->count(4)->create(); // someone else's mailbox

        $this->actingAs($user)
            ->postJson('/analyze', ['question' => 'Totals?', 'senders' => ['billing@acme.test']])
            ->streamedContent();

        $this->assertCount(2, $this->fakeAnalyst->documents);
        $this->assertTrue($this->fakeAnalyst->documents->every(fn (PdfDocument $d) => $d->user_id === $user->id));
    }

    /**
     * testOnlyPdfsWithinTheDateRangeAreAnalyzed
     *
     * @return void
     */
    public function test_only_pdfs_within_the_date_range_are_analyzed(): void
    {
        $user = User::factory()->create();
        PdfDocument::factory()->count(2)->for($user)->create(['sent_at' => '2026-08-10 09:00:00']);
        PdfDocument::factory()->count(3)->for($user)->create(['sent_at' => '2026-07-10 09:00:00']);

        $this->actingAs($user)
            ->postJson('/analyze', ['question' => 'Totals?', 'from' => '2026-08-01', 'to' => '2026-08-31'])
            ->streamedContent();

        $this->assertCount(2, $this->fakeAnalyst->documents);
    }

    /**
     * testPdfAnalysisGetsClaudesTimeLimit
     *
     * PHP's default 30 seconds cut answers off mid-stream.
     *
     * @return void
     */
    public function test_pdf_analysis_gets_claudes_time_limit(): void
    {
        config(['services.anthropic.time_limit' => 123]);
        $user = User::factory()->create();
        PdfDocument::factory()->for($user)->create();

        try {
            $this->actingAs($user)->postJson('/analyze', ['question' => 'Totals?'])->streamedContent();

            $this->assertSame('123', ini_get('max_execution_time'));
        } finally {
            set_time_limit(0);
        }
    }

    /**
     * testAnalyzeNeedsCollectedPdfs
     *
     * @return void
     */
    public function test_analyze_needs_collected_pdfs(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/analyze', ['question' => 'Totals?'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Collect some PDFs first, then ask about them.');
    }

    /**
     * testAnalyzeSendsAtMostMaxDocuments
     *
     * @return void
     */
    public function test_analyze_sends_at_most_max_documents(): void
    {
        $user = User::factory()->create();
        PdfDocument::factory()->count(PdfAnalyst::MAX_DOCUMENTS + 3)->for($user)->create();

        $this->actingAs($user)->postJson('/analyze', ['question' => 'Totals?'])->streamedContent();

        $this->assertCount(PdfAnalyst::MAX_DOCUMENTS, $this->fakeAnalyst->documents);
    }

    /**
     * testGuestsCannotAnalyze
     *
     * @return void
     */
    public function test_guests_cannot_analyze(): void
    {
        $this->postJson('/analyze', ['question' => 'Totals?'])->assertUnauthorized();
    }

    /**
     * testQuestionIsRequired
     *
     * @return void
     */
    public function test_question_is_required(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/ask', ['question' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('question');
    }

    /**
     * testKeyWithoutWorkspaceGivesAClearError
     *
     * @return void
     */
    public function test_key_without_workspace_gives_a_clear_error(): void
    {
        $this->fake->error = new BadRequestException(
            new Request('POST', 'https://api.anthropic.com/v1/messages'),
            new Response(400, [], json_encode(['type' => 'error', 'error' => [
                'type' => 'invalid_request_error',
                'message' => 'This API key is not scoped to a workspace, so this request must include the anthropic-workspace-id header with the ID of the workspace to use.',
            ]])),
        );
        $user = User::factory()->create();
        Email::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson('/ask', ['question' => 'Hi']);

        $response->assertOk();
        $this->assertStringContainsString('Set ANTHROPIC_WORKSPACE_ID in .env', $response->streamedContent());
    }

    /**
     * testUnknownWorkspaceGivesAClearError
     *
     * @return void
     */
    public function test_unknown_workspace_gives_a_clear_error(): void
    {
        $this->fake->error = new NotFoundException(
            new Request('POST', 'https://api.anthropic.com/v1/messages'),
            new Response(404, [], json_encode(['type' => 'error', 'error' => [
                'type' => 'not_found_error',
                'message' => 'Workspace `19b09ab1-29c2-4ec2-943d-9e58bab1ff1a` not found.',
            ]])),
        );
        $user = User::factory()->create();
        Email::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson('/ask', ['question' => 'Hi']);

        $response->assertOk();
        $this->assertStringContainsString("ANTHROPIC_WORKSPACE_ID doesn't match a workspace", $response->streamedContent());
    }

    /**
     * testOtherApiErrorsShowOnlyTheirMessage
     *
     * @return void
     */
    public function test_other_api_errors_show_only_their_message(): void
    {
        $this->fake->error = new BadRequestException(
            new Request('POST', 'https://api.anthropic.com/v1/messages'),
            new Response(400, [], json_encode(['type' => 'error', 'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Your credit balance is too low to access the Anthropic API.',
            ]])),
        );
        $user = User::factory()->create();
        Email::factory()->for($user)->create();

        $content = $this->actingAs($user)->postJson('/ask', ['question' => 'Hi'])->streamedContent();

        $this->assertSame("\n\n[Claude returned an error: Your credit balance is too low to access the Anthropic API.]", $content);
    }

    /**
     * testMissingApiKeyGivesAClearError
     *
     * @return void
     */
    public function test_missing_api_key_gives_a_clear_error(): void
    {
        config(['services.anthropic.key' => null]);
        $user = User::factory()->create();
        Email::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/ask', ['question' => 'Hi'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Claude is not set up yet: add ANTHROPIC_API_KEY to .env.');
    }

    /**
     * testGuestsCannotAsk
     *
     * @return void
     */
    public function test_guests_cannot_ask(): void
    {
        $this->postJson('/ask', ['question' => 'Hi'])->assertUnauthorized();
    }

    /**
     * testContextListsEachEmail
     *
     * @return void
     */
    public function test_context_lists_each_email(): void
    {
        $emails = Email::factory()->count(2)->make([
            'sender' => 'Bob <bob@example.com>',
            'receivers' => ['me@example.com', 'team@example.com'],
            'subject' => 'Invoice #42',
            'snippet' => 'Please pay by Friday',
        ]);

        $context = (new EmailAssistant())->context($emails);

        $this->assertStringStartsWith('<emails count="2">', $context);
        $this->assertSame(2, substr_count($context, '<email>'));
        $this->assertStringContainsString('From: Bob <bob@example.com>', $context);
        $this->assertStringContainsString('To: me@example.com, team@example.com', $context);
        $this->assertStringContainsString('Subject: Invoice #42', $context);
        $this->assertStringContainsString('Snippet: Please pay by Friday', $context);
    }

    /**
     * testDocumentBlocksAttachEachStoredPdf
     *
     * Files missing from disk are skipped, and only the last block is cached.
     *
     * @return void
     */
    public function test_document_blocks_attach_each_stored_pdf(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('pdfs/1/a.pdf', '%PDF-invoice-a');
        Storage::disk('local')->put('pdfs/1/b.pdf', '%PDF-invoice-b');

        $documents = collect([
            PdfDocument::factory()->make(['path' => 'pdfs/1/a.pdf', 'filename' => 'a.pdf', 'sender' => 'Bob <bob@example.com>', 'subject' => 'March invoice']),
            PdfDocument::factory()->make(['path' => 'pdfs/1/gone.pdf']),
            PdfDocument::factory()->make(['path' => 'pdfs/1/b.pdf', 'filename' => 'b.pdf']),
        ]);

        $blocks = (new PdfAnalyst())->documentBlocks($documents);

        $this->assertCount(2, $blocks);
        $this->assertSame(['a.pdf', 'b.pdf'], array_column($blocks, 'title'));
        $this->assertSame(base64_encode('%PDF-invoice-a'), $blocks[0]['source']['data']);
        $this->assertSame('application/pdf', $blocks[0]['source']['mediaType']);
        $this->assertStringContainsString('Emailed by Bob <bob@example.com>', $blocks[0]['context']);
        $this->assertStringContainsString('Subject: March invoice', $blocks[0]['context']);
        $this->assertArrayNotHasKey('cacheControl', $blocks[0]);
        $this->assertSame(['type' => 'ephemeral'], $blocks[1]['cacheControl']);
    }
}
