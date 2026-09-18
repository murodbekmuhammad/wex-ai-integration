<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @class User
 *
 * @package App\Models
 */
#[Fillable(['google_id', 'name', 'email', 'google_token', 'google_refresh_token', 'google_token_expires_at'])]
#[Hidden(['google_token', 'google_refresh_token', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * casts
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'google_token' => 'encrypted',
            'google_refresh_token' => 'encrypted',
            'google_token_expires_at' => 'datetime',
        ];
    }

    /**
     * emails
     *
     * Gmail messages synced for this user.
     *
     * @return HasMany<Email, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    /**
     * pdfDocuments
     *
     * PDF attachments collected from this user's mailbox.
     *
     * @return HasMany<PdfDocument, $this>
     */
    public function pdfDocuments(): HasMany
    {
        return $this->hasMany(PdfDocument::class);
    }

    /**
     * reportTables
     *
     * Tables Claude built from this user's PDFs.
     *
     * @return HasMany<ReportTable, $this>
     */
    public function reportTables(): HasMany
    {
        return $this->hasMany(ReportTable::class);
    }
}
