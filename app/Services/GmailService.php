<?php

namespace App\Services;

use App\Models\Email;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ValueError;

/**
 * @class GmailService
 *
 * @package App\Services
 *
 * Reads the user's mailbox through the Gmail REST API.
 */
class GmailService
{
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    /**
     * Messages fetched in parallel per batch. Gmail allows ~50 message reads per
     * second per user, so batches of 20 stay under that.
     */
    private const BATCH_SIZE = 20;

    /**
     * Gmail wants repeated "metadataHeaders=X" params, which Laravel's query
     * array encoding can't produce, so the header request is spelled out here.
     */
    private const METADATA_QUERY = 'format=metadata&metadataHeaders=From&metadataHeaders=To&metadataHeaders=Cc&metadataHeaders=Subject';

    /**
     * __construct
     *
     * @param GoogleAuth $google
     */
    public function __construct(private GoogleAuth $google) {}

    /**
     * sync
     *
     * Store the headers of the user's newest messages locally. Messages that
     * are already stored are skipped, so repeat syncs only fetch new mail.
     *
     * @param User $user
     * @param int $limit
     * @return int number of new messages stored
     * @throws AuthenticationException
     */
    public function sync(User $user, int $limit): int
    {
        $token = $this->google->accessToken($user);

        $ids = $this->get($token, '/messages', ['maxResults' => $limit, 'q' => '-in:drafts'])['messages'] ?? [];
        $ids = array_column($ids, 'id');

        $known = $user->emails()->whereIn('gmail_id', $ids)->pluck('gmail_id')->all();
        $new = array_values(array_diff($ids, $known));

        $stored = 0;

        foreach (array_chunk($new, self::BATCH_SIZE) as $batch) {
            $rows = array_map(fn (array $message) => $this->toRow($user, $message), $this->fetch($token, $batch, self::METADATA_QUERY));

            if ($rows) {
                Email::upsert($rows, ['user_id', 'gmail_id'], ['sender', 'sender_email', 'receivers', 'subject', 'snippet', 'sent_at']);
                $stored += count($rows);
            }
        }

        return $stored;
    }

    /**
     * search
     *
     * Return the ids of the messages matching a Gmail search query, newest first.
     *
     * @param User $user
     * @param string $query
     * @param int $limit
     * @return array<int, string>
     * @throws AuthenticationException
     */
    public function search(User $user, string $query, int $limit): array
    {
        $found = $this->get($this->google->accessToken($user), '/messages', [
            'maxResults' => $limit,
            'q' => $query,
        ])['messages'] ?? [];

        return array_column($found, 'id');
    }

    /**
     * messages
     *
     * Fetch several full messages, including their MIME structure.
     *
     * @param User $user
     * @param array<int, string> $gmailIds
     * @return array<int, array>
     * @throws AuthenticationException
     */
    public function messages(User $user, array $gmailIds): array
    {
        $token = $this->google->accessToken($user);
        $messages = [];

        foreach (array_chunk($gmailIds, self::BATCH_SIZE) as $batch) {
            $messages = array_merge($messages, $this->fetch($token, $batch, 'format=full'));
        }

        return $messages;
    }

    /**
     * attachment
     *
     * Download one attachment and return its decoded bytes.
     *
     * @param User $user
     * @param string $gmailId
     * @param string $attachmentId
     * @return string
     * @throws AuthenticationException
     */
    public function attachment(User $user, string $gmailId, string $attachmentId): string
    {
        $attachment = $this->get(
            $this->google->accessToken($user),
            "/messages/{$gmailId}/attachments/{$attachmentId}",
        );

        return (string) base64_decode(strtr($attachment['data'] ?? '', '-_', '+/'));
    }

    /**
     * body
     *
     * Fetch the full message from Gmail and return its HTML and/or plain-text body.
     *
     * @param User $user
     * @param string $gmailId
     * @return array{html: string|null, text: string|null}
     * @throws AuthenticationException
     */
    public function body(User $user, string $gmailId): array
    {
        $message = $this->get($this->google->accessToken($user), "/messages/{$gmailId}", ['format' => 'full']);

        return [
            'html' => $this->findPart($message['payload'] ?? [], 'text/html'),
            'text' => $this->findPart($message['payload'] ?? [], 'text/plain'),
        ];
    }

