<?php

namespace App\Intake;

use App\Enums\RuleMatchType;
use App\Models\IntakeItem;
use App\Models\IntakeRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Deterministic pre-selection of list and assignees (R2): the first active rule in
 * IntakeRule::ordered() that matches the item wins.
 *
 * Matching, always without case:
 * - from_domain: the domain of the sender address equals the value exactly. Subdomains are
 *   not included: "example.com" does not match "mail.example.com", that needs its own rule.
 *   A leading "@" in the value is ignored.
 * - from_email: the sender address equals the value.
 * - subject_contains: the subject contains the value.
 * A rule with an empty value never matches.
 *
 * Bound as scoped service: the active rules are loaded once per request or job, also for a
 * table with many rows.
 */
class RuleMatcher
{
    /** @var Collection<int, IntakeRule>|null */
    private ?Collection $rules = null;

    public function match(IntakeItem $item): ?IntakeRule
    {
        return $this->rules()->first(
            fn (IntakeRule $rule): bool => self::matches($rule, $item->from_email, $item->subject),
        );
    }

    public static function matches(IntakeRule $rule, ?string $fromEmail, ?string $subject): bool
    {
        if (! $rule->active) {
            return false;
        }

        $value = Str::lower(trim((string) $rule->match_value));

        if ($value === '') {
            return false;
        }

        $address = Str::lower(trim((string) $fromEmail));

        return match ($rule->match_type) {
            RuleMatchType::FromDomain => $address !== ''
                && str_contains($address, '@')
                && Str::afterLast($address, '@') === ltrim($value, '@'),
            RuleMatchType::FromEmail => $address === $value,
            RuleMatchType::SubjectContains => Str::contains((string) $subject, $value, ignoreCase: true),
        };
    }

    /**
     * @return Collection<int, IntakeRule>
     */
    private function rules(): Collection
    {
        return $this->rules ??= IntakeRule::query()->where('active', true)->ordered()->get();
    }
}
