<?php

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Intake\ItemTransitions;
use App\Jobs\PollMailbox;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every minute (routes/console.php, E11). Only users with a working Microsoft connection
 * are polled (E1). The work happens in the queue, one unique job per user.
 *
 * First, items stuck in creating (worker killed during "Task erstellen") become failed, for
 * all users, also those without Microsoft connection (E10).
 */
#[Signature('intake:poll')]
#[Description('Queue a mailbox poll for every active user with a Microsoft connection')]
class Poll extends Command
{
    public function handle(ItemTransitions $transitions): int
    {
        $stale = $transitions->failStaleCreating();

        if ($stale > 0) {
            $this->warn("Items stuck in creating marked as failed: {$stale}");
        }

        $count = 0;

        User::query()
            ->where('active', true)
            ->where('ms_connection_status', ConnectionStatus::Connected->value)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $userId) use (&$count): void {
                PollMailbox::dispatch($userId);
                $count++;
            });

        $this->line("Queued polls: {$count}");

        return self::SUCCESS;
    }
}
