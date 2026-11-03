<?php

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Microsoft\GraphException;
use App\Microsoft\MicrosoftConnectionBroken;
use App\Microsoft\MicrosoftTokenUnavailable;
use App\Microsoft\OutlookCategories;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Repair path: categories are normally created by a job after each sign-in (E1).
 */
#[Signature('intake:setup-categories {upn? : Mailbox (UPN) of one user, default all connected users}')]
#[Description('Create the Outlook categories ClickUp, ClickUp ✓ and Support ✓ where missing')]
class SetupCategories extends Command
{
    public function handle(OutlookCategories $categories): int
    {
        $users = $this->users();

        if ($users === null) {
            return self::FAILURE;
        }

        $failed = 0;

        foreach ($users as $user) {
            if ($user->ms_connection_status !== ConnectionStatus::Connected) {
                $this->error("{$user->mailbox_upn}: no Microsoft connection, the user must sign in.");
                $failed++;

                continue;
            }

            try {
                $created = $categories->ensureMasterCategories($user);
                $this->line("{$user->mailbox_upn}: ".($created === [] ? 'all categories present' : 'created '.implode(', ', $created)));
            } catch (MicrosoftConnectionBroken|MicrosoftTokenUnavailable|GraphException $e) {
                $this->error("{$user->mailbox_upn}: failed (".class_basename($e).').');
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, User>|null
     */
    private function users(): ?Collection
    {
        $upn = $this->argument('upn');

        if ($upn === null) {
            return User::query()
                ->where('active', true)
                ->where('ms_connection_status', ConnectionStatus::Connected->value)
                ->orderBy('id')
                ->get();
        }

        $users = User::query()
            ->where('active', true)
            ->whereRaw('lower(mailbox_upn) = ?', [Str::lower($upn)])
            ->get();

        if ($users->count() !== 1) {
            $this->error($users->isEmpty() ? 'No active user with this mailbox.' : 'Several users with this mailbox, aborting.');

            return null;
        }

        return $users;
    }
}
