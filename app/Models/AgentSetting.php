<?php

namespace App\Models;

use Database\Factories\AgentSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @class AgentSetting
 *
 * @package App\Models
 *
 * How a user's agents run: which Google Sheet the report goes to and which
 * invoice aging PDFs the agent may use. A user without a saved row gets the
 * defaults: the existing sheet and all PDFs.
 */
#[Fillable(['sheet_mode', 'pdf_document_ids'])]
#[Hidden(['id', 'user_id', 'created_at', 'updated_at'])]
class AgentSetting extends Model
{
    /** @use HasFactory<AgentSettingFactory> */
    use HasFactory;

    /**
     * The settings of a user who hasn't saved any.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sheet_mode' => AgentRun::SHEET_EXISTING,
        'pdf_document_ids' => null,
    ];

    /**
     * casts
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pdf_document_ids' => 'array',
        ];
    }

    /**
     * user
     *
     * The user these settings belong to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
