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
 * Steps 4 and 5 add the transitions to creating, created, forwarded and discarded here.
 */
class ItemTransitions
{
    private const IMPORT_RETRY_MAX_MINUTES = 60;

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
    ): bool {
        $query = IntakeItem::query()
            ->whereKey($item->getKey())
            ->whereIn('status', array_map(fn (IntakeStatus $status): string => $status->value, $from));

        if ($requireNotImported) {
            $query->whereNull('imported_at');
        }

        $changed = $query->update([
            ...$attributes,
            'status' => $to->value,
            'outlook_category_synced_at' => null,
            'processed_at' => $to->isFinal() ? now() : null,
            'processed_by' => $to->isFinal() ? $actor?->id : null,
        ]) === 1;

        $item->refresh();

        return $changed;
    }
}
