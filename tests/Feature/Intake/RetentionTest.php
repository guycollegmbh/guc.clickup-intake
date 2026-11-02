<?php

use App\Enums\IntakeStatus;
use App\Intake\AttachmentStore;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

function itemWithFile(IntakeItem $item): IntakeItem
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, 'content');
    rewind($stream);
    app(AttachmentStore::class)->store($item, ['graph_attachment_id' => 'a', 'name' => 'f.pdf', 'size' => 7], $stream);

    return $item;
}

it('deletes finished items after the retention period, including files', function (IntakeStatus $status) {
    $item = itemWithFile(IntakeItem::factory()->processed($status, now()->subDays(31))->create());

    $this->artisan('intake:prune')->expectsOutput('Deleted items: 1')->assertSuccessful();

    expect(IntakeItem::query()->find($item->id))->toBeNull()
        ->and(IntakeAttachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing("intake/{$item->id}");
})->with([IntakeStatus::Created, IntakeStatus::Forwarded, IntakeStatus::Discarded]);

it('keeps finished items within the retention period', function () {
    $item = itemWithFile(IntakeItem::factory()->processed(IntakeStatus::Created, now()->subDays(29))->create());

    $this->artisan('intake:prune')->expectsOutput('Deleted items: 0')->assertSuccessful();

    expect($item->fresh())->not->toBeNull();
    Storage::disk('local')->assertExists("intake/{$item->id}");
});

it('never deletes pending, creating or failed items', function (IntakeStatus $status) {
    $item = IntakeItem::factory()->create();
    $item->forceFill([
        'status' => $status,
        'processed_at' => now()->subDays(90),
        'outlook_category_synced_at' => now()->subDays(90),
        'created_at' => now()->subDays(90),
    ])->save();

    $this->artisan('intake:prune')->assertSuccessful();

    expect($item->fresh())->not->toBeNull();
})->with([IntakeStatus::Pending, IntakeStatus::Creating, IntakeStatus::Failed]);

it('keeps finished items whose Outlook category is not synced', function () {
    $item = IntakeItem::factory()->processed(IntakeStatus::Created, now()->subDays(31))->create();
    $item->forceFill(['outlook_category_synced_at' => null])->save();

    $this->artisan('intake:prune')->expectsOutput('Deleted items: 0')->assertFailed();

    expect($item->fresh())->not->toBeNull();
});

it('reports unsynced items as stuck and fails', function () {
    $stuck = IntakeItem::factory()->processed(IntakeStatus::Created, now()->subDays(31))->create();
    $stuck->forceFill(['outlook_category_synced_at' => null])->save();
    $fresh = IntakeItem::factory()->processed(IntakeStatus::Created, now()->subDays(5))->create();
    $fresh->forceFill(['outlook_category_synced_at' => null])->save();

    $this->artisan('intake:prune')
        ->expectsOutput('Stuck items (Outlook category not synced): 1')
        ->assertFailed();

    expect($stuck->fresh())->not->toBeNull();
});

it('deletes unsynced items after the hard limit and logs ids only', function () {
    Log::spy();
    $item = itemWithFile(IntakeItem::factory()->processed(IntakeStatus::Discarded, now()->subDays(61))->create());
    $item->forceFill(['outlook_category_synced_at' => null])->save();

    $this->artisan('intake:prune')
        ->expectsOutput('Deleted after hard limit: 1')
        ->expectsOutput('Stuck items (Outlook category not synced): 0')
        ->assertFailed();

    expect(IntakeItem::query()->find($item->id))->toBeNull();
    Storage::disk('local')->assertMissing("intake/{$item->id}");
    Log::shouldHaveReceived('warning')->once()->with(
        'Retention hard limit: deleting item without synced Outlook category.',
        ['intake_item_id' => $item->id, 'user_id' => $item->user_id],
    );
});

it('never applies a hard limit shorter than the retention period', function () {
    config(['intake.retention_hard_limit_days' => 10]);
    $item = IntakeItem::factory()->processed(IntakeStatus::Created, now()->subDays(20))->create();
    $item->forceFill(['outlook_category_synced_at' => null])->save();

    $this->artisan('intake:prune')->expectsOutput('Deleted after hard limit: 0');

    expect($item->fresh())->not->toBeNull();
});

it('keeps finished items without processed_at', function () {
    $item = IntakeItem::factory()->create();
    $item->forceFill(['status' => IntakeStatus::Created, 'outlook_category_synced_at' => now()->subDays(40)])->save();

    $this->artisan('intake:prune')->assertSuccessful();

    expect($item->fresh())->not->toBeNull();
});

it('honours the configured retention period', function () {
    config(['intake.retention_days' => 5]);
    $item = IntakeItem::factory()->processed(IntakeStatus::Created, now()->subDays(6))->create();

    $this->artisan('intake:prune')->assertSuccessful();

    expect($item->fresh())->toBeNull();
});

it('fails when the orphans check finds something', function () {
    Storage::disk('local')->put('intake/999/1', 'x');

    $this->artisan('intake:prune')->expectsOutput('Orphans: 1')->assertFailed();
});

it('is scheduled daily without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command, 'intake:prune'));

    expect($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expression)->toBe('30 3 * * *');
});
