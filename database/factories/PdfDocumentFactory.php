<?php

namespace Database\Factories;

use App\Models\PdfDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @class PdfDocumentFactory
 *
 * @package Database\Factories
 *
 * @extends Factory<PdfDocument>
 */
class PdfDocumentFactory extends Factory
{
    /**
     * definition
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $senderEmail = fake()->unique()->safeEmail();
        $gmailId = fake()->unique()->regexify('[0-9a-f]{16}');

        return [
            'user_id' => User::factory(),
            'gmail_id' => $gmailId,
            'part_id' => '1',
            'filename' => fake()->word().'.pdf',
            'sender' => fake()->name().' <'.$senderEmail.'>',
            'sender_email' => $senderEmail,
            'subject' => fake()->sentence(6),
            'size' => fake()->numberBetween(10_000, 500_000),
            'path' => "pdfs/1/{$gmailId}-1.pdf",
            'sent_at' => fake()->dateTimeBetween('-6 days'),
        ];
    }

    /**
     * invoiceAging
     *
     * A PDF Claude has already tagged as an invoice aging report.
     *
     * @return static
     */
    public function invoiceAging(): static
    {
        return $this->state(fn () => ['report_type' => 'invoice_aging', 'classified_at' => now()]);
    }

    /**
     * reserveAccountDetail
     *
     * A PDF already tagged as a reserve account detail report.
     *
     * @return static
     */
    public function reserveAccountDetail(): static
    {
        return $this->state(fn () => ['report_type' => 'reserve_account_detail', 'classified_at' => now()]);
    }
}
