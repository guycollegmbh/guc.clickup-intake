<?php

namespace App\Policies;

use App\Models\IntakeRule;
use App\Models\User;

/**
 * Rules are admin only (R12). Inactive users nothing. Rules have no soft deletes.
 * All abilities Filament asks for are explicit (strictAuthorization).
 */
class IntakeRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, IntakeRule $rule): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, IntakeRule $rule): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function delete(User $user, IntakeRule $rule): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function replicate(User $user, IntakeRule $rule): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function reorder(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function restore(User $user, IntakeRule $rule): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, IntakeRule $rule): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->active && $user->isAdmin();
    }
}
