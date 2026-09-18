<?php

namespace Database\Factories;

use App\Models\ReportTable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @class ReportTableFactory
 *
 * @package Database\Factories
 *
 * @extends Factory<ReportTable>
 */
class ReportTableFactory extends Factory
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
            'title' => 'Revenue by branch',
            'request' => 'Make a table of revenue by branch',
            'summary' => 'Revenue for each branch across the reports.',
            'columns' => ['Branch', 'Revenue (UZS)', 'Source'],
            'rows' => [
                ['Tashkent', 1200, 'march.pdf'],
                ['Samarkand', 800, 'april.pdf'],
            ],
            'warnings' => [],
            'pdf_document_ids' => [],
        ];
    }
}
