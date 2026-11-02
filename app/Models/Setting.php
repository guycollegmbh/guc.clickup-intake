<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Key/value store for configuration written at runtime (setup assistant).
 * Values are always encrypted with APP_KEY.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->first()?->value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
