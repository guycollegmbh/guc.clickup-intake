<?php

namespace Database\Factories;

use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        $email = fake()->unique()->userName().'@guycolle.test';

        return [
            'name' => fake()->name(),
            'email' => $email,
            'mailbox_upn' => $email,
            'entra_object_id' => (string) Str::uuid(),
            'role' => Role::Member,
            'active' => true,
            // Database defaults, set here so a factory model has them without a reload.
            'ms_connection_status' => ConnectionStatus::Disconnected,
            'clickup_connection_status' => ConnectionStatus::Disconnected,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => Role::Admin]);
    }

    /**
     * Microsoft connection with a valid access token, so no token refresh is needed.
     */
    public function connected(): static
    {
        return $this->state(fn (): array => [
            'ms_connection_status' => ConnectionStatus::Connected,
            'ms_refresh_token' => 'refresh-token-old',
            'ms_access_token' => 'access-token-valid',
            'ms_token_expires_at' => now()->addHour(),
            'ms_connected_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
