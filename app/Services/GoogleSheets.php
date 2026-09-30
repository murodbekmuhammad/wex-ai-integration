<?php

namespace App\Services;

use App\Models\ReportTable;
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
     * @param TableExporter $exporter
     */
    public function __construct(private GoogleAuth $google, private TableExporter $exporter) {}

    /**
     * publish
     *
     * Upload the table to the user's Drive as a Google Sheet and remember
     * where it went. A table that was uploaded before and is still in Drive
     * isn't uploaded again; its existing sheet is kept.
     *
     * @param User $user
     * @param ReportTable $table
     * @return string the sheet's URL
     * @throws AuthenticationException when Google no longer accepts the user's tokens
     * @throws AuthorizationException when the user hasn't granted Google Drive access
     * @throws RequestException
     */
    public function publish(User $user, ReportTable $table): string
    {
        if (! $table->google_sheet_id || ! $this->exists($user, $table->google_sheet_id)) {
            $sheet = $this->upload($user, $table->title, $this->exporter->xlsxContents($table));

            $table->update(['google_sheet_id' => $sheet['id'], 'google_sheet_url' => $sheet['url']]);
        }

        return $table->google_sheet_url;
    }

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
        [$body, $type] = $this->multipart(['name' => $name, 'mimeType' => self::SHEET_MIME_TYPE], $xlsx);

        $response = Http::withToken($this->google->accessToken($user))
            ->withBody($body, $type)
            ->post(self::UPLOAD_URL.'?uploadType=multipart&fields=id,webViewLink');

        $this->guardAccess($response);

        $file = $response->throw()->json();

        return ['id' => $file['id'], 'url' => $file['webViewLink']];
    }

    /**
     * replace
     *
     * Replace the contents of a Google Sheet the app uploaded before with a
     * new workbook, keeping its id, link and sharing.
     *
     * @param User $user
     * @param string $fileId
     * @param string $name the sheet's title
     * @param string $xlsx the new workbook's bytes
     * @return array{id: string, url: string}
     * @throws AuthenticationException when Google no longer accepts the user's tokens
     * @throws AuthorizationException when the user hasn't granted Google Drive access
     * @throws RequestException
     */
    public function replace(User $user, string $fileId, string $name, string $xlsx): array
    {
        [$body, $type] = $this->multipart(['name' => $name], $xlsx);

        $response = Http::withToken($this->google->accessToken($user))
            ->withBody($body, $type)
            ->patch(self::UPLOAD_URL.'/'.rawurlencode($fileId).'?uploadType=multipart&fields=id,webViewLink');

        $this->guardAccess($response);

        $file = $response->throw()->json();

        return ['id' => $file['id'], 'url' => $file['webViewLink']];
    }

    /**
     * export
     *
     * Download a Google Sheet the app uploaded before as an .xlsx workbook,
     * including whatever people have typed into it since.
     *
     * @param User $user
     * @param string $fileId
     * @return string the workbook's bytes
     * @throws AuthenticationException when Google no longer accepts the user's tokens
     * @throws AuthorizationException when the user hasn't granted Google Drive access
     * @throws RequestException
     */
    public function export(User $user, string $fileId): string
    {
        $response = Http::withToken($this->google->accessToken($user))
            ->get(self::FILES_URL.'/'.rawurlencode($fileId).'/export', ['mimeType' => self::XLSX_MIME_TYPE]);

        $this->guardAccess($response);

        return $response->throw()->body();
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
     * multipart
     *
     * A Drive multipart upload body: the file's metadata, then the workbook.
     *
     * @param array<string, string> $metadata
     * @param string $xlsx
     * @return array{0: string, 1: string} the body and its content type
     */
    private function multipart(array $metadata, string $xlsx): array
    {
        $boundary = Str::random(32);
        $json = json_encode($metadata);

        $body = "--{$boundary}\r\n"
            ."Content-Type: application/json; charset=UTF-8\r\n\r\n{$json}\r\n"
            ."--{$boundary}\r\n"
            .'Content-Type: '.self::XLSX_MIME_TYPE."\r\n\r\n{$xlsx}\r\n"
            ."--{$boundary}--";

        return [$body, "multipart/related; boundary={$boundary}"];
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
