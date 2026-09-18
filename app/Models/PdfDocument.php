<?php

namespace App\Models;

use Database\Factories\PdfDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @class PdfDocument
 *
 * @package App\Models
 */
#[Fillable(['gmail_id', 'part_id', 'filename', 'sender', 'sender_email', 'subject', 'size', 'path', 'sent_at'])]
#[Hidden(['user_id', 'path'])]
class PdfDocument extends Model
{
    /** @use HasFactory<PdfDocumentFactory> */
    use HasFactory;

    /**
     * casts
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * user
     *
     * The user whose mailbox this PDF was collected from.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * contents
     *
     * The raw bytes of the stored file, or null when it is missing from disk.
     *
     * @return string|null
     */
    public function contents(): ?string
    {
        return Storage::disk()->get($this->path);
    }
}
