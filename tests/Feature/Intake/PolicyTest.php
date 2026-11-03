<?php

use App\Enums\IntakeStatus;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use App\Models\IntakeRule;
use App\Models\User;
use App\Policies\IntakeAttachmentPolicy;
use App\Policies\IntakeItemPolicy;
use App\Policies\IntakeRulePolicy;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

it('lets a member see and edit own items only', function () {
    $member = User::factory()->create();
    $own = IntakeItem::factory()->for($member)->create();
    $foreign = IntakeItem::factory()->create();

    expect($member->can('viewAny', IntakeItem::class))->toBeTrue()
        ->and($member->can('view', $own))->toBeTrue()
        ->and($member->can('update', $own))->toBeTrue()
        ->and($member->can('view', $foreign))->toBeFalse()
        ->and($member->can('update', $foreign))->toBeFalse();
});

it('lets an admin see and edit all items', function () {
    $admin = User::factory()->admin()->create();
    $foreign = IntakeItem::factory()->create();

    expect($admin->can('view', $foreign))->toBeTrue()
        ->and($admin->can('update', $foreign))->toBeTrue();
});

it('lets nobody create or delete items by hand', function () {
    $admin = User::factory()->admin()->create();
    $own = IntakeItem::factory()->for($admin)->create();

    expect($admin->can('create', IntakeItem::class))->toBeFalse()
        ->and($admin->can('delete', $own))->toBeFalse()
        ->and($admin->can('deleteAny', IntakeItem::class))->toBeFalse();
});

it('denies inactive users everything on items', function () {
    $inactiveAdmin = User::factory()->admin()->inactive()->create();
    $inactiveMember = User::factory()->inactive()->create();
    $own = IntakeItem::factory()->for($inactiveMember)->create();

    expect($inactiveAdmin->can('viewAny', IntakeItem::class))->toBeFalse()
        ->and($inactiveAdmin->can('view', $own))->toBeFalse()
        ->and($inactiveMember->can('view', $own))->toBeFalse()
        ->and($inactiveMember->can('update', $own))->toBeFalse();
});

it('allows rules to active admins only', function (string $ability, bool $withModel) {
    $rule = IntakeRule::factory()->create();
    $argument = $withModel ? $rule : IntakeRule::class;

    expect(User::factory()->admin()->create()->can($ability, $argument))->toBeTrue()
        ->and(User::factory()->create()->can($ability, $argument))->toBeFalse()
        ->and(User::factory()->admin()->inactive()->create()->can($ability, $argument))->toBeFalse();
})->with([
    ['viewAny', false],
    ['view', true],
    ['create', false],
    ['update', true],
    ['delete', true],
    ['deleteAny', false],
    ['reorder', false],
]);

it('allows processing only open or failed items of the owner', function (IntakeStatus $status, bool $allowed) {
    $member = User::factory()->create();
    $own = IntakeItem::factory()->for($member)->create();
    $own->forceFill(['status' => $status])->save();
    $foreign = IntakeItem::factory()->create();
    $foreign->forceFill(['status' => $status])->save();

    expect($member->can('process', $own))->toBe($allowed)
        ->and($member->can('process', $foreign))->toBeFalse()
        ->and(User::factory()->admin()->create()->can('process', $foreign))->toBe($allowed)
        ->and(User::factory()->inactive()->create()->can('process', $own))->toBeFalse();
})->with([
    [IntakeStatus::Pending, true],
    [IntakeStatus::Failed, true],
    [IntakeStatus::Creating, false],
    [IntakeStatus::Created, false],
    [IntakeStatus::Forwarded, false],
    [IntakeStatus::Discarded, false],
]);

it('lets attachments follow the view right of their item and nothing else', function () {
    $member = User::factory()->create();
    $own = IntakeAttachment::factory()->for(IntakeItem::factory()->for($member), 'item')->create();
    $foreign = IntakeAttachment::factory()->create();

    expect($member->can('view', $own))->toBeTrue()
        ->and($member->can('view', $foreign))->toBeFalse()
        ->and(User::factory()->admin()->create()->can('view', $foreign))->toBeTrue()
        ->and(User::factory()->inactive()->create()->can('view', $own))->toBeFalse()
        ->and($member->can('update', $own))->toBeFalse()
        ->and($member->can('delete', $own))->toBeFalse()
        ->and($member->can('viewAny', IntakeAttachment::class))->toBeFalse();
});

it('defines every ability Filament asks for under strict authorization', function (string $policy) {
    $abilities = ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny', 'restore', 'restoreAny',
        'forceDelete', 'forceDeleteAny', 'replicate', 'reorder'];

    foreach ($abilities as $ability) {
        expect(method_exists($policy, $ability))->toBeTrue("{$policy}::{$ability} missing");
    }
})->with([IntakeItemPolicy::class, IntakeRulePolicy::class, IntakeAttachmentPolicy::class]);

it('enables strict authorization and still serves the dashboard', function () {
    expect(Filament::getPanel('app')->isAuthorizationStrict())->toBeTrue();

    $this->actingAs(User::factory()->create())->get('/')->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get('/')->assertOk();
});

it('does not serve the local disk over a route', function () {
    expect(Route::has('storage.local'))->toBeFalse();
});

it('treats a failed item with a ClickUp task as partial success, not as processable', function () {
    $member = User::factory()->create();
    $partial = IntakeItem::factory()->for($member)->failed('Anhänge fehlen.')->create(['status' => IntakeStatus::Failed]);
    $partial->forceFill(['clickup_task_id' => 'abc123'])->save();

    expect($partial->isProcessable())->toBeFalse()
        ->and($member->can('process', $partial))->toBeFalse()
        ->and($member->can('forward', $partial))->toBeFalse()
        ->and(IntakeItem::query()->processable()->whereKey($partial->id)->exists())->toBeFalse();
});

it('allows forwarding of imported items only', function () {
    $member = User::factory()->create();
    $failedImport = IntakeItem::factory()->for($member)->importing()->failed()->create();
    $imported = IntakeItem::factory()->for($member)->failed()->create();

    expect($member->can('process', $failedImport))->toBeTrue()
        ->and($member->can('forward', $failedImport))->toBeFalse()
        ->and($member->can('forward', $imported))->toBeTrue();
});
