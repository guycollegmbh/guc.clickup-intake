<?php

namespace App\Jobs;

use App\Intake\ItemTransitions;
use App\Intake\TaskUploads;
use App\Models\IntakeItem;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uploads the attachments of a created task and finishes the item (TaskUploads). The payload is
 * the item id only, no token and no mail content (R11c).
 *
 * timeout stays below the queue's retry_after (config/queue.php). Before each upload TaskUploads
 * checks the time left: if it might not suffice, the job queues a follow-up for the rest and
 * ends normally. Unique until processing, so that follow-up can be queued while this job runs.
 * A second job for the item cannot be queued meanwhile: only creating items are uploaded, and
 * the transitions into creating do not happen while the item is creating.
 *
 * A job that fails or times out leaves a partial success naming the missing attachments, a
 * killed worker is caught by ItemTransitions::failStaleCreating().
 */
class UploadTaskAttachments implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /**
     * Upper bound for the unique lock if a worker dies without releasing it.
     */
    public int $uniqueFor = 900;

    public function __construct(public readonly int $itemId) {}

    public function uniqueId(): string
    {
        return (string) $this->itemId;
    }

    public function handle(TaskUploads $uploads): void
    {
        $item = IntakeItem::query()->find($this->itemId);

        if ($item !== null && ! $uploads->run($item, now()->addSeconds($this->timeout))) {
            self::dispatch($this->itemId);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('ClickUp upload job failed.', [
            'intake_item_id' => $this->itemId,
            'exception' => $e === null ? null : $e::class,
        ]);

        $item = IntakeItem::query()->find($this->itemId);

        if ($item !== null && $item->clickup_task_id !== null) {
            app(ItemTransitions::class)->markUploadFailed($item, TaskUploads::abortedText($item));
        }
    }
}
