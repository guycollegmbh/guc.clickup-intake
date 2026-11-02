<?php

namespace Database\Factories;

use App\Enums\AttachmentType;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Rows only. Files are written by AttachmentStore, use it in tests that need a file.
 *
 * @extends Factory<IntakeAttachment>
 */
class IntakeAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'intake_item_id' => IntakeItem::factory(),
            'type' => AttachmentType::File,
            'graph_attachment_id' => 'AAMk'.Str::random(40),
            'name' => fake()->word().'.pdf',
            'content_type' => 'application/pdf',
            'size' => fake()->numberBetween(1_000, 500_000),
            'content_id' => null,
            'is_inline' => false,
        ];
    }

    public function inline(): static
    {
        return $this->state(fn (): array => [
            'name' => 'image001.png',
            'content_type' => 'image/png',
            'content_id' => 'image001.png@'.Str::random(8),
            'is_inline' => true,
        ]);
    }
}
