<?php

use App\Enums\IntakeStatus;
use App\Intake\ItemTransitions;
use App\Microsoft\GraphException;
use App\Models\IntakeItem;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeGraph;

beforeEach(fn () => Sleep::fake());

function processedItem(IntakeStatus $status): IntakeItem
{
    $user = User::factory()->connected()->create();

    return IntakeItem::factory()->for($user)->processed($status)->create([
        'graph_message_id' => 'msg-1',
        'processed_by' => $user->id,
    ]);
}

it('derives final and processable states from the enum', function () {
    expect(IntakeStatus::finalCases())->toBe([IntakeStatus::Created, IntakeStatus::Forwarded, IntakeStatus::Discarded])
        ->and(array_values(array_filter(IntakeStatus::cases(), fn (IntakeStatus $s): bool => $s->isProcessable())))
        ->toBe([IntakeStatus::Pending, IntakeStatus::Failed]);
});

it('marks a failed import with text, attempt and wait, and resets the category sync', function () {
    $item = IntakeItem::factory()->importing()->create(['outlook_category_synced_at' => now()]);

    expect(app(ItemTransitions::class)->markImportFailed($item, 'Import fehlgeschlagen.'))->toBeTrue();

    expect($item->status)->toBe(IntakeStatus::Failed)
        ->and($item->error)->toBe('Import fehlgeschlagen.')
        ->and($item->imported_at)->toBeNull()
        ->and($item->import_attempts)->toBe(1)
        ->and($item->next_import_at->isFuture())->toBeTrue()
        ->and($item->outlook_category_synced_at)->toBeNull()
        ->and($item->processed_at)->toBeNull();
});

it('does not mark an imported item as failed import', function () {
    $item = IntakeItem::factory()->create();

    expect(app(ItemTransitions::class)->markImportFailed($item, 'x'))->toBeFalse()
        ->and($item->status)->toBe(IntakeStatus::Pending);
});

it('claims an item for import only while it is pending or failed and not imported', function () {
    $failed = IntakeItem::factory()->importing()->failed()->create();
    $discarded = IntakeItem::factory()->importing()->create(['status' => IntakeStatus::Discarded]);
    $imported = IntakeItem::factory()->failed()->create();

    $transitions = app(ItemTransitions::class);

    expect($transitions->claimForImport($failed))->toBeTrue()
        ->and($failed->status)->toBe(IntakeStatus::Pending)
        ->and($failed->error)->toBeNull()
        ->and($transitions->claimForImport($discarded))->toBeFalse()
        ->and($discarded->status)->toBe(IntakeStatus::Discarded)
        ->and($transitions->claimForImport($imported))->toBeFalse();
});

it('does not overwrite a status changed in the meantime', function () {
    $item = IntakeItem::factory()->importing()->create();
    IntakeItem::query()->whereKey($item->id)->update(['status' => IntakeStatus::Discarded->value]);

    // $item is stale and still says pending.
    expect(app(ItemTransitions::class)->markImportFailed($item, 'x'))->toBeFalse()
        ->and($item->status)->toBe(IntakeStatus::Discarded);
});

it('brings a discarded item back to pending', function () {
    $item = processedItem(IntakeStatus::Discarded);

    expect(app(ItemTransitions::class)->backToPending($item))->toBeTrue();

    expect($item->status)->toBe(IntakeStatus::Pending)
        ->and($item->processed_at)->toBeNull()
        ->and($item->processed_by)->toBeNull()
        ->and($item->outlook_category_synced_at)->toBeNull()
        ->and($item->error)->toBeNull();
});

it('reopens only discarded items', function () {
    $item = processedItem(IntakeStatus::Created);

    expect(app(ItemTransitions::class)->backToPending($item))->toBeFalse()
        ->and($item->status)->toBe(IntakeStatus::Created);
});

