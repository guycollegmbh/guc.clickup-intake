<?php

use App\Enums\RuleMatchType;
use App\Intake\RuleMatcher;
use App\Models\IntakeItem;
use App\Models\IntakeRule;

function rule(RuleMatchType $type, string $value, array $attributes = []): IntakeRule
{
    return IntakeRule::factory()->create([
        'match_type' => $type,
        'match_value' => $value,
        'clickup_list_label' => "{$type->value}:{$value}",
        ...$attributes,
    ]);
}

function itemFrom(string $fromEmail, string $subject = 'Anfrage'): IntakeItem
{
    return IntakeItem::factory()->make(['from_email' => $fromEmail, 'subject' => $subject]);
}

it('matches the domain of the sender without case', function () {
    $rule = rule(RuleMatchType::FromDomain, 'Example.TEST');

    expect(RuleMatcher::matches($rule, 'erika@example.test', null))->toBeTrue()
        ->and(RuleMatcher::matches($rule, 'ERIKA@EXAMPLE.test', null))->toBeTrue()
        ->and(RuleMatcher::matches($rule, 'erika@example.test.evil', null))->toBeFalse()
        ->and(RuleMatcher::matches($rule, 'example.test@other.test', null))->toBeFalse()
        ->and(RuleMatcher::matches($rule, null, null))->toBeFalse();
});

it('does not include subdomains in a domain rule', function () {
    $rule = rule(RuleMatchType::FromDomain, 'example.test');

    expect(RuleMatcher::matches($rule, 'erika@mail.example.test', null))->toBeFalse()
        ->and(RuleMatcher::matches($rule, 'erika@badexample.test', null))->toBeFalse();
});

it('ignores a leading @ in a domain rule', function () {
    expect(RuleMatcher::matches(rule(RuleMatchType::FromDomain, '@example.test'), 'erika@example.test', null))->toBeTrue();
});

it('matches the sender address exactly without case', function () {
    $rule = rule(RuleMatchType::FromEmail, 'Erika@Example.test');

    expect(RuleMatcher::matches($rule, 'erika@example.test', null))->toBeTrue()
        ->and(RuleMatcher::matches($rule, 'erika@example.test.ch', null))->toBeFalse()
        ->and(RuleMatcher::matches($rule, 'max.erika@example.test', null))->toBeFalse();
});

it('matches a part of the subject without case', function () {
    $rule = rule(RuleMatchType::SubjectContains, 'Rechnung');

    expect(RuleMatcher::matches($rule, null, 'AW: rechnung März'))->toBeTrue()
        ->and(RuleMatcher::matches($rule, null, 'RECHNUNGEN'))->toBeTrue()
        ->and(RuleMatcher::matches($rule, null, 'Offerte'))->toBeFalse()
        ->and(RuleMatcher::matches($rule, null, null))->toBeFalse();
});

it('never matches with an empty value or when inactive', function () {
    expect(RuleMatcher::matches(rule(RuleMatchType::SubjectContains, '  '), null, 'Offerte'))->toBeFalse()
        ->and(RuleMatcher::matches(rule(RuleMatchType::FromDomain, 'example.test', ['active' => false]), 'erika@example.test', null))->toBeFalse();
});

it('takes the first active matching rule by priority, then id', function () {
    rule(RuleMatchType::SubjectContains, 'Offerte', ['priority' => 30, 'clickup_list_label' => 'Betreff']);
    rule(RuleMatchType::FromDomain, 'example.test', ['priority' => 5, 'active' => false, 'clickup_list_label' => 'Inaktiv']);
    rule(RuleMatchType::FromEmail, 'erika@example.test', ['priority' => 10, 'clickup_list_label' => 'Adresse']);
    rule(RuleMatchType::FromDomain, 'example.test', ['priority' => 10, 'clickup_list_label' => 'Domain']);

    expect(app(RuleMatcher::class)->match(itemFrom('erika@example.test', 'Offerte'))?->clickup_list_label)->toBe('Adresse')
        ->and(app(RuleMatcher::class)->match(itemFrom('max@example.test', 'Offerte'))?->clickup_list_label)->toBe('Domain')
        ->and(app(RuleMatcher::class)->match(itemFrom('max@other.test', 'Offerte'))?->clickup_list_label)->toBe('Betreff')
        ->and(app(RuleMatcher::class)->match(itemFrom('max@other.test', 'Rechnung')))->toBeNull();
});

it('prefers a domain rule over an address rule when its priority is higher', function () {
    rule(RuleMatchType::FromEmail, 'erika@example.test', ['priority' => 20, 'clickup_list_label' => 'Adresse']);
    rule(RuleMatchType::FromDomain, 'example.test', ['priority' => 1, 'clickup_list_label' => 'Domain']);

    expect(app(RuleMatcher::class)->match(itemFrom('erika@example.test'))?->clickup_list_label)->toBe('Domain');
});
