<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Database\Factories;

use App\Domains\Notifications\Models\SlackMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Notifications\Notification;

/**
 * @extends Factory<SlackMessage>
 */
class SlackMessageFactory extends Factory
{
    protected $model = SlackMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => fake()->regexify('C[A-Z0-9]{10}'),
            // Slack sends the ts as a string, and the column stores it verbatim.
            'message_ts' => now()->getTimestamp().'.'.fake()->unique()->numerify('######'),
            // The column logs whichever notification was sent; defaulting to the base
            // class keeps this domain from depending on any sender's namespace.
            'type' => Notification::class,
            'content' => fake()->sentence(),
            'sent_at' => now(),
        ];
    }
}
