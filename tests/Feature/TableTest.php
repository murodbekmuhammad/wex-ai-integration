<?php

namespace Tests\Feature;

use Anthropic\Core\Exceptions\BadRequestException;
use App\Models\PdfDocument;
use App\Models\ReportTable;
use App\Models\User;
use App\Services\PdfAnalyst;
use App\Services\TableBuildException;
use App\Services\TableBuilder;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Throwable;

/**
 * @class TableTest
 *
 * @package Tests\Feature
 */
class TableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fake table builder bound in setUp; records what it was asked.
     */
    private object $fake;

    /**
     * setUp
     *
     * Replace the real Claude call with a fake that returns a canned table.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.key' => 'test-key']);

        $this->fake = new class(new PdfAnalyst()) extends TableBuilder
        {
            public ?Collection $documents = null;

            public ?string $request = null;

            public ?ReportTable $current = null;

            /**
             * Thrown instead of answering, to simulate a failure.
             */
            public ?Throwable $error = null;

            /**
             * build
             *
             * @param Collection $documents
             * @param string $request
             * @param ReportTable|null $current
             * @return array<string, mixed>
             * @throws Throwable
             */
            public function build(Collection $documents, string $request, ?ReportTable $current = null): array
            {
                $this->documents = $documents;
                $this->request = $request;
                $this->current = $current;

                if ($this->error) {
                    throw $this->error;
                }

                return [
                    'title' => 'Revenue by branch',
                    'summary' => 'Revenue from both reports.',
                    'columns' => ['Branch', 'Revenue (UZS)'],
                    'rows' => [['Tashkent', 1200], ['Samarkand', 800]],
                    'warnings' => [],
                ];
            }
        };

        $this->app->instance(TableBuilder::class, $this->fake);
    }

    /**
     * testTableIsBuiltFromTheFilteredPdfsAndSaved
     *
     * @return void
     */
    public function test_table_is_built_from_the_filtered_pdfs_and_saved(): void
    {
        $user = User::factory()->create();
        $billing = PdfDocument::factory()->count(2)->for($user)->create(['sender_email' => 'billing@acme.test']);
        PdfDocument::factory()->for($user)->create(['sender_email' => 'ops@acme.test']);

        $response = $this->actingAs($user)->postJson('/tables', [
            'question' => 'Revenue by branch',
            'senders' => ['billing@acme.test'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('title', 'Revenue by branch')
            ->assertJsonPath('rows.0', ['Tashkent', 1200])
            ->assertJsonMissingPath('user_id');

        $this->assertSame('Revenue by branch', $this->fake->request);
        $this->assertEqualsCanonicalizing($billing->pluck('id')->all(), $this->fake->documents->pluck('id')->all());

        $table = $user->reportTables()->sole();
        $this->assertSame('Revenue by branch', $table->request);
        $this->assertEqualsCanonicalizing($billing->pluck('id')->all(), $table->pdf_document_ids);
    }

    /**
     * testBuildingATableGetsClaudesTimeLimit
     *
     * @return void
     */
    public function test_building_a_table_gets_claudes_time_limit(): void
    {
        config(['services.anthropic.time_limit' => 123]);
        $user = User::factory()->create();
        PdfDocument::factory()->for($user)->create();

        try {
            $this->actingAs($user)->postJson('/tables', ['question' => 'Revenue by branch'])->assertCreated();

            $this->assertSame('123', ini_get('max_execution_time'));
        } finally {
            set_time_limit(0);
        }
    }

    /**
     * testOnlyTickedPdfsAreUsed
     *
     * @return void
     */
    public function test_only_ticked_pdfs_are_used(): void
    {
        $user = User::factory()->create();
        $documents = PdfDocument::factory()->count(3)->for($user)->create();
        $someoneElses = PdfDocument::factory()->create();

        $this->actingAs($user)->postJson('/tables', [
            'question' => 'Combine these',
            'document_ids' => [$documents[0]->id, $documents[2]->id, $someoneElses->id],
        ])->assertCreated();

        $this->assertEqualsCanonicalizing([$documents[0]->id, $documents[2]->id], $this->fake->documents->pluck('id')->all());
    }

    /**
     * testRevisingATableReusesItsPdfs
     *
     * @return void
     */
    public function test_revising_a_table_reuses_its_pdfs(): void
    {
        $user = User::factory()->create();
        $used = PdfDocument::factory()->for($user)->create();
        PdfDocument::factory()->for($user)->create();
        $table = ReportTable::factory()->for($user)->create(['pdf_document_ids' => [$used->id]]);

        $this->actingAs($user)
            ->postJson('/tables', ['question' => 'Add a total row', 'table_id' => $table->id])
            ->assertCreated();

        $this->assertTrue($this->fake->current->is($table));
        $this->assertSame([$used->id], $this->fake->documents->pluck('id')->all());
        $this->assertSame(2, $user->reportTables()->count());
    }

    /**
     * testUserCannotReviseSomeoneElsesTable
     *
     * @return void
     */
    public function test_user_cannot_revise_someone_elses_table(): void
    {
        $table = ReportTable::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/tables', ['question' => 'Add a total row', 'table_id' => $table->id])
            ->assertNotFound();

        $this->assertNull($this->fake->request);
    }

    /**
     * testATableNeedsCollectedPdfs
     *
     * @return void
     */
    public function test_a_table_needs_collected_pdfs(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/tables', ['question' => 'Revenue by branch'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Collect some PDFs first, then ask for a table.');
    }

    /**
     * testWhenClaudeFindsNoTableItsReasonIsShown
     *
     * @return void
     */
    public function test_when_claude_finds_no_table_its_reason_is_shown(): void
    {
        $this->fake->error = new TableBuildException('These PDFs have no revenue figures.');
        $user = User::factory()->create();
        PdfDocument::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/tables', ['question' => 'Revenue by branch'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'These PDFs have no revenue figures.');

        $this->assertSame(0, $user->reportTables()->count());
    }

    /**
     * testClaudeApiErrorsGiveAReadableMessage
     *
     * @return void
     */
    public function test_claude_api_errors_give_a_readable_message(): void
    {
        $this->fake->error = new BadRequestException(
            new Request('POST', 'https://api.anthropic.com/v1/messages'),
            new Response(400, [], json_encode(['type' => 'error', 'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Your credit balance is too low to access the Anthropic API.',
            ]])),
        );
        $user = User::factory()->create();
        PdfDocument::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/tables', ['question' => 'Revenue by branch'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Claude returned an error: Your credit balance is too low to access the Anthropic API.');
    }

    /**
     * testUserOnlySeesOwnTables
     *
     * @return void
     */
    public function test_user_only_sees_own_tables(): void
    {
        $user = User::factory()->create();
        $own = ReportTable::factory()->for($user)->create();
        $someoneElses = ReportTable::factory()->create();

        $this->actingAs($user)->getJson('/tables')->assertOk()->assertJsonCount(1, 'tables')->assertJsonPath('tables.0.id', $own->id);
        $this->actingAs($user)->getJson("/tables/{$own->id}")->assertOk()->assertJsonPath('columns', $own->columns);
        $this->actingAs($user)->getJson("/tables/{$someoneElses->id}")->assertNotFound();
        $this->actingAs($user)->get("/tables/{$someoneElses->id}/download/xlsx")->assertNotFound();
        $this->actingAs($user)->deleteJson("/tables/{$someoneElses->id}")->assertNotFound();
    }

    /**
     * testTableDownloadsAsExcel
     *
     * Numbers stay numbers, and text that looks like a formula stays text.
     *
     * @return void
     */
    public function test_table_downloads_as_excel(): void
    {
        $user = User::factory()->create();
        $table = ReportTable::factory()->for($user)->create([
            'title' => 'Revenue by branch',
            'columns' => ['Branch', 'Revenue (UZS)'],
            'rows' => [['Tashkent', 1200.5], ['=HYPERLINK("http://evil.test")', null]],
        ]);

        $response = $this->actingAs($user)->get("/tables/{$table->id}/download/xlsx");

        $response->assertOk()->assertDownload('revenue-by-branch.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getSheet(0);
        unlink($path);

        $this->assertSame('Branch', $sheet->getCell('A1')->getValue());
        $this->assertSame(1200.5, $sheet->getCell('B2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A3')->getDataType());
        $this->assertSame('=HYPERLINK("http://evil.test")', $sheet->getCell('A3')->getValue());
    }

    /**
     * testTableDownloadsAsPdf
     *
     * @return void
     */
    public function test_table_downloads_as_pdf(): void
    {
        $user = User::factory()->create();
        $table = ReportTable::factory()->for($user)->create(['title' => 'Выручка по филиалам']);

        $response = $this->actingAs($user)->get("/tables/{$table->id}/download/pdf");

        $response->assertOk()->assertDownload('vyrucka-po-filialam.pdf');
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /**
     * testUnknownDownloadFormatIsNotFound
     *
     * @return void
     */
    public function test_unknown_download_format_is_not_found(): void
    {
        $user = User::factory()->create();
        $table = ReportTable::factory()->for($user)->create();

        $this->actingAs($user)->get("/tables/{$table->id}/download/csv")->assertNotFound();
    }

    /**
     * testTableCanBeDeleted
     *
     * @return void
     */
    public function test_table_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $table = ReportTable::factory()->for($user)->create();

        $this->actingAs($user)->deleteJson("/tables/{$table->id}")->assertOk();

        $this->assertModelMissing($table);
    }

    /**
     * testGuestsCannotUseTables
     *
     * @return void
     */
    public function test_guests_cannot_use_tables(): void
    {
        $this->getJson('/tables')->assertUnauthorized();
        $this->postJson('/tables', ['question' => 'Hi'])->assertUnauthorized();
        $this->getJson('/tables/1/download/xlsx')->assertUnauthorized();
    }

    /**
     * testClaudesAnswerIsShapedIntoATable
     *
     * Rows get exactly one cell per column, whatever Claude returned.
     *
     * @return void
     */
    public function test_claudes_answer_is_shaped_into_a_table(): void
    {
        $table = (new TableBuilder(new PdfAnalyst()))->fromJson(json_encode([
            'title' => 'Revenue',
            'summary' => 'Two branches.',
            'columns' => ['Branch', 'Revenue'],
            'rows' => [['Tashkent', 1200, 'extra'], ['Samarkand']],
            'has_total_row' => false,
        ]));

        $this->assertSame([['Tashkent', 1200], ['Samarkand', null]], $table['rows']);
        $this->assertSame([], $table['warnings']);
    }

    /**
     * testAnEmptyTableExplainsWhy
     *
     * @return void
     */
    public function test_an_empty_table_explains_why(): void
    {
        $this->expectException(TableBuildException::class);
        $this->expectExceptionMessage('The PDFs have no revenue figures.');

        (new TableBuilder(new PdfAnalyst()))->fromJson(json_encode([
            'title' => '',
            'summary' => 'The PDFs have no revenue figures.',
            'columns' => [],
            'rows' => [],
            'has_total_row' => false,
        ]));
    }

    /**
     * testAWrongTotalIsFlagged
     *
     * @return void
     */
    public function test_a_wrong_total_is_flagged(): void
    {
        $builder = new TableBuilder(new PdfAnalyst());
        $columns = ['Branch', 'Revenue', 'Orders'];

        $warnings = $builder->totalMismatches($columns, [
            ['Tashkent', 1200.25, 10],
            ['Samarkand', 800, 5],
            ['Total', 2100, 15],
        ]);

        $this->assertSame(['The total for "Revenue" is 2,100, but the rows above add up to 2,000.25.'], $warnings);
        $this->assertSame([], $builder->totalMismatches($columns, [['A', 0.1, 1], ['B', 0.2, 2], ['Total', 0.3, 3]]));
    }
}
