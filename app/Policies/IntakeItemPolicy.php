<?php

namespace App\Policies;

use App\Enums\IntakeStatus;
use App\Models\IntakeItem;
use App\Models\User;

/**
 * Members see and work on their own items only, admins on all (R12). Inactive users nothing.
 * Items are created by the import only and deleted by retention only, never by hand.
 * All abilities Filament asks for are explicit (strictAuthorization).
 */
class IntakeItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->active;
    }

    public function view(User $user, IntakeItem $item): bool
    {
        return $this->owns($user, $item);
    }

    public function update(User $user, IntakeItem $item): bool
    {
        return $this->owns($user, $item);
    }

    /**
     * Triage actions (create task, forward, discard) on items that are not done yet.
     */
    public function process(User $user, IntakeItem $item): bool
    {
        return $this->owns($user, $item)
            && in_array($item->status, [IntakeStatus::Pending, IntakeStatus::Failed], true);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, IntakeItem $item): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, IntakeItem $item): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, IntakeItem $item): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, IntakeItem $item): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }

    private function owns(User $user, IntakeItem $item): bool
    {
        return $user->active && ($user->isAdmin() || $item->user_id === $user->id);
    }
}
