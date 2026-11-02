<?php

namespace App\Users;

use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Admin actions on users (step 4). Users are created by signing in only and never deleted.
 * Every method checks the UserPolicy itself (R12): an admin cannot deactivate themselves or
 * change their own role, so there is always an active admin left.
 */
class UserAdministration
{
    public function changeRole(User $target, Role $role, User $actor): void
    {
        Gate::forUser($actor)->authorize('changeRole', $target);

        $target->role = $role;
        $target->save();
    }

    /**
     * Deactivated users cannot sign in and get no token (UserTokens). All stored tokens are
     * removed (E2), the poll skips them, the panel refuses them on the next request.
     */
    public function deactivate(User $target, User $actor): void
    {
        Gate::forUser($actor)->authorize('deactivate', $target);

        $target->active = false;
        $target->ms_refresh_token = null;
        $target->ms_access_token = null;
        $target->ms_token_expires_at = null;
        $target->ms_connection_status = ConnectionStatus::Disconnected;
        $target->clickup_token = null;
        $target->clickup_connection_status = ConnectionStatus::Disconnected;
        $target->save();
    }

    /**
     * The user signs in again to connect Microsoft 365.
     */
    public function activate(User $target, User $actor): void
    {
        Gate::forUser($actor)->authorize('activate', $target);

        $target->active = true;
        $target->save();
    }
}
