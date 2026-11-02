<?php

use App\Enums\AttachmentType;
use App\Enums\IntakeStatus;
use App\Enums\RuleMatchType;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use App\Models\IntakeRule;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;

it('allows the same internet message id for two users', function () {
    $messageId = '<same@mail.example.test>';

    IntakeItem::factory()->create(['internet_message_id' => $messageId]);
    IntakeItem::factory()->create(['internet_message_id' => $messageId]);

    expect(IntakeItem::query()->where('internet_message_id', $messageId)->count())->toBe(2);
});

it('rejects the same internet message id twice for one user', function () {
    $user = User::factory()->create();

    IntakeItem::factory()->for($user)->create(['internet_message_id' => '<same@mail.example.test>']);
    IntakeItem::factory()->for($user)->create(['internet_message_id' => '<same@mail.example.test>']);
})->throws(QueryException::class);

it('dedupes by user, not by mailbox upn', function () {
    $user = User::factory()->create();

    IntakeItem::factory()->for($user)->create(['mailbox_upn' => 'old@guycolle.test', 'internet_message_id' => '<m@x.test>']);
    IntakeItem::factory()->for($user)->create(['mailbox_upn' => 'new@guycolle.test', 'internet_message_id' => '<m@x.test>']);
})->throws(QueryException::class);

it('casts status, type, match type and json fields', function () {
    $item = IntakeItem::factory()->create(['to_json' => [['name' => 'A', 'address' => 'a@x.test']]])->fresh();
    $attachment = IntakeAttachment::factory()->for($item, 'item')->create()->fresh();
    $rule = IntakeRule::factory()->create(['assignee_ids' => [1, 2]])->fresh();

    expect($item->status)->toBe(IntakeStatus::Pending)
        ->and($item->to_json)->toBe([['name' => 'A', 'address' => 'a@x.test']])
        ->and($attachment->type)->toBe(AttachmentType::File)
        ->and($rule->match_type)->toBe(RuleMatchType::FromDomain)
        ->and($rule->assignee_ids)->toBe([1, 2]);
});

it('keeps status and processing fields out of mass assignment', function (string $field) {
    new IntakeItem([$field => 'x']);
})->with(['status', 'error', 'processed_at', 'processed_by', 'outlook_category_synced_at', 'clickup_task_id', 'user_id'])
    ->throws(MassAssignmentException::class);

it('keeps an item when its processor is deleted', function () {
    $admin = User::factory()->admin()->create();
    $item = IntakeItem::factory()->create();
    $item->forceFill(['processed_by' => $admin->id])->save();

    $admin->delete();

    expect($item->fresh()->processed_by)->toBeNull();
});

it('refuses to delete a user who still has items', function () {
    $item = IntakeItem::factory()->create();

    $item->user->delete();
})->throws(QueryException::class);

it('shows only completely imported items in triage', function () {
    $imported = IntakeItem::factory()->create();
    IntakeItem::factory()->importing()->create();

    expect(IntakeItem::query()->imported()->pluck('id')->all())->toBe([$imported->id]);
});

it('orders rules by priority, then id', function () {
    $second = IntakeRule::factory()->create(['priority' => 10]);
    $third = IntakeRule::factory()->create(['priority' => 10]);
    $first = IntakeRule::factory()->create(['priority' => 1]);

    expect(IntakeRule::query()->ordered()->pluck('id')->all())->toBe([$first->id, $second->id, $third->id]);
});

it('separates upload state from storage errors on attachments', function () {
    $attachment = IntakeAttachment::factory()->create()->fresh();

    expect($attachment->selected_for_upload)->toBeFalse()
        ->and($attachment->uploaded_to_clickup)->toBeFalse()
        ->and($attachment->upload_error)->toBeNull()
        ->and($attachment->error)->toBeNull();
});

it('stores the label of the last used ClickUp list at the user', function () {
    $user = User::factory()->create();
    $user->forceFill(['last_clickup_list_id' => '901', 'last_clickup_list_label' => 'Space / Folder / List'])->save();

    expect($user->fresh()->last_clickup_list_label)->toBe('Space / Folder / List');
});
