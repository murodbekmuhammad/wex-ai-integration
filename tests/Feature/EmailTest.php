<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * @class EmailTest
 *
 * @package Tests\Feature
 */
class EmailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * testGuestsCannotListEmails
     *
     * @return void
     */
    public function test_guests_cannot_list_emails(): void
    {
        $this->getJson('/emails')->assertUnauthorized();
    }

    /**
     * testSyncStoresGmailMessages
     *
     * @return void
     */
    public function test_sync_stores_gmail_messages(): void
    {
        $user = User::factory()->create();
        Email::factory()->for($user)->create(['gmail_id' => 'm1']); // already synced

        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([
                'messages' => [['id' => 'm1'], ['id' => 'm2']],
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m2*' => Http::response([
                'id' => 'm2',
                'internalDate' => '1767225600000',
                'snippet' => 'Hi there, it&#39;s me',
                'payload' => ['headers' => [
                    ['name' => 'From', 'value' => 'Bob <bob@example.com>'],
                    ['name' => 'To', 'value' => '"Doe, Jane" <Jane@Example.com>, team@example.com'],
                    ['name' => 'Cc', 'value' => 'boss@example.com'],
                    ['name' => 'Subject', 'value' => 'Hello'],
                ]],
            ]),
        ]);

        $this->actingAs($user)->postJson('/emails/sync')->assertOk()->assertJson(['synced' => 1]);

        $email = $user->emails()->firstWhere('gmail_id', 'm2');
        $this->assertSame('Bob <bob@example.com>', $email->sender);
        $this->assertSame('bob@example.com', $email->sender_email);
        $this->assertSame(['jane@example.com', 'team@example.com', 'boss@example.com'], $email->receivers);
        $this->assertSame("Hi there, it's me", $email->snippet);
        $this->assertSame('2026-01-01', $email->sent_at->toDateString());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/messages/m1'));
    }

    /**
     * testSyncRefreshesExpiredToken
     *
     * @return void
     */
    public function test_sync_refreshes_expired_token(): void
    {
        $user = User::factory()->create(['google_token_expires_at' => now()->subMinute()]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
            'gmail.googleapis.com/*' => Http::response(['resultSizeEstimate' => 0]),
        ]);

        $this->actingAs($user)->postJson('/emails/sync')->assertOk();

        $this->assertSame('fresh', $user->fresh()->google_token);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gmail.googleapis.com')
            && $request->hasHeader('Authorization', 'Bearer fresh'));
    }

    /**
     * testSyncReturns401WhenGoogleAccessIsRevoked
     *
     * @return void
     */
    public function test_sync_returns_401_when_google_access_is_revoked(): void
    {
        $user = User::factory()->create(['google_token_expires_at' => now()->subMinute()]);

        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->actingAs($user)->postJson('/emails/sync')->assertUnauthorized();
    }

    /**
     * testUserOnlySeesOwnEmails
     *
     * @return void
     */
    public function test_user_only_sees_own_emails(): void
    {
        $user = User::factory()->create();
        Email::factory()->count(3)->for($user)->create();
        Email::factory()->count(2)->create();

        $this->actingAs($user)
            ->getJson('/emails')
            ->assertOk()
            ->assertJsonCount(3, 'emails.data')
            ->assertJsonCount(3, 'receivers');
    }

    /**
     * testEmailsCanBeFilteredByReceiver
     *
     * @return void
     */
    public function test_emails_can_be_filtered_by_receiver(): void
    {
        $user = User::factory()->create();
        Email::factory()->count(2)->for($user)->create(['receivers' => ['me@example.com', 'team@example.com']]);
        Email::factory()->count(3)->for($user)->create(['receivers' => ['me@example.com']]);

        $this->actingAs($user)
            ->getJson('/emails?receiver=team@example.com')
            ->assertOk()
            ->assertJsonCount(2, 'emails.data')
            ->assertJsonPath('receivers', ['me@example.com', 'team@example.com']);

        $this->actingAs($user)
            ->getJson('/emails?receiver=me@example.com')
            ->assertJsonCount(5, 'emails.data');
    }

    /**
     * testEmailsCanBeFilteredBySenders
     *
     * @return void
     */
    public function test_emails_can_be_filtered_by_senders(): void
    {
        $user = User::factory()->create();
        Email::factory()->count(2)->for($user)->create(['sender_email' => 'billing@acme.test']);
        Email::factory()->for($user)->create(['sender_email' => 'ops@acme.test']);
        Email::factory()->count(3)->for($user)->create(['sender_email' => 'news@other.test']);

        $this->actingAs($user)
            ->getJson('/emails?senders[]=billing@acme.test&senders[]=ops@acme.test')
            ->assertOk()
            ->assertJsonCount(3, 'emails.data')
            ->assertJsonPath('senders', ['billing@acme.test', 'news@other.test', 'ops@acme.test']);

        $this->actingAs($user)
            ->getJson('/emails?senders[]=billing@acme.test')
            ->assertJsonCount(2, 'emails.data');
    }

    /**
     * testEmailsCanBeSorted
     *
     * @return void
     */
    public function test_emails_can_be_sorted(): void
    {
        $user = User::factory()->create();
        foreach (['Banana', 'Apple', 'Cherry'] as $subject) {
            Email::factory()->for($user)->create(['subject' => $subject]);
        }

        $this->actingAs($user)
            ->getJson('/emails?sort=subject&direction=asc')
            ->assertJsonPath('emails.data.*.subject', ['Apple', 'Banana', 'Cherry']);

        $this->actingAs($user)
            ->getJson('/emails?sort=subject&direction=desc')
            ->assertJsonPath('emails.data.*.subject', ['Cherry', 'Banana', 'Apple']);
    }

    /**
     * testInvalidSortColumnIsRejected
     *
     * @return void
     */
    public function test_invalid_sort_column_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/emails?sort=google_token')
            ->assertUnprocessable();
    }

    /**
     * testShowReturnsMessageBodyFromGmail
     *
     * @return void
     */
    public function test_show_returns_message_body_from_gmail(): void
    {
        $user = User::factory()->create();
        $email = Email::factory()->for($user)->create(['gmail_id' => 'm9']);
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/m9*' => Http::response([
                'id' => 'm9',
                'payload' => [
                    'mimeType' => 'multipart/mixed',
                    'parts' => [
                        ['mimeType' => 'multipart/alternative', 'parts' => [
                            ['mimeType' => 'text/plain', 'filename' => '', 'body' => ['data' => $b64('Plain hello')]],
                            ['mimeType' => 'text/html', 'filename' => '', 'body' => ['data' => $b64('<p>Hello</p>')]],
                        ]],
                        ['mimeType' => 'text/plain', 'filename' => 'notes.txt', 'body' => ['attachmentId' => 'a1']],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->getJson("/emails/{$email->id}")
            ->assertOk()
            ->assertExactJson(['html' => '<p>Hello</p>', 'text' => 'Plain hello']);
    }

    /**
     * testUserCannotReadSomeoneElsesEmail
     *
     * @return void
     */
    public function test_user_cannot_read_someone_elses_email(): void
    {
        $other = Email::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/emails/{$other->id}")
            ->assertNotFound();
    }
}
