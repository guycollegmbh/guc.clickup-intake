<?php

namespace App\Intake;

use App\Enums\IntakeStatus;
use App\Microsoft\OutlookCategories;
use App\Models\IntakeItem;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The only place that changes the status of an intake item.
 *
 * Every transition is a conditional update (id and allowed source states). If the item was
 * changed in the meantime, e.g. discarded in triage while the poll runs, nothing is written and
 * the method returns false. The model is reloaded either way.
 *
 * Contract (E7, R10):
 * - every status change sets outlook_category_synced_at to null,
 * - a final status sets processed_at and, if a user acts, processed_by. Leaving a final
 *   status clears both,
 * - outlook_category_synced_at is set only by syncCategory(), after Outlook confirms the
 *   category of the status.
 *
 * ClickUp (step 5, E10): pending or failed without task → creating (processed_by is the acting
 * user, whose token creates the task and uploads) → created, or back to failed. With a task,
 * failed is a partial success, retryUpload() takes it back to creating.
 */
class ItemTransitions
{
    private const IMPORT_RETRY_MAX_MINUTES = 60;

    private const PROCESSABLE = [IntakeStatus::Pending, IntakeStatus::Failed];

    /**
     * An item in creating without change for this long is taken as aborted (worker killed).
     */
    public const STALE_CREATING_MINUTES = 15;

    public const ABORTED_UPLOAD_ERROR = 'Der Upload wurde abgebrochen.';

    public const ABORTED_CREATE_ERROR = 'Die Task-Erstellung wurde abgebrochen. Bitte in ClickUp prüfen.';

    public function __construct(private OutlookCategories $categories) {}

    /**
     * Claims an item whose import is not complete (new, aborted or failed) for an import run.
     * False if it is no longer pending or failed, or was imported in the meantime.
     */
    public function claimForImport(IntakeItem $item): bool
    {
        return $this->transition($item, IntakeStatus::Pending, [IntakeStatus::Pending, IntakeStatus::Failed], [
            'error' => null,
        ], requireNotImported: true);
    }

    /**
     * An import that failed: visible in triage as failed with the text (R6), imported_at
     * stays null and the next attempt waits 2, 4, 8 ... up to 60 minutes (E8).
     *
     * @param  string  $error  German text, shown in triage
     */
    public function markImportFailed(IntakeItem $item, string $error): bool
    {
        $attempts = (int) $item->import_attempts + 1;

        return $this->transition($item, IntakeStatus::Failed, [IntakeStatus::Pending, IntakeStatus::Failed], [
            'error' => $error,
            'imported_at' => null,
            'import_attempts' => $attempts,
            'next_import_at' => now()->addMinutes(self::retryDelayMinutes($attempts)),
        ], requireNotImported: true);
    }

    /**
     * A discarded mail that was marked again in Outlook goes back to triage.
     */
    public function backToPending(IntakeItem $item): bool
    {
        return $this->transition($item, IntakeStatus::Pending, [IntakeStatus::Discarded], ['error' => null]);
    }

    /**
     * Discarded in triage (R10: syncCategory() then removes "ClickUp"). Only from a
     * processable state (IntakeItem::isProcessable()), so a parallel action is never
     * overwritten. The error text of a failed item stays.
     */
    public function discard(IntakeItem $item, User $actor): bool
    {
        return $this->transition($item, IntakeStatus::Discarded, self::PROCESSABLE, actor: $actor, requireProcessable: true);
    }

    /**
     * The mail was forwarded to the support address (R14). syncCategory() then sets
     * "Support ✓".
     */
    public function markForwarded(IntakeItem $item, User $actor): bool
    {
        return $this->transition($item, IntakeStatus::Forwarded, self::PROCESSABLE, [
            'error' => null,
        ], actor: $actor, requireProcessable: true);
    }

    /**
     * A triage action on an imported item failed (R6). imported_at stays set, so the poll
     * never imports it again and the text stays (E8, E9).
     *
     * @param  string  $error  German text, shown in triage
     */
    public function markFailed(IntakeItem $item, string $error): bool
    {
        return $this->transition($item, IntakeStatus::Failed, self::PROCESSABLE, [
            'error' => $error,
        ], requireProcessable: true, requireImported: true);
    }

