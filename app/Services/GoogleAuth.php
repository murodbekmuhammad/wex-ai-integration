<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * @class GoogleAuth
 *
 * @package App\Services
 *
 * Minimal Google OAuth 2.0 client: builds the consent URL, exchanges the
 * returned code for tokens, and keeps a user's access token fresh.
 */
class GoogleAuth
{
    public const GMAIL_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    /**
     * Lets the app create files in the user's Drive and see only those files,
     * which is all uploading tables to Google Sheets needs.
     */
    public const DRIVE_FILE_SCOPE = 'https://www.googleapis.com/auth/drive.file';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /**
     * authorizationUrl
     *
     * URL of Google's consent screen. Asks for offline access so we get a
     * refresh token and can keep reading Gmail after the access token expires.
     * Drive access is requested too, but is optional: without it only the
     * Google Sheets upload is unavailable.
     *
     * @param string $state
     * @return string
     */
    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email profile '.self::GMAIL_SCOPE.' '.self::DRIVE_FILE_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * userFromCode
     *
     * Exchange the authorization code for tokens and fetch the Google profile.
     *
     * @param string $code
     * @return array{id: string, name: string, email: string, token: string, refresh_token: string|null, expires_in: int, scopes: array<int, string>}
     * @throws RuntimeException
     */
    public function userFromCode(string $code): array
    {
        $tokens = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if ($tokens->failed()) {
            throw new RuntimeException('Google token exchange failed: '.$tokens->body());
        }

        $profile = Http::withToken($tokens->json('access_token'))->get(self::USERINFO_URL);

        if ($profile->failed()) {
            throw new RuntimeException('Fetching the Google profile failed: '.$profile->body());
        }

        return [
            'id' => $profile->json('sub'),
            'name' => $profile->json('name') ?? $profile->json('email'),
            'email' => $profile->json('email'),
            'token' => $tokens->json('access_token'),
            'refresh_token' => $tokens->json('refresh_token'),
            'expires_in' => (int) $tokens->json('expires_in'),
            'scopes' => explode(' ', (string) $tokens->json('scope')),
        ];
    }

    /**
     * accessToken
     *
     * Return a valid access token for the user, refreshing it when it is
     * about to expire.
     *
     * @param User $user
     * @return string
     * @throws AuthenticationException when Google no longer accepts the refresh token
     */
    public function accessToken(User $user): string
    {
        if ($user->google_token_expires_at?->isAfter(now()->addMinute())) {
            return $user->google_token;
        }

        if (! $user->google_refresh_token) {
            throw new AuthenticationException('Your Google session expired. Please sign in again.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $user->google_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            Log::warning('Google token refresh failed', ['user' => $user->id, 'body' => $response->body()]);

            throw new AuthenticationException('Your Google session expired. Please sign in again.');
        }

        $user->update([
            'google_token' => $response->json('access_token'),
            'google_token_expires_at' => now()->addSeconds((int) $response->json('expires_in')),
        ]);

        return $user->google_token;
    }
}
