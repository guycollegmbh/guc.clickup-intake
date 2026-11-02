<?php

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Jobs\PollMailbox;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every two minutes (routes/console.php). Only users with a working Microsoft connection
 * are polled (E1). The work happens in the queue, one unique job per user.
 */
#[Signature('intake:poll')]
#[Description('Queue a mailbox poll for every active user with a Microsoft connection')]
class Poll extends Command
{
    public function handle(): int
    {
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
