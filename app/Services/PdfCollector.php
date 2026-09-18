<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @class PdfCollector
 *
 * @package App\Services
 *
 * Collects the PDF attachments a set of senders mailed, storing each file on
 * the local disk so Claude can analyse it later.
 */
class PdfCollector
{
    /**
     * Attachments larger than this are skipped: Claude accepts at most 32 MB
     * per request, so a single huge file could never be analysed anyway.
     */
    public const MAX_FILE_SIZE = 20 * 1024 * 1024;

    /**
     * __construct
     *
     * @param GmailService $gmail
     */
    public function __construct(private GmailService $gmail) {}

    /**
     * collect
     *
     * Search Gmail for messages from the given senders that carry a PDF and
     * arrived within the date range, then download and store any attachment
     * that isn't collected yet. Messages whose attachments are already stored
     * are not fetched again.
     *
     * @param User $user
     * @param array<int, string> $senders bare sender addresses; empty means any sender
     * @param CarbonInterface $from earliest arrival time
     * @param CarbonInterface $until latest arrival time
     * @param int $limit how many matching messages to scan
     * @return int number of PDFs newly stored
     * @throws AuthenticationException
     */
    public function collect(User $user, array $senders, CarbonInterface $from, CarbonInterface $until, int $limit): int
    {
        $gmailIds = $this->gmail->search($user, $this->query($senders, $from, $until), $limit);

        $collected = $user->pdfDocuments()->whereIn('gmail_id', $gmailIds)->pluck('gmail_id')->all();
        $pending = array_values(array_diff($gmailIds, $collected));

        if (! $pending) {
            return 0;
        }

        $stored = 0;

        foreach ($this->gmail->messages($user, $pending) as $message) {
            $stored += $this->store($user, $message);
        }

        return $stored;
    }

    /**
     * query
     *
     * Build the Gmail search query for PDF-carrying mail from these senders
     * within the date range. Gmail reads plain dates in Pacific time, so the
     * range is given as Unix timestamps to keep it exact.
     *
     * @param array<int, string> $senders
     * @param CarbonInterface $from
     * @param CarbonInterface $until
     * @return string
     */
    public function query(array $senders, CarbonInterface $from, CarbonInterface $until): string
    {
        $query = sprintf(
            'has:attachment filename:pdf -in:drafts after:%d before:%d',
            $from->getTimestamp() - 1,
            $until->getTimestamp() + 1,
        );

        if ($senders) {
            $query = 'from:('.implode(' OR ', $senders).') '.$query;
        }

        return $query;
    }

    /**
     * store
     *
     * Download every PDF part of one message and record it.
     *
     * @param User $user
     * @param array $message
     * @return int number of PDFs stored for this message
     * @throws AuthenticationException
     */
    private function store(User $user, array $message): int
    {
        $headers = collect($message['payload']['headers'] ?? [])
            ->mapWithKeys(fn (array $header) => [strtolower($header['name']) => $header['value']]);

        $from = (string) $headers->get('from', '');
        preg_match('/[\w.+\'-]+@[\w-]+(?:\.[\w-]+)+/', $from, $address);

        $stored = 0;

        foreach ($this->pdfParts($message['payload'] ?? []) as $part) {
            // Gmail reports the decoded size, so oversized files are skipped without downloading them.
            if ($part['size'] > self::MAX_FILE_SIZE) {
                continue;
            }

            $bytes = $this->gmail->attachment($user, $message['id'], $part['attachment_id']);

            if ($bytes === '' || strlen($bytes) > self::MAX_FILE_SIZE) {
                continue;
            }

            $path = "pdfs/{$user->id}/{$message['id']}-{$part['part_id']}.pdf";
            Storage::disk()->put($path, $bytes);

            $user->pdfDocuments()->updateOrCreate(
                ['gmail_id' => $message['id'], 'part_id' => $part['part_id']],
                [
                    'filename' => Str::limit($part['filename'], 250, ''),
                    'sender' => Str::limit($from, 250),
                    'sender_email' => strtolower($address[0] ?? ''),
                    'subject' => (string) $headers->get('subject', ''),
                    'size' => strlen($bytes),
                    'path' => $path,
                    'sent_at' => Carbon::createFromTimestampMs($message['internalDate']),
                ],
            );

            $stored++;
        }

        return $stored;
    }

    /**
     * pdfParts
     *
     * Depth-first search of the MIME tree for downloadable PDF attachments.
     *
     * @param array $part
     * @return array<int, array{part_id: string, filename: string, attachment_id: string, size: int}>
     */
    private function pdfParts(array $part): array
    {
        $filename = $part['filename'] ?? '';
        $isPdf = ($part['mimeType'] ?? '') === 'application/pdf' || Str::endsWith(strtolower($filename), '.pdf');

        if ($isPdf && isset($part['body']['attachmentId'])) {
            return [[
                'part_id' => (string) ($part['partId'] ?? '0'),
                'filename' => $filename ?: 'attachment.pdf',
                'attachment_id' => $part['body']['attachmentId'],
                'size' => (int) ($part['body']['size'] ?? 0),
            ]];
        }

        $found = [];

        foreach ($part['parts'] ?? [] as $child) {
            $found = array_merge($found, $this->pdfParts($child));
        }

        return $found;
    }
}
