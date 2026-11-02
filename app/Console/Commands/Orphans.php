<?php

namespace App\Console\Commands;

use App\Intake\AttachmentStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('intake:orphans')]
#[Description('Report attachment directories and files without rows, and rows without files (R8)')]
class Orphans extends Command
{
    public function handle(AttachmentStore $store): int
    {
        $found = $store->orphans();

        foreach ($found as $line) {
            $this->warn($line);
        }

        $this->line('Orphans: '.count($found));

        return $found === [] ? self::SUCCESS : self::FAILURE;
    }
}
