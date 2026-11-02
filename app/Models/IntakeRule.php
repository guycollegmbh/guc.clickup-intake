<?php

namespace App\Models;

use App\Enums\RuleMatchType;
use Database\Factories\IntakeRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Deterministic pre-selection of list and assignees (R2). Admin only (R12).
 * Rules are checked by ascending priority, the first match wins (step 4).
 */
#[Fillable([
    'priority',
    'match_type',
    'match_value',
    'clickup_list_id',
    'clickup_list_label',
    'assignee_ids',
    'active',
])]
class IntakeRule extends Model
{
    /** @use HasFactory<IntakeRuleFactory> */
    use HasFactory;

    protected $attributes = [
        'priority' => 0,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'match_type' => RuleMatchType::class,
            'assignee_ids' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * Evaluation order: ascending priority, id as tie-breaker.
     *
     * @param  Builder<IntakeRule>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('priority')->orderBy('id');
    }
}
