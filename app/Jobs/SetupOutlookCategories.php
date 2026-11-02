<?php

namespace App\Jobs;

use App\Enums\ConnectionStatus;
use App\Microsoft\MicrosoftConnectionBroken;
use App\Microsoft\OutlookCategories;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Creates the Outlook categories in the user's mailbox after sign-in (E1). Payload is the user
 * id only (R11c). Repair by hand: intake:setup-categories.
 */
class SetupOutlookCategories implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    public function __construct(public readonly int $userId) {}

    public function handle(OutlookCategories $categories): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null || ! $user->active || $user->ms_connection_status !== ConnectionStatus::Connected) {
            return;
        }

        try {
            $categories->ensureMasterCategories($user);
        } catch (MicrosoftConnectionBroken) {
            // Nothing to do until the next sign-in.
        }
    }
}
