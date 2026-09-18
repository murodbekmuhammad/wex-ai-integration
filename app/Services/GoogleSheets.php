<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @class GoogleSheets
 *
 * @package App\Services
 *
 * Uploads Excel workbooks to the user's Google Drive, where Google converts
 * them into Google Sheets.
 */
class GoogleSheets
{
    private const FILES_URL = 'https://www.googleapis.com/drive/v3/files';
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';
    private const SHEET_MIME_TYPE = 'application/vnd.google-apps.spreadsheet';
    private const XLSX_MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * __construct
     *
     * @param GoogleAuth $google
     */
    public function __construct(private GoogleAuth $google) {}

    /**
     * upload
     *
     * Upload an .xlsx file and have Drive convert it into a Google Sheet.
     *
     * @param User $user
     * @param string $name title of the new sheet
     * @param string $xlsx the workbook's bytes
     * @return array{id: string, url: string}
     * @throws AuthenticationException when Google no longer accepts the user's tokens
     * @throws AuthorizationException when the user hasn't granted Google Drive access
     * @throws RequestException
     */
    public function upload(User $user, string $name, string $xlsx): array
    {
        $boundary = Str::random(32);
        $metadata = json_encode(['name' => $name, 'mimeType' => self::SHEET_MIME_TYPE]);

        $body = "--{$boundary}\r\n"
            ."Content-Type: application/json; charset=UTF-8\r\n\r\n{$metadata}\r\n"
            ."--{$boundary}\r\n"
            .'Content-Type: '.self::XLSX_MIME_TYPE."\r\n\r\n{$xlsx}\r\n"
            ."--{$boundary}--";

        $response = Http::withToken($this->google->accessToken($user))
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->post(self::UPLOAD_URL.'?uploadType=multipart&fields=id,webViewLink');

        $this->guardAccess($response);

        $file = $response->throw()->json();

        return ['id' => $file['id'], 'url' => $file['webViewLink']];
    }

    /**
     * exists
     *
     * Whether a sheet uploaded earlier is still in the user's Drive and not
     * in the trash.
     *
     * @param User $user
     * @param string $fileId
     * @return bool
     * @throws AuthenticationException when Google no longer accepts the user's tokens
     * @throws AuthorizationException when the user hasn't granted Google Drive access
     * @throws RequestException
     */
    public function exists(User $user, string $fileId): bool
    {
        $response = Http::withToken($this->google->accessToken($user))
            ->get(self::FILES_URL.'/'.rawurlencode($fileId), ['fields' => 'id,trashed']);

        if ($response->notFound()) {
            return false;
        }

        $this->guardAccess($response);

        return ! $response->throw()->json('trashed');
    }

    /**
     * guardAccess
     *
     * Turn Google's "who are you" and "not allowed" answers into exceptions
     * the controller can explain to the user.
     *
     * @param Response $response
     * @return void
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    private function guardAccess(Response $response): void
    {
        if ($response->status() === 401) {
            throw new AuthenticationException('Google access was revoked. Please sign in again.');
        }

        // A 403 is also used for rate limits, so only a missing scope means "not allowed".
        if ($response->forbidden() && Str::contains($response->body(), ['insufficientPermissions', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'])) {
            throw new AuthorizationException('Sign in again and allow access to Google Drive to upload tables to Google Sheets.');
        }
    }
}
