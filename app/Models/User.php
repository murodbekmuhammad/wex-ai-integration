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

    /**
     * agentRuns
     *
     * The saved results of this user's agent runs.
     *
     * @return HasMany<AgentRun, $this>
     */
    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /**
     * agentSettings
     *
     * How this user's agents run, one row per agent that has saved settings.
     *
     * @return HasMany<AgentSetting, $this>
     */
    public function agentSettings(): HasMany
    {
        return $this->hasMany(AgentSetting::class);
    }

    /**
     * agentSettingFor
     *
     * How one of this user's agents runs; the defaults when none are saved.
     *
     * @param string $agentKey an agent key from config/agents.php
     * @return AgentSetting
     */
    public function agentSettingFor(string $agentKey): AgentSetting
    {
        return $this->agentSettings()->firstWhere('agent_key', $agentKey)
            ?? new AgentSetting(['agent_key' => $agentKey]);
    }
}
