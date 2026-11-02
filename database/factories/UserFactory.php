<?php

namespace Database\Factories;

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
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => Role::Admin]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
