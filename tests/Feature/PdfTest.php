<?php

namespace Tests\Feature;

use App\Models\PdfDocument;
use App\Models\User;
use App\Services\PdfCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * @class PdfTest
 *
 * @package Tests\Feature
 */
class PdfTest extends TestCase
{
    use RefreshDatabase;

    /**
     * setUp
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
    }

    /**
     * fakeGmailWithPdf
     *
     * Fake a Gmail search that returns one message carrying one PDF.
     *
     * @param string $bytes
     * @param int|null $reportedSize the size Gmail claims the attachment has
     * @return void
     */
    private function fakeGmailWithPdf(string $bytes = '%PDF-1.4 invoice', ?int $reportedSize = null): void
    {
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([
                'messages' => [['id' => 'm1']],
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1/attachments/*' => Http::response([
                'data' => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='),
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1*' => Http::response([
                'id' => 'm1',
                'internalDate' => '1767225600000',
                'payload' => [
                    'mimeType' => 'multipart/mixed',
                    'headers' => [
                        ['name' => 'From', 'value' => 'Acme Billing <billing@acme.test>'],
                        ['name' => 'Subject', 'value' => 'Invoice #42'],
                    ],
                    'parts' => [
                        ['partId' => '0', 'mimeType' => 'text/plain', 'filename' => '', 'body' => ['data' => 'aGk']],
                        ['partId' => '1', 'mimeType' => 'application/pdf', 'filename' => 'invoice-42.pdf', 'body' => [
                            'attachmentId' => 'att-1',
                            'size' => $reportedSize ?? strlen($bytes),
                        ]],
                    ],
                ],
            ]),
        ]);
    }

    /**
     * testCollectStoresPdfAttachments
     *
     * @return void
     */
    public function test_collect_stores_pdf_attachments(): void
    {
        $user = User::factory()->create();
        $this->fakeGmailWithPdf();

        $this->actingAs($user)
            ->postJson('/pdfs/collect', ['senders' => ['billing@acme.test']])
            ->assertOk()
            ->assertJson(['collected' => 1]);

        $document = $user->pdfDocuments()->sole();
        $this->assertSame('invoice-42.pdf', $document->filename);
        $this->assertSame('billing@acme.test', $document->sender_email);
        $this->assertSame('Invoice #42', $document->subject);
        $this->assertSame('1', $document->part_id);
        $this->assertSame(16, $document->size);
        $this->assertSame('2026-01-01', $document->sent_at->toDateString());
        Storage::disk()->assertExists($document->path);
        $this->assertSame('%PDF-1.4 invoice', $document->contents());
    }

    /**
     * testCollectOnlySearchesTheFilteredSenders
     *
     * @return void
     */
    public function test_collect_only_searches_the_filtered_senders_and_dates(): void
    {
        $this->fakeGmailWithPdf();

        $this->actingAs(User::factory()->create())
            ->postJson('/pdfs/collect', [
                'senders' => ['billing@acme.test', 'ops@acme.test'],
                'from' => '2026-08-01',
                'to' => '2026-08-31',
            ])
            ->assertOk();

        $after = Carbon::parse('2026-08-01 00:00:00')->getTimestamp() - 1;
        $before = Carbon::parse('2026-09-01 00:00:00')->getTimestamp();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages?')
            && $request['q'] === "from:(billing@acme.test OR ops@acme.test) has:attachment filename:pdf -in:drafts after:{$after} before:{$before}");
    }

    /**
     * testCollectDefaultsToLastWeekFromEverySender
     *
     * @return void
     */
    public function test_collect_defaults_to_last_week_from_every_sender(): void
    {
        $this->travelTo(Carbon::parse('2026-09-11 15:30:00'));
        $this->fakeGmailWithPdf();

        $this->actingAs(User::factory()->create())->postJson('/pdfs/collect')->assertOk();

        $after = Carbon::parse('2026-09-04 00:00:00')->getTimestamp() - 1;
        $before = Carbon::parse('2026-09-12 00:00:00')->getTimestamp();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages?')
            && $request['q'] === "has:attachment filename:pdf -in:drafts after:{$after} before:{$before}");
    }

    /**
     * testCollectRejectsAnEndDateBeforeTheStart
     *
     * @return void
     */
    public function test_collect_rejects_an_end_date_before_the_start(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->postJson('/pdfs/collect', ['from' => '2026-09-10', 'to' => '2026-09-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        Http::assertNothingSent();
    }

    /**
     * testCollectSkipsMessagesItAlreadyStored
     *
     * @return void
     */
    public function test_collect_skips_messages_it_already_stored(): void
    {
        $user = User::factory()->create();
        PdfDocument::factory()->for($user)->create(['gmail_id' => 'm1']);
        $this->fakeGmailWithPdf();

        $this->actingAs($user)->postJson('/pdfs/collect')->assertOk()->assertJson(['collected' => 0]);

        $this->assertSame(1, $user->pdfDocuments()->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/messages/m1'));
    }

    /**
     * testCollectSkipsFilesTooLargeForClaude
     *
     * @return void
     */
    public function test_collect_skips_files_too_large_for_claude(): void
    {
        $user = User::factory()->create();
        $this->fakeGmailWithPdf(reportedSize: PdfCollector::MAX_FILE_SIZE + 1);

        $this->actingAs($user)->postJson('/pdfs/collect')->assertOk()->assertJson(['collected' => 0]);

        $this->assertSame(0, $user->pdfDocuments()->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/attachments/'));
    }

    /**
     * testIndexListsOnlyTheFilteredSendersDocuments
     *
     * @return void
     */
    public function test_index_lists_only_the_filtered_senders_documents(): void
    {
        $user = User::factory()->create();
        PdfDocument::factory()->count(2)->for($user)->create(['sender_email' => 'billing@acme.test', 'size' => 1000]);
        PdfDocument::factory()->for($user)->create(['sender_email' => 'ops@acme.test']);
        PdfDocument::factory()->create(); // someone else's mailbox

        $this->actingAs($user)
            ->getJson('/pdfs?senders[]=billing@acme.test')
            ->assertOk()
            ->assertJsonCount(2, 'documents')
            ->assertJsonPath('total_size', 2000);

        $this->actingAs($user)->getJson('/pdfs')->assertJsonCount(3, 'documents');
    }

    /**
     * testIndexOffersEachPdfSenderOnce
     *
     * @return void
     */
    public function test_index_offers_each_pdf_sender_once(): void
    {
        $user = User::factory()->create();
        PdfDocument::factory()->count(2)->for($user)->create(['sender_email' => 'ops@acme.test']);
        PdfDocument::factory()->for($user)->create(['sender_email' => 'billing@acme.test', 'sent_at' => now()->subYear()]);
        PdfDocument::factory()->create(['sender_email' => 'stranger@other.test']);

        $this->actingAs($user)
            ->getJson('/pdfs?senders[]=ops@acme.test')
            ->assertJsonPath('senders', ['billing@acme.test', 'ops@acme.test']);
    }

    /**
     * testIndexListsOnlyDocumentsWithinTheDateRange
     *
     * @return void
     */
    public function test_index_lists_only_documents_within_the_date_range(): void
    {
        $this->travelTo(Carbon::parse('2026-09-11 15:30:00'));
        $user = User::factory()->create();
        PdfDocument::factory()->for($user)->create(['filename' => 'this-week.pdf', 'sent_at' => '2026-09-05 09:00:00']);
        PdfDocument::factory()->for($user)->create(['filename' => 'august.pdf', 'sent_at' => '2026-08-20 09:00:00']);

        $this->actingAs($user)
            ->getJson('/pdfs')
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.filename', 'this-week.pdf');

        $this->actingAs($user)
            ->getJson('/pdfs?from=2026-08-20&to=2026-08-20')
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.filename', 'august.pdf');
    }

    /**
     * testDownloadSendsTheStoredPdf
     *
     * @return void
     */
    public function test_download_sends_the_stored_pdf(): void
    {
        $user = User::factory()->create();
        $document = PdfDocument::factory()->for($user)->create(['filename' => 'invoice-42.pdf']);
        Storage::disk()->put($document->path, '%PDF-1.4 invoice');

        $response = $this->actingAs($user)->get("/pdfs/{$document->id}/download");

        $response->assertOk()->assertDownload('invoice-42.pdf');
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('%PDF-1.4 invoice', $response->streamedContent());
    }

    /**
     * testUserCannotDownloadSomeoneElsesPdf
     *
     * @return void
     */
    public function test_user_cannot_download_someone_elses_pdf(): void
    {
        $document = PdfDocument::factory()->create();
        Storage::disk()->put($document->path, '%PDF-1.4 private');

        $this->actingAs(User::factory()->create())
            ->get("/pdfs/{$document->id}/download")
            ->assertNotFound();
    }

    /**
     * testDownloadOfAMissingFileIsNotFound
     *
     * @return void
     */
    public function test_download_of_a_missing_file_is_not_found(): void
    {
        $user = User::factory()->create();
        $document = PdfDocument::factory()->for($user)->create();

        $this->actingAs($user)->get("/pdfs/{$document->id}/download")->assertNotFound();
    }

    /**
     * testStoredPathIsNotExposed
     *
     * @return void
     */
    public function test_stored_path_is_not_exposed(): void
    {
        $user = User::factory()->create();
        PdfDocument::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson('/pdfs')
            ->assertOk()
            ->assertJsonMissingPath('documents.0.path')
            ->assertJsonMissingPath('documents.0.user_id');
    }

    /**
     * testGuestsCannotUsePdfs
     *
     * @return void
     */
    public function test_guests_cannot_use_pdfs(): void
    {
        $this->getJson('/pdfs')->assertUnauthorized();
        $this->postJson('/pdfs/collect')->assertUnauthorized();
        $this->getJson('/pdfs/1/download')->assertUnauthorized();
    }
}
