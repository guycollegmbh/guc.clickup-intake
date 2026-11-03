<?php

namespace Database\Factories;

use App\Enums\RuleMatchType;
use App\Models\IntakeRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeRule>
 */
class IntakeRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'priority' => fake()->numberBetween(1, 100),
            'match_type' => RuleMatchType::FromDomain,
            'match_value' => fake()->domainWord().'.test',
            'clickup_list_id' => (string) fake()->numberBetween(900000000, 999999999),
            'clickup_list_label' => 'Space / Folder / List',
            'assignee_ids' => [fake()->numberBetween(1000000, 9999999)],
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
