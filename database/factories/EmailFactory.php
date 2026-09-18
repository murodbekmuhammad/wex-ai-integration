<?php

namespace Database\Factories;

use App\Models\Email;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @class EmailFactory
 *
 * @package Database\Factories
 *
 * @extends Factory<Email>
 */
class EmailFactory extends Factory
{
    /**
     * definition
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $senderEmail = fake()->safeEmail();

        return [
            'user_id' => User::factory(),
            'gmail_id' => fake()->unique()->regexify('[0-9a-f]{16}'),
            'sender' => fake()->name().' <'.$senderEmail.'>',
            'sender_email' => $senderEmail,
            'receivers' => [fake()->safeEmail()],
            'subject' => fake()->sentence(6),
            'snippet' => fake()->sentence(15),
            'sent_at' => fake()->dateTimeBetween('-3 months'),
        ];
    }
}
