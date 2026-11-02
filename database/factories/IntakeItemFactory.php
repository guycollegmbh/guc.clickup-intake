<?php

namespace Database\Factories;

use App\Enums\IntakeStatus;
use App\Models\IntakeItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntakeItem>
 */
class IntakeItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'mailbox_upn' => fn (array $attributes): string => User::query()->findOrFail($attributes['user_id'])->mailbox_upn,
            'graph_message_id' => 'AAMk'.Str::random(40),
            'internet_message_id' => '<'.Str::uuid().'@mail.example.test>',
            'subject' => fake()->sentence(4),
            'from_name' => fake()->name(),
            'from_email' => fake()->unique()->userName().'@example.test',
            'to_json' => [['name' => 'Team', 'address' => 'team@guycolle.test']],
            'cc_json' => [],
            'received_at' => now()->subHour(),
            'web_link' => 'https://outlook.office365.com/owa/?ItemID='.Str::random(20),
            'body_html' => '<p>'.fake()->sentence().'</p>',
            'body_text' => fake()->sentence(),
            'status' => IntakeStatus::Pending,
            'imported_at' => now()->subHour(),
        ];
    }

    /**
     * Import still running: not visible in triage yet.
     */
    public function importing(): static
    {
        return $this->state(fn (): array => ['imported_at' => null]);
    }

    /**
     * Finished item whose Outlook category reflects the result.
     */
    public function processed(IntakeStatus $status, ?CarbonInterface $at = null): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'processed_at' => $at ?? now(),
            'outlook_category_synced_at' => $at ?? now(),
        ]);
    }

    public function failed(string $error = 'Fehler beim Import.'): static
    {
        return $this->state(fn (): array => [
            'status' => IntakeStatus::Failed,
            'error' => $error,
        ]);
    }
}
