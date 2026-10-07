<?php

namespace App\Models;

use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @class AgentRun
 *
 * @package App\Models
 *
 * The saved result of one run of a configured agent: its summary, the
 * Google Sheet it wrote to and the steps it took.
 */
#[Fillable(['agent_key', 'sheet_mode', 'status', 'summary', 'error', 'google_sheet_url', 'steps', 'started_at', 'finished_at'])]
#[Hidden(['user_id', 'error'])]
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    /**
     * The run's report updated the agent's existing Google Sheet.
     */
    public const SHEET_EXISTING = 'existing';

    /**
     * The run's report went to a new Google Sheet.
     */
    public const SHEET_NEW = 'new';

    /**
     * casts
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * user
     *
     * The user who ran the agent.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
