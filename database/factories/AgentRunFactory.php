<?php

namespace Database\Factories;

use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @class AgentRunFactory
 *
 * @package Database\Factories
 *
 * @extends Factory<AgentRun>
 */
class AgentRunFactory extends Factory
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
            'status' => 'completed',
            'summary' => 'Updated the aging report: 12 invoices, $4,500.00 open.',
            'google_sheet_url' => 'https://docs.google.com/spreadsheets/d/'.fake()->uuid(),
            'steps' => [
                ['tool' => 'create_aging_report', 'label' => 'Reading the aging report and updating the Google Sheet…', 'ok' => true, 'text' => 'Updated “Aging report”: 12 invoices, $4,500.00 open.'],
            ],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
