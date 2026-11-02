<?php

namespace App\Intake;

use App\Enums\IntakeStatus;
use App\Models\IntakeItem;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Deletes finished items after the retention period, including their files (R9).
 *
 * Contract (E7): every status change sets outlook_category_synced_at to null, it is set only
 * after a successful Outlook category update. So a non-null value always belongs to the
 * current status. Only such items are deleted after retention_days (decisions, round 1,
 * point 10). Items whose category never synced are reported as stuck and deleted after
 * retention_hard_limit_days anyway, with a log entry, so they cannot pile up silently.
 *
 * pending, creating and failed are never touched (R6). Deletion goes through Eloquent so the
 * model event removes the files (R8).
 */
class Retention
{
    /**
     * @return array{deleted: int, forced: int, stuck: int} deleted regularly, deleted after the
     *                                                      hard limit, left waiting for the category
     */
    public function prune(): array
    {
        $days = (int) config('intake.retention_days');
        $cutoff = now()->subDays($days);
        $hardCutoff = now()->subDays(max($days, (int) config('intake.retention_hard_limit_days', 60)));

        $deleted = $this->deleteEach(
            $this->due($cutoff)->whereNotNull('outlook_category_synced_at'),
        );

        $forced = $this->deleteEach(
            $this->due($hardCutoff)->whereNull('outlook_category_synced_at'),
            function (IntakeItem $item): void {
                Log::warning('Retention hard limit: deleting item without synced Outlook category.', [
                    'intake_item_id' => $item->id,
                    'user_id' => $item->user_id,
                ]);
            },
        );

        $stuck = $this->due($cutoff)->whereNull('outlook_category_synced_at')->count();

        return ['deleted' => $deleted, 'forced' => $forced, 'stuck' => $stuck];
    }

    /**
     * @return Builder<IntakeItem>
     */
    private function due(DateTimeInterface $before): Builder
    {
        return IntakeItem::query()
            ->whereIn('status', array_map(fn (IntakeStatus $status): string => $status->value, IntakeStatus::finalCases()))
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', $before);
    }

    /**
     * @param  Builder<IntakeItem>  $query
     * @param  (callable(IntakeItem): void)|null  $before
     */
    private function deleteEach(Builder $query, ?callable $before = null): int
    {
        $count = 0;

        $query->lazyById()->each(function (IntakeItem $item) use ($before, &$count): void {
            if ($before !== null) {
                $before($item);
            }

            $item->delete();
            $count++;
        });

        return $count;
    }
}
