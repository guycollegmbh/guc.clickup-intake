<?php

namespace App\Console\Commands;

use App\Intake\Retention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Fails when items are stuck (category not synced) or were deleted after the hard limit, and
 * when intake:orphans finds something, so the scheduler reports it.
 */
#[Signature('intake:prune')]
#[Description('Delete finished items after the retention period, then check for orphans (R9)')]
class Prune extends Command
{
    public function handle(Retention $retention): int
    {
        $result = $retention->prune();

        $this->line('Deleted items: '.$result['deleted']);
        $this->line('Deleted after hard limit: '.$result['forced']);
        $this->line('Stuck items (Outlook category not synced): '.$result['stuck']);

        $orphans = $this->call('intake:orphans');

        return $orphans === self::SUCCESS && $result['forced'] === 0 && $result['stuck'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
