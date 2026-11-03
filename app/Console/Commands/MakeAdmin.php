<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Emergency path to grant the admin role. Normally the person finishing the setup assistant
 * becomes the first admin, and admins manage roles in the panel.
 *
 * Users are keyed by Entra object id (R11). The e-mail is only a convenience lookup and must
 * match exactly one user, otherwise the command lists the candidates and asks for --oid.
 */
#[Signature('intake:make-admin
    {email? : E-mail or UPN of a user who has signed in at least once}
    {--oid= : Entra object id, unambiguous}')]
#[Description('Grant the admin role to an existing user (and reactivate the user)')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $candidates = $this->candidates();

        if ($candidates->isEmpty()) {
            $this->error('No such user. The person must sign in once with Microsoft first.');

            return self::FAILURE;
        }

        if ($candidates->count() > 1) {
            $this->error('Ambiguous, more than one user matches. Run again with --oid:');
            $this->table(
                ['oid', 'name', 'upn', 'active', 'last login'],
                $candidates->map(fn (User $user): array => [
                    $user->entra_object_id,
                    $user->name,
                    $user->mailbox_upn,
                    $user->active ? 'yes' : 'no',
                    $user->last_login_at?->toDateTimeString() ?? 'never',
                ]),
            );

            return self::FAILURE;
        }

        /** @var User $user */
        $user = $candidates->first();
        $wasInactive = ! $user->active;

        $user->role = Role::Admin;
        $user->active = true;
        $user->save();

        $this->info("{$user->name} ({$user->entra_object_id}) is now admin.");

        if ($wasInactive) {
            $this->warn('The user was deactivated and has been reactivated.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, User>
     */
    private function candidates()
    {
        if ($oid = $this->option('oid')) {
            return User::query()->where('entra_object_id', $oid)->get();
        }

        $email = mb_strtolower((string) $this->argument('email'));

        if ($email === '') {
            $this->error('Pass an e-mail or --oid.');

            return collect();
        }

        return User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->orWhereRaw('lower(mailbox_upn) = ?', [$email])
            ->get();
    }
}
