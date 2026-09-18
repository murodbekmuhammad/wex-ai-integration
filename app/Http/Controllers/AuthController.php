<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GoogleAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * @class AuthController
 *
 * @package App\Http\Controllers
 */
class AuthController extends Controller
{
    /**
     * __construct
     *
     * @param GoogleAuth $google
     */
    public function __construct(private GoogleAuth $google) {}

    /**
     * redirect
     *
     * Send the user to Google's consent screen.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away($this->google->authorizationUrl($state));
    }

    /**
     * callback
     *
     * Google sends the user back here. Create or update the account, save the
     * Gmail tokens and log the user in.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull('google_oauth_state');

        if (! $expectedState || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return $this->fail('Sign-in expired. Please try again.');
        }

        if ($request->query('error') || ! $request->query('code')) {
            return $this->fail('Google sign-in was cancelled.');
        }

        try {
            $google = $this->google->userFromCode($request->query('code'));
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Google sign-in failed. Please try again.');
        }

        // Google lets users untick individual permissions on the consent screen.
        if (! in_array(GoogleAuth::GMAIL_SCOPE, $google['scopes'], true)) {
            return $this->fail('Please allow access to your Gmail so we can show your emails.');
        }

        $user = User::updateOrCreate(['google_id' => $google['id']], array_filter([
            'name' => $google['name'],
            'email' => $google['email'],
            'google_token' => $google['token'],
            // Google only sends a refresh token sometimes; keep the old one if it's missing.
            'google_refresh_token' => $google['refresh_token'],
            'google_token_expires_at' => now()->addSeconds($google['expires_in']),
        ]));

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect('/');
    }

    /**
     * logout
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    /**
     * fail
     *
     * Go back to the sign-in page with an error message.
     *
     * @param string $message
     * @return RedirectResponse
     */
    private function fail(string $message): RedirectResponse
    {
        return redirect('/')->with('error', $message);
    }
}
