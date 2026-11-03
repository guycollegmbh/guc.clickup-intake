<?php

use App\Enums\RuleMatchType;
use App\Filament\Resources\IntakeRules\IntakeRuleResource;
use App\Filament\Resources\IntakeRules\Pages\ManageIntakeRules;
use App\Models\IntakeRule;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeClickUp;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    configureClickUpApp();
});

function ruleAdmin(): User
{
    FakeClickUp::fake(FakeClickUp::workspaceRoutes());

    return User::factory()->admin()->clickUpConnected('admin-token')->create()->fresh();
}

it('is for active admins only', function () {
    $this->actingAs(User::factory()->create())->get(IntakeRuleResource::getUrl('index'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->inactive()->create())->get(IntakeRuleResource::getUrl('index'))->assertForbidden();
    $this->actingAs(ruleAdmin())->get(IntakeRuleResource::getUrl('index'))->assertOk();
});

it('lists the rules by priority with list label and assignee names', function () {
    $this->actingAs(ruleAdmin());
    $second = IntakeRule::factory()->create(['priority' => 2, 'match_value' => 'zwei.test']);
    $first = IntakeRule::factory()->create(['priority' => 1, 'match_value' => 'eins.test', 'clickup_list_label' => 'Kunden / Allgemein', 'assignee_ids' => [501, 999]]);

    Livewire::test(ManageIntakeRules::class)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true)
        ->assertSee('Kunden / Allgemein')
        ->assertSee('Ada Admin')
        ->assertSee('#999');
});

it('creates a rule with the list label from the hierarchy and puts it last', function () {
    $this->actingAs(ruleAdmin());
    IntakeRule::factory()->create(['priority' => 4]);

    Livewire::test(ManageIntakeRules::class)
        ->callAction('create', data: [
            'match_type' => RuleMatchType::FromDomain->value,
            'match_value' => 'kunde.test',
            'clickup_list_id' => '901',
            'assignee_ids' => ['501', '502'],
            'active' => true,
        ])
        ->assertHasNoActionErrors();

    $rule = IntakeRule::query()->where('match_value', 'kunde.test')->sole();
    expect($rule->priority)->toBe(5)
        ->and($rule->clickup_list_id)->toBe('901')
        ->and($rule->clickup_list_label)->toBe('Kunden / Projekte / Website')
        ->and($rule->assignee_ids)->toBe([501, 502]);
});

it('validates domain and address by match type', function (RuleMatchType $type, string $value, bool $valid) {
    $this->actingAs(ruleAdmin());

    $page = Livewire::test(ManageIntakeRules::class)
        ->callAction('create', data: ['match_type' => $type->value, 'match_value' => $value, 'clickup_list_id' => '902']);

    $valid ? $page->assertHasNoActionErrors() : $page->assertHasActionErrors(['match_value']);
})->with([
    [RuleMatchType::FromDomain, 'kunde.ch', true],
    [RuleMatchType::FromDomain, '@kunde.ch', true],
    [RuleMatchType::FromDomain, 'mail.kunde.co.uk', true],
    [RuleMatchType::FromDomain, 'erika@kunde.ch', false],
    [RuleMatchType::FromDomain, 'https://kunde.ch', false],
    [RuleMatchType::FromDomain, 'kunde', false],
    [RuleMatchType::FromEmail, 'erika@kunde.ch', true],
    [RuleMatchType::FromEmail, 'kunde.ch', false],
    [RuleMatchType::SubjectContains, 'Offerte: Website', true],
]);

it('accepts only lists from the hierarchy of the admin', function () {
    $this->actingAs(ruleAdmin());

    Livewire::test(ManageIntakeRules::class)
        ->callAction('create', data: ['match_type' => 'from_email', 'match_value' => 'a@b.ch', 'clickup_list_id' => '12345'])
        ->assertHasActionErrors(['clickup_list_id']);
});

it('keeps the stored label when the list is unchanged but no longer in the hierarchy', function () {
    $this->actingAs(ruleAdmin());
    $rule = IntakeRule::factory()->create(['clickup_list_id' => '901', 'clickup_list_label' => 'Alt / Label']);

    expect(IntakeRuleResource::withListLabel(['clickup_list_id' => '777'], $rule)['clickup_list_label'])->toBe('777')
        ->and(IntakeRuleResource::withListLabel(['clickup_list_id' => '901'], $rule)['clickup_list_label'])->toBe('Kunden / Projekte / Website');

    $rule->forceFill(['clickup_list_id' => '777'])->save();
    expect(IntakeRuleResource::withListLabel(['clickup_list_id' => '777'], $rule)['clickup_list_label'])->toBe('Alt / Label');
});

it('edits a rule and updates the list label', function () {
    $this->actingAs(ruleAdmin());
    $rule = IntakeRule::factory()->create(['match_type' => 'from_domain', 'match_value' => 'alt.test', 'clickup_list_id' => '901', 'assignee_ids' => [501]]);

    Livewire::test(ManageIntakeRules::class)
        ->callAction(TestAction::make('edit')->table($rule), data: ['clickup_list_id' => '902', 'match_value' => 'neu.test'])
        ->assertHasNoActionErrors();

    expect($rule->fresh()->clickup_list_label)->toBe('Kunden / Allgemein')
        ->and($rule->fresh()->match_value)->toBe('neu.test');
});

it('reorders the rules by dragging', function () {
    $this->actingAs(ruleAdmin());
    $a = IntakeRule::factory()->create(['priority' => 1]);
    $b = IntakeRule::factory()->create(['priority' => 2]);
    $c = IntakeRule::factory()->create(['priority' => 3]);

    Livewire::test(ManageIntakeRules::class)
        ->call('reorderTable', [(string) $c->id, (string) $a->id, (string) $b->id]);

    expect([$c->fresh()->priority, $a->fresh()->priority, $b->fresh()->priority])->toBe([1, 2, 3]);
});

it('shows a hint instead of the list select without ClickUp connection', function () {
    Http::fake();
    $this->actingAs(User::factory()->admin()->create()->fresh());

    Livewire::test(ManageIntakeRules::class)
        ->mountAction('create')
        ->assertFormFieldHidden('clickup_list_id', 'mountedActionSchema0')
        ->assertFormFieldHidden('assignee_ids', 'mountedActionSchema0');

    Http::assertNothingSent();
});

it('deletes a rule', function () {
    $this->actingAs(ruleAdmin());
    $rule = IntakeRule::factory()->create();

    Livewire::test(ManageIntakeRules::class)->callAction(TestAction::make('delete')->table($rule));

    expect(IntakeRule::query()->count())->toBe(0);
});