it('sets the category matching the final status and records the sync', function (IntakeStatus $status, ?string $expected) {
    $item = processedItem($status);
    $item->forceFill(['outlook_category_synced_at' => null])->save();
    FakeGraph::fake([
        'GET me/messages/msg-1?*' => Http::response(['categories' => ['ClickUp', 'Rot']]),
        'PATCH me/messages/msg-1' => fn (Request $r) => Http::response(['categories' => $r['categories']]),
    ]);

    expect(app(ItemTransitions::class)->syncCategory($item))->toBeTrue();

    $categories = $expected === null ? ['Rot'] : ['Rot', $expected];
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && $r['categories'] === $categories);
    expect($item->outlook_category_synced_at)->not->toBeNull();
})->with([
    'created' => [IntakeStatus::Created, 'ClickUp ✓'],
    'forwarded' => [IntakeStatus::Forwarded, 'Support ✓'],
    'discarded' => [IntakeStatus::Discarded, null],
]);

it('removes the category in any spelling', function () {
    $item = processedItem(IntakeStatus::Discarded);
    $item->forceFill(['outlook_category_synced_at' => null])->save();
    FakeGraph::fake([
        'GET me/messages/msg-1?*' => Http::response(['categories' => ['Clickup']]),
        'PATCH me/messages/msg-1' => fn (Request $r) => Http::response(['categories' => $r['categories']]),
    ]);

    expect(app(ItemTransitions::class)->syncCategory($item))->toBeTrue();

    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && $r['categories'] === []);
});

it('records no sync when Outlook still shows the old category after the update', function () {
    $item = processedItem(IntakeStatus::Created);
    $item->forceFill(['outlook_category_synced_at' => null])->save();
    FakeGraph::fake([
        'GET me/messages/msg-1?*' => Http::response(['categories' => ['ClickUp']]),
        'PATCH me/messages/msg-1' => Http::response(['categories' => ['CLICKUP', 'ClickUp ✓']]),
    ]);

    expect(app(ItemTransitions::class)->syncCategory($item))->toBeFalse()
        ->and($item->fresh()->outlook_category_synced_at)->toBeNull();
});

it('does not touch Outlook for an open item', function () {
    Http::fake();
    $item = IntakeItem::factory()->create();

    expect(app(ItemTransitions::class)->syncCategory($item))->toBeFalse();

    Http::assertNothingSent();
    expect($item->fresh()->outlook_category_synced_at)->toBeNull();
});

it('leaves the sync open when the category update fails', function () {
    $item = processedItem(IntakeStatus::Created);
    $item->forceFill(['outlook_category_synced_at' => null])->save();
    FakeGraph::fake(['GET me/messages/msg-1?*' => Http::response([], 500)]);

    expect(fn () => app(ItemTransitions::class)->syncCategory($item))->toThrow(GraphException::class);
    expect($item->fresh()->outlook_category_synced_at)->toBeNull();
});

it('never discards, forwards or fails a partial success with a ClickUp task', function () {
    $item = IntakeItem::factory()->failed('Anhänge fehlen.')->create();
    $item->forceFill(['clickup_task_id' => 'abc123'])->save();
    $actor = User::factory()->create();
    $transitions = app(ItemTransitions::class);

    expect($transitions->discard($item, $actor))->toBeFalse()
        ->and($transitions->markForwarded($item, $actor))->toBeFalse()
        ->and($transitions->markFailed($item, 'x'))->toBeFalse()
        ->and($item->status)->toBe(IntakeStatus::Failed)
        ->and($item->error)->toBe('Anhänge fehlen.');
});

it('marks only imported items as failed in triage', function () {
    $item = IntakeItem::factory()->importing()->failed('Import fehlgeschlagen.')->create();

    expect(app(ItemTransitions::class)->markFailed($item, 'Weiterleitung fehlgeschlagen.'))->toBeFalse()
        ->and($item->error)->toBe('Import fehlgeschlagen.');
});