    /**
     * "Task erstellen" starts: only for a processable, imported item. The acting user is stored
     * in processed_by, the upload job uses that user's token (E2). Also stores the comment wish.
     */
    public function startCreating(IntakeItem $item, User $actor, bool $addComment): bool
    {
        return $this->transition($item, IntakeStatus::Creating, self::PROCESSABLE, [
            'error' => null,
            'processed_by' => $actor->id,
            'clickup_comment_requested' => $addComment,
            'clickup_comment_added_at' => null,
        ], requireProcessable: true, requireImported: true);
    }

    /**
     * Records the created task while the item is creating. The status stays creating until
     * the uploads are done.
     */
    public function recordTask(IntakeItem $item, string $taskId, string $taskUrl, string $listId): bool
    {
        $recorded = IntakeItem::query()
            ->whereKey($item->getKey())
            ->where('status', IntakeStatus::Creating->value)
            ->whereNull('clickup_task_id')
            ->update([
                'clickup_task_id' => $taskId,
                'clickup_task_url' => $taskUrl,
                'clickup_list_id' => $listId,
            ]) === 1;
        $item->refresh();

        return $recorded;
    }

    /**
     * The task could not be created: back to failed with the text (R6), imported_at stays.
     *
     * @param  string  $error  German text, shown in triage
     */
    public function failCreating(IntakeItem $item, string $error): bool
    {
        return $this->transition($item, IntakeStatus::Failed, [IntakeStatus::Creating], [
            'error' => $error,
        ], hasTask: false);
    }

    /**
     * ClickUp created the task, but its id could not be stored on the item (database error, or
     * the item left creating). Failed without task id, the text names the task URL, so nobody
     * creates a second one without looking.
     *
     * @param  string  $error  German text with the task URL
     */
    public function markTaskNotRecorded(IntakeItem $item, string $error): bool
    {
        return $this->transition($item, IntakeStatus::Failed, [IntakeStatus::Creating, IntakeStatus::Failed], [
            'error' => $error,
        ], hasTask: false);
    }

    /**
     * Task and all selected uploads are done. syncCategory() then sets "ClickUp ✓" (R10).
     * processed_by stays the user who created the task.
     */
    public function markCreated(IntakeItem $item): bool
    {
        return $this->transition($item, IntakeStatus::Created, [IntakeStatus::Creating], [
            'error' => null,
        ], keepProcessedBy: true, hasTask: true);
    }

    /**
     * Partial success: task created, an upload or the comment failed (round 1, point 5).
     *
     * @param  string  $error  German text naming the missing attachments
     */
    public function markUploadFailed(IntakeItem $item, string $error): bool
    {
        return $this->transition($item, IntakeStatus::Failed, [IntakeStatus::Creating], [
            'error' => $error,
        ], keepProcessedBy: true, hasTask: true);
    }

    /**
     * "Fehlende Anhänge erneut hochladen": a partial success goes back to creating, the
     * acting user's token is used from now on.
     */
    public function retryUpload(IntakeItem $item, User $actor): bool
    {
        return $this->transition($item, IntakeStatus::Creating, [IntakeStatus::Failed], [
            'error' => null,
            'processed_by' => $actor->id,
        ], hasTask: true);
    }

    /**
     * Keeps a running upload from being taken as aborted.
     */
    public function touchCreating(IntakeItem $item): void
    {
        IntakeItem::query()
            ->whereKey($item->getKey())
            ->where('status', IntakeStatus::Creating->value)
            ->update(['updated_at' => now()]);
    }

