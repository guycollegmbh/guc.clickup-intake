<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Emergency path to grant the admin role. Normally the person finishing the setup assistant
 * becomes the first admin, and admins manage roles in the panel.
 */
#[Signature('intake:make-admin {email : E-mail or UPN of a user who has signed in at least once}')]
#[Description('Grant the admin role to an existing user')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $email = mb_strtolower($this->argument('email'));

        $user = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->orWhereRaw('lower(mailbox_upn) = ?', [$email])
            ->first();

        if ($user === null) {
            $this->error("No user {$email}. The person must sign in once with Microsoft first.");

            return self::FAILURE;
        }

        $user->role = Role::Admin;
        $user->save();

        $this->info("{$user->name} is now admin.");

        return self::SUCCESS;
    }
}
