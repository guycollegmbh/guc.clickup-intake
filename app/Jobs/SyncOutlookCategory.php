<?php

namespace App\Jobs;

use App\Intake\TriageActions;
use App\Models\IntakeItem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sets the Outlook category of a finished item outside the request (bulk discard, E9).
 * One job per item at a time. Failures are logged by TriageActions and left to the poll,
 * which repeats the category of finished items without sync (E7).
 */
class SyncOutlookCategory implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $itemId) {}

    public function uniqueId(): string
    {
        return (string) $this->itemId;
    }

    public function handle(TriageActions $actions): void
    {
        $item = IntakeItem::query()->find($this->itemId);

        if ($item !== null) {
            $actions->syncCategory($item);
        }
    }
}
