<?php

use App\Enums\IntakeStatus;
use App\Filament\Resources\IntakeItems\IntakeItemResource;
use App\Filament\Resources\IntakeItems\Pages\ListIntakeItems;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use App\Models\IntakeRule;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\FakeGraph;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Sleep::fake();
});

it('shows a member only own items and an admin all, with the user column for admins only', function () {
    $member = User::factory()->create();
    $own = IntakeItem::factory()->for($member)->create();
    $foreign = IntakeItem::factory()->create();

    $this->actingAs($member);
    Livewire::test(ListIntakeItems::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertTableColumnHidden('user.name')
        ->assertTableFilterHidden('user_id');

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(ListIntakeItems::class)
        ->assertCanSeeTableRecords([$own, $foreign])
        ->assertTableColumnVisible('user.name')
        ->assertTableFilterVisible('user_id');
});

it('shows imported and failed items, but no import in progress', function () {
    $user = User::factory()->create();
    $failedImport = IntakeItem::factory()->for($user)->importing()->failed()->create();
    $importing = IntakeItem::factory()->for($user)->importing()->create();
    $imported = IntakeItem::factory()->for($user)->create();

    $this->actingAs($user);
    Livewire::test(ListIntakeItems::class)
        ->filterTable('status', null)
        ->assertCanSeeTableRecords([$failedImport, $imported])
        ->assertCanNotSeeTableRecords([$importing]);
});

it('filters to open items by default', function () {
    $user = User::factory()->create();
    $pending = IntakeItem::factory()->for($user)->create();
    $failed = IntakeItem::factory()->for($user)->failed()->create();
    $discarded = IntakeItem::factory()->for($user)->processed(IntakeStatus::Discarded)->create();

    $this->actingAs($user);
    Livewire::test(ListIntakeItems::class)
        ->assertTableFilterExists('status')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$failed, $discarded])
        ->filterTable('status', IntakeStatus::Failed->value)
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$pending]);
});

it('counts attachments without inline images and cloud links, and shows the pre-selected list', function () {
    $user = User::factory()->create();
    $user->forceFill(['last_clickup_list_label' => 'Intern / Zuletzt'])->save();
    $byRule = IntakeItem::factory()->for($user)->create(['from_email' => 'erika@kunde.test']);
    $byLast = IntakeItem::factory()->for($user)->create(['from_email' => 'max@other.test']);
    IntakeRule::factory()->create(['match_value' => 'kunde.test', 'clickup_list_label' => 'Kunden / Projekte']);
    IntakeAttachment::factory()->count(2)->for($byRule, 'item')->create();
    IntakeAttachment::factory()->inline()->for($byRule, 'item')->create();
    IntakeAttachment::factory()->for($byRule, 'item')->create(['type' => 'reference']);

    $this->actingAs($user);
    Livewire::test(ListIntakeItems::class)
        ->assertTableColumnStateSet('listed_attachments_count', 2, $byRule)
        ->assertTableColumnStateSet('listed_attachments_count', 0, $byLast)
        ->assertTableColumnStateSet('preselected_list', 'Kunden / Projekte', $byRule)
        ->assertTableColumnStateSet('preselected_list', 'Intern / Zuletzt', $byLast)
        ->assertTableColumnFormattedStateSet('status', 'Offen', $byRule);
});

it('loads the table without the mail bodies and without a query per row', function () {
    $admin = User::factory()->admin()->create();
    IntakeItem::factory()->count(8)->create();
    IntakeRule::factory()->count(3)->create();

    $this->actingAs($admin);
    DB::enableQueryLog();
    Livewire::test(ListIntakeItems::class)->assertCanSeeTableRecords(IntakeItem::all());
    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->filter(fn (string $q): bool => str_contains($q, 'from "intake_items"') && ! str_contains($q, 'count(*) as aggregate'))->implode("\n"))
        ->not->toContain('body_html')
        ->not->toContain('"intake_items".*')
        ->and($queries->filter(fn (string $q): bool => str_contains($q, 'from "intake_rules"'))->count())->toBe(1)
        ->and($queries->filter(fn (string $q): bool => str_contains($q, 'from "users"'))->count())->toBeLessThanOrEqual(3);
});

it('discards only the selected items the user may process', function () {
    $user = User::factory()->connected()->create();
    $open = IntakeItem::factory()->for($user)->create(['graph_message_id' => 'msg-1']);
    $failed = IntakeItem::factory()->for($user)->failed()->create(['graph_message_id' => 'msg-2']);
    $creating = IntakeItem::factory()->for($user)->create(['status' => IntakeStatus::Creating]);
    FakeGraph::fake([
        'GET me/messages/*' => Http::response(['categories' => ['ClickUp']]),
        'PATCH me/messages/*' => fn (Request $r) => Http::response(['categories' => $r['categories']]),
    ]);

    $this->actingAs($user);
    Livewire::test(ListIntakeItems::class)
        ->filterTable('status', null)
        ->callTableBulkAction('discard', [$open, $failed, $creating]);

    expect($open->fresh()->status)->toBe(IntakeStatus::Discarded)
        ->and($open->fresh()->processed_by)->toBe($user->id)
        ->and($open->fresh()->outlook_category_synced_at)->not->toBeNull()
        ->and($failed->fresh()->status)->toBe(IntakeStatus::Discarded)
        ->and($creating->fresh()->status)->toBe(IntakeStatus::Creating);
    Http::assertSentCount(4);
});

it('cannot reach a foreign item through the bulk action', function () {
    $member = User::factory()->create();
    $foreign = IntakeItem::factory()->create();
    Http::fake();

    $this->actingAs($member);
    Livewire::test(ListIntakeItems::class)->callTableBulkAction('discard', [$foreign]);

    expect($foreign->fresh()->status)->toBe(IntakeStatus::Pending);
    Http::assertNothingSent();
});

it('offers no create, edit or delete', function () {
    expect(IntakeItemResource::hasPage('create'))->toBeFalse()
        ->and(IntakeItemResource::hasPage('edit'))->toBeFalse();

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(ListIntakeItems::class)
        ->assertActionDoesNotExist('create')
        ->assertTableBulkActionDoesNotExist('delete');
});

it('serves the inbox page to members and admins', function () {
    IntakeItem::factory()->create();

    $this->actingAs(User::factory()->create())->get(IntakeItemResource::getUrl('index'))->assertOk()->assertSee('Eingang');
    $this->actingAs(User::factory()->admin()->create())->get(IntakeItemResource::getUrl('index'))->assertOk();
});