    /**
     * get
     *
     * GET a Gmail API endpoint and return the decoded JSON.
     *
     * @param string $token
     * @param string $path
     * @param array $query
     * @return array
     * @throws AuthenticationException when Google rejects the token
     */
    private function get(string $token, string $path, array $query = []): array
    {
        $response = Http::withToken($token)->get(self::API.$path, $query);

        if ($response->status() === 401) {
            throw new AuthenticationException('Google access was revoked. Please sign in again.');
        }

        return $response->throw()->json();
    }

    /**
     * fetch
     *
     * Fetch several messages in parallel. Requests that fail (usually rate
     * limiting) are retried a couple of times; anything still missing is
     * simply picked up by the next run.
     *
     * @param string $token
     * @param array<int, string> $ids
     * @param string $query raw query string, e.g. "format=full"
     * @return array<int, array>
     */
    private function fetch(string $token, array $ids, string $query): array
    {
        $messages = [];

        for ($attempt = 1; $ids && $attempt <= 3; $attempt++) {
            if ($attempt > 1) {
                sleep($attempt - 1);
            }

            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $id) => $pool->as($id)->withToken($token)->get(self::API."/messages/{$id}?{$query}"),
                $ids,
            ));

            $retry = [];

            foreach ($responses as $id => $response) {
                if ($response instanceof Response && $response->successful()) {
                    $messages[] = $response->json();
                } elseif (! ($response instanceof Response && $response->notFound())) {
                    $retry[] = $id;
                }
            }

            $ids = $retry;
        }

        return $messages;
    }

    /**
     * toRow
     *
     * Turn a Gmail metadata message into a row for the emails table.
     *
     * @param User $user
     * @param array $message
     * @return array<string, mixed>
     */
    private function toRow(User $user, array $message): array
    {
        $headers = collect($message['payload']['headers'] ?? [])
            ->mapWithKeys(fn (array $header) => [strtolower($header['name']) => $header['value']]);

        return [
            'user_id' => $user->id,
            'gmail_id' => $message['id'],
            'sender' => Str::limit($headers->get('from', ''), 250),
            'sender_email' => $this->addresses($headers->get('from', ''))[0] ?? '',
            // upsert() skips model casts, so encode the JSON column by hand.
            'receivers' => json_encode($this->addresses($headers->get('to', '').','.$headers->get('cc', ''))),
            'subject' => $headers->get('subject', ''),
            'snippet' => html_entity_decode($message['snippet'] ?? '', ENT_QUOTES | ENT_HTML5),
            'sent_at' => Carbon::createFromTimestampMs($message['internalDate']),
        ];
    }

    /**
     * addresses
     *
     * Pull the bare email addresses out of an address header such as
     * `"Doe, Jane" <jane@example.com>, bob@example.com`.
     *
     * @param string $header
     * @return array<int, string>
     */
    private function addresses(string $header): array
    {
        preg_match_all('/[\w.+\'-]+@[\w-]+(?:\.[\w-]+)+/', $header, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[0])));
    }

    /**
     * findPart
     *
     * Depth-first search of the MIME tree for the first inline part of the given type.
     *
     * @param array $part
     * @param string $mimeType
     * @return string|null
     */
    private function findPart(array $part, string $mimeType): ?string
    {
        if (($part['mimeType'] ?? '') === $mimeType && empty($part['filename']) && isset($part['body']['data'])) {
            return $this->toUtf8(base64_decode(strtr($part['body']['data'], '-_', '+/')), $part);
        }

        foreach ($part['parts'] ?? [] as $child) {
            if (($found = $this->findPart($child, $mimeType)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * toUtf8
     *
     * Convert a decoded body to UTF-8 using the charset from its Content-Type.
     *
     * @param string $data
     * @param array $part
     * @return string
     */
    private function toUtf8(string $data, array $part): string
    {
        if (mb_check_encoding($data, 'UTF-8')) {
            return $data;
        }

        $contentType = collect($part['headers'] ?? [])->firstWhere(fn (array $h) => strcasecmp($h['name'], 'Content-Type') === 0);
        preg_match('/charset="?([\w.:-]+)/i', $contentType['value'] ?? '', $matches);

        try {
            return mb_convert_encoding($data, 'UTF-8', $matches[1] ?? 'ISO-8859-1');
        } catch (ValueError) {
            return mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
        }
    }
}
