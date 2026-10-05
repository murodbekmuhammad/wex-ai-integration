<?php

namespace Database\Factories;

use App\Models\AgentRun;
use App\Models\AgentSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @class AgentSettingFactory
 *
 * @package Database\Factories
 *
 * @extends Factory<AgentSetting>
 */
class AgentSettingFactory extends Factory
{
    /**
     * definition
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'agent_key' => 'invoice_aging',
            'sheet_mode' => AgentRun::SHEET_EXISTING,
            'pdf_document_ids' => null,
        ];
    }

    /**
     * newSheet
     *
     * Every run puts its report in a new Google Sheet.
     *
     * @return static
     */
    public function newSheet(): static
    {
        return $this->state(fn () => ['sheet_mode' => AgentRun::SHEET_NEW]);
    }
}