    /**
     * Items in creating without change for STALE_CREATING_MINUTES (worker killed, job lost)
     * become failed. With a task the missing uploads can be retried, without a task it is
     * unclear whether ClickUp created it. Returns the number of items changed.
     */
    public function failStaleCreating(): int
    {
        $count = 0;

        IntakeItem::query()
            ->where('status', IntakeStatus::Creating->value)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_CREATING_MINUTES))
            ->lazyById()
            ->each(function (IntakeItem $item) use (&$count): void {
                $changed = $item->clickup_task_id === null
                    ? $this->failCreating($item, self::ABORTED_CREATE_ERROR)
                    : $this->markUploadFailed($item, self::ABORTED_UPLOAD_ERROR);

                if ($changed) {
                    Log::warning('Intake item stuck in creating, marked as failed.', ['intake_item_id' => $item->id]);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Brings the Outlook category of the item's mail in line with its final status and records
     * it. Nothing to do for pending, creating and failed: their mail keeps "ClickUp".
     * Graph and token errors are thrown, outlook_category_synced_at then stays null.
     */
    public function syncCategory(IntakeItem $item): bool
    {
        $target = $this->target($item->status);

        if ($target === null) {
            return false;
        }

        if (! $this->categories->replace($this->owner($item), $item->graph_message_id, ...$target)) {
            Log::warning('Outlook category not confirmed after the update.', ['intake_item_id' => $item->id]);

            return false;
        }

        $synced = IntakeItem::query()
            ->whereKey($item->getKey())
            ->where('status', $item->status->value)
            ->update(['outlook_category_synced_at' => now()]) === 1;
        $item->refresh();

        return $synced;
    }

    /**
     * A copy of the item's mail (same mail, other Graph id, e.g. copied to another folder)
     * gets the category of the item's final status. The item and its own sync state stay as
     * they are.
     */
    public function alignCopy(IntakeItem $item, string $graphMessageId): void
    {
        $target = $this->target($item->status);

        if ($target !== null) {
            $this->categories->replace($this->owner($item), $graphMessageId, ...$target);
        }
    }

    public static function retryDelayMinutes(int $attempts): int
    {
        return min(2 ** min(max($attempts, 1), 6), self::IMPORT_RETRY_MAX_MINUTES);
    }

    /**
     * @return array{0: string, 1: string|null}|null category to replace and its replacement
     */
    private function target(IntakeStatus $status): ?array
    {
        return match ($status) {
            IntakeStatus::Created => [OutlookCategories::CLICKUP, OutlookCategories::CLICKUP_DONE],
            IntakeStatus::Forwarded => [OutlookCategories::CLICKUP, OutlookCategories::SUPPORT_DONE],
            IntakeStatus::Discarded => [OutlookCategories::CLICKUP, null],
            IntakeStatus::Pending, IntakeStatus::Creating, IntakeStatus::Failed => null,
        };
    }

    private function owner(IntakeItem $item): User
    {
        return $item->loadMissing('user')->user;
    }

    /**
     * @param  list<IntakeStatus>  $from  allowed source states
     * @param  array<string, mixed>  $attributes  further fields written with the status
     */
    private function transition(
        IntakeItem $item,
        IntakeStatus $to,
        array $from,
        array $attributes = [],
        ?User $actor = null,
        bool $requireNotImported = false,
        bool $requireProcessable = false,
        bool $requireImported = false,
        bool $keepProcessedBy = false,
        ?bool $hasTask = null,
    ): bool {
        $query = IntakeItem::query()
            ->whereKey($item->getKey())
            ->whereIn('status', array_map(fn (IntakeStatus $status): string => $status->value, $from));

        if ($requireNotImported) {
            $query->whereNull('imported_at');
        }

        if ($requireImported) {
            $query->whereNotNull('imported_at');
        }

        if ($requireProcessable) {
            $query->processable();
        }

        if ($hasTask !== null) {
            $hasTask ? $query->whereNotNull('clickup_task_id') : $query->whereNull('clickup_task_id');
        }

        // processed_by: the actor of a final status, given explicitly (creating) or kept as is
        // (the user who created the task).
        $values = [
            'processed_by' => $to->isFinal() ? $actor?->id : null,
            ...$attributes,
            'status' => $to->value,
            'outlook_category_synced_at' => null,
            'processed_at' => $to->isFinal() ? now() : null,
        ];

        if ($keepProcessedBy) {
            unset($values['processed_by']);
        }

        $changed = $query->update($values) === 1;

        $item->refresh();

        return $changed;
    }
}
