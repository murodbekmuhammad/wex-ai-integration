<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * @class AuthTest
 *
 * @package Tests\Feature
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * testRedirectSendsUserToGoogleWithGmailScope
     *
     * @return void
     */
    public function test_redirect_sends_user_to_google_with_gmail_scope(): void
    {
        $response = $this->get('/auth/google');

        $response->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth');
        $this->assertStringContainsString(urlencode(GoogleAuth::GMAIL_SCOPE), $response->headers->get('Location'));
        $this->assertNotNull(session('google_oauth_state'));
    }

    /**
     * testCallbackCreatesUserAndLogsIn
     *
     * @return void
     */
    public function test_callback_creates_user_and_logs_in(): void
    {
        $this->fakeGoogle(scope: 'openid email profile '.GoogleAuth::GMAIL_SCOPE);

        $this->withSession(['google_oauth_state' => 'abc'])
            ->get('/auth/google/callback?state=abc&code=the-code')
            ->assertRedirect('/');

        $user = User::firstWhere('google_id', 'g-123');
        $this->assertSame('jane@gmail.com', $user->email);
        $this->assertSame('access-1', $user->google_token);
        $this->assertSame('refresh-1', $user->google_refresh_token);
        $this->assertAuthenticatedAs($user);
    }

    /**
     * testCallbackRejectsWrongState
     *
     * @return void
     */
    public function test_callback_rejects_wrong_state(): void
    {
        Http::fake();

        $this->withSession(['google_oauth_state' => 'abc'])
            ->get('/auth/google/callback?state=evil&code=the-code')
            ->assertRedirect('/')
            ->assertSessionHas('error');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    /**
     * testCallbackRequiresGmailPermission
     *
     * @return void
     */
    public function test_callback_requires_gmail_permission(): void
    {
        $this->fakeGoogle(scope: 'openid email profile');

        $this->withSession(['google_oauth_state' => 'abc'])
            ->get('/auth/google/callback?state=abc&code=the-code')
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    /**
     * testUserCanLogout
     *
     * @return void
     */
    public function test_user_can_logout(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/logout')
            ->assertOk();

        $this->assertGuest();
    }

    /**
     * fakeGoogle
     *
     * Fake Google's token and userinfo endpoints.
     *
     * @param string $scope
     * @return void
     */
    private function fakeGoogle(string $scope): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-1',
                'refresh_token' => 'refresh-1',
                'expires_in' => 3600,
                'scope' => $scope,
            ]),
            'openidconnect.googleapis.com/*' => Http::response([
                'sub' => 'g-123',
                'name' => 'Jane',
                'email' => 'jane@gmail.com',
            ]),
        ]);
    }
}
