<?php

namespace App\Policies;

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
     * Triage actions (create task, forward, discard) on items that are not done yet and have
     * no ClickUp task (IntakeItem::isProcessable()). Step 5 adds a separate ability for
     * "Fehlende Anhänge erneut hochladen" on failed items with clickup_task_id.
     */
    public function process(User $user, IntakeItem $item): bool
    {
        return $this->owns($user, $item) && $item->isProcessable();
    }

    /**
     * Forwarding needs a completely imported item. A failed import is retried by the poll,
     * which would overwrite a forward error (E8, E9). Until then it can only be discarded.
     */
    public function forward(User $user, IntakeItem $item): bool
    {
        return $this->process($user, $item) && $item->imported_at !== null;
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
