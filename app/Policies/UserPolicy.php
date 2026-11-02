<?php

namespace App\Policies;

use App\Models\User;

/**
 * The resource "Benutzer" is admin only (R12). Users are created by signing in only and
 * never deleted. An admin cannot deactivate themselves or change their own role.
 * All abilities Filament asks for are explicit (strictAuthorization).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, User $model): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function changeRole(User $user, User $model): bool
    {
        return $this->isActiveAdmin($user) && ! $user->is($model);
    }

    public function deactivate(User $user, User $model): bool
    {
        return $this->isActiveAdmin($user) && ! $user->is($model) && $model->active;
    }

    public function activate(User $user, User $model): bool
    {
        return $this->isActiveAdmin($user) && ! $model->active;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $model): bool
    {
        return false;
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, User $model): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, User $model): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->active && $user->isAdmin();
    }
}
