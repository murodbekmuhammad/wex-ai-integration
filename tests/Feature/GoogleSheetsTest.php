<?php

namespace Tests\Feature;

use App\Models\ReportTable;
use App\Models\User;
use App\Services\GoogleAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * @class GoogleSheetsTest
 *
 * @package Tests\Feature
 */
class GoogleSheetsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * testTableIsUploadedAsAGoogleSheet
     *
     * @return void
     */
    public function test_table_is_uploaded_as_a_google_sheet(): void
    {
        Http::fake([
            'www.googleapis.com/upload/drive/v3/files*' => Http::response(['id' => 'sheet-1', 'webViewLink' => 'https://docs.google.com/spreadsheets/d/sheet-1/edit']),
        ]);
        $user = User::factory()->create();
        $table = ReportTable::factory()->for($user)->create(['title' => 'Revenue by branch']);

        $this->actingAs($user)
            ->postJson("/tables/{$table->id}/google-sheet")
            ->assertOk()
            ->assertJsonPath('google_sheet_url', 'https://docs.google.com/spreadsheets/d/sheet-1/edit');

        Http::assertSent(function (Request $request) {
            return str_starts_with($request->url(), 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart')
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && str_contains($request->body(), '"name":"Revenue by branch","mimeType":"application\/vnd.google-apps.spreadsheet"')
                && str_contains($request->body(), "PK\x03\x04");
        });

        $table->refresh();
        $this->assertSame('sheet-1', $table->google_sheet_id);
        $this->assertSame('https://docs.google.com/spreadsheets/d/sheet-1/edit', $table->google_sheet_url);
    }

    /**
     * testAnAlreadyUploadedTableIsNotUploadedAgain
     *
     * @return void
     */
    public function test_an_already_uploaded_table_is_not_uploaded_again(): void
    {
        Http::fake([
            'www.googleapis.com/drive/v3/files/sheet-1*' => Http::response(['id' => 'sheet-1', 'trashed' => false]),
        ]);
        $user = User::factory()->create();
        $table = ReportTable::factory()->for($user)->create(['google_sheet_id' => 'sheet-1', 'google_sheet_url' => 'https://sheet-1']);

        $this->actingAs($user)
            ->postJson("/tables/{$table->id}/google-sheet")
            ->assertOk()
            ->assertJsonPath('google_sheet_url', 'https://sheet-1');

        Http::assertSentCount(1);
    }

    /**
     * testATrashedSheetIsUploadedAgain
     *
     * @return void
     */
    public function test_a_trashed_sheet_is_uploaded_again(): void
    {
        Http::fake([
            'www.googleapis.com/drive/v3/files/sheet-1*' => Http::response(['id' => 'sheet-1', 'trashed' => true]),
            'www.googleapis.com/upload/drive/v3/files*' => Http::response(['id' => 'sheet-2', 'webViewLink' => 'https://sheet-2']),
        ]);
        $user = User::factory()->create();
        $table = ReportTable::factory()->for($user)->create(['google_sheet_id' => 'sheet-1', 'google_sheet_url' => 'https://sheet-1']);

        $this->actingAs($user)
            ->postJson("/tables/{$table->id}/google-sheet")
            ->assertOk()
            ->assertJsonPath('google_sheet_url', 'https://sheet-2');

        $this->assertSame('sheet-2', $table->refresh()->google_sheet_id);
    }

    /**
     * testMissingDriveAccessAsksUserToSignInAgain
     *
     * @return void
     */
    public function test_missing_drive_access_asks_user_to_sign_in_again(): void
    {
        Http::fake([
            'www.googleapis.com/upload/drive/v3/files*' => Http::response(['error' => [
                'code' => 403,
                'message' => 'Request had insufficient authentication scopes.',
                'errors' => [['reason' => 'insufficientPermissions']],
            ]], 403),
        ]);
        $user = User::factory()->create();
        $table = ReportTable::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/tables/{$table->id}/google-sheet")
            ->assertForbidden()
            ->assertJsonPath('message', 'Sign in again and allow access to Google Drive to upload tables to Google Sheets.');

        $this->assertNull($table->refresh()->google_sheet_id);
    }

    /**
     * testDriveFailuresGiveAReadableMessage
     *
     * A rate-limit 403 is a Drive failure, not missing access.
     *
     * @return void
     */
    public function test_drive_failures_give_a_readable_message(): void
    {
        Http::fake([
            'www.googleapis.com/upload/drive/v3/files*' => Http::response(['error' => [
                'code' => 403,
                'errors' => [['reason' => 'userRateLimitExceeded']],
            ]], 403),
        ]);
        $user = User::factory()->create();
        $table = ReportTable::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/tables/{$table->id}/google-sheet")
            ->assertStatus(502)
            ->assertJsonPath('message', 'Google Drive could not take the file right now. Please try again.');
    }

    /**
     * testRevokedGoogleAccessReturns401
     *
     * @return void
     */
    public function test_revoked_google_access_returns_401(): void
    {
        Http::fake(['www.googleapis.com/upload/drive/v3/files*' => Http::response([], 401)]);
        $user = User::factory()->create();
        $table = ReportTable::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/tables/{$table->id}/google-sheet")->assertUnauthorized();
    }

    /**
     * testUserCannotUploadSomeoneElsesTable
     *
     * @return void
     */
    public function test_user_cannot_upload_someone_elses_table(): void
    {
        Http::fake();
        $table = ReportTable::factory()->create();

        $this->postJson("/tables/{$table->id}/google-sheet")->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson("/tables/{$table->id}/google-sheet")->assertNotFound();

        Http::assertNothingSent();
    }

    /**
     * testSignInAsksForDriveAccess
     *
     * @return void
     */
    public function test_sign_in_asks_for_drive_access(): void
    {
        $this->assertStringContainsString(
            urlencode(GoogleAuth::DRIVE_FILE_SCOPE),
            $this->get('/auth/google')->headers->get('Location'),
        );
    }
}
