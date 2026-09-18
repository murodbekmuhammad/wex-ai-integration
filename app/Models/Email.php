<?php

namespace App\Models;

use Database\Factories\EmailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @class Email
 *
 * @package App\Models
 */
#[Fillable(['gmail_id', 'sender', 'sender_email', 'receivers', 'subject', 'snippet', 'sent_at'])]
#[Hidden(['user_id'])]
class Email extends Model
{
    /** @use HasFactory<EmailFactory> */
    use HasFactory;

    /**
     * casts
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'receivers' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * user
     *
     * The user whose mailbox this email belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
