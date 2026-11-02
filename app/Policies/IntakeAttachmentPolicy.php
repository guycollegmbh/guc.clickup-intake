<?php

namespace App\Policies;

use App\Models\IntakeAttachment;
use App\Models\User;

/**
 * Attachments are visible to whoever may view their item (R12). Everything else goes through
 * the item and its actions, never through the attachment directly.
 */
class IntakeAttachmentPolicy
{
    public function __construct(private readonly IntakeItemPolicy $items) {}

    public function view(User $user, IntakeAttachment $attachment): bool
    {
        return $attachment->item !== null && $this->items->view($user, $attachment->item);
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, IntakeAttachment $attachment): bool
    {
        return false;
    }

    public function delete(User $user, IntakeAttachment $attachment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, IntakeAttachment $attachment): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, IntakeAttachment $attachment): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, IntakeAttachment $attachment): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }

    // Relation manager abilities (attachments as a relation of the item).

    public function associate(User $user): bool
    {
        return false;
    }

    public function attach(User $user): bool
    {
        return false;
    }

    public function detach(User $user, IntakeAttachment $attachment): bool
    {
        return false;
    }

    public function detachAny(User $user): bool
    {
        return false;
    }

    public function dissociate(User $user, IntakeAttachment $attachment): bool
    {
        return false;
    }

    public function dissociateAny(User $user): bool
    {
        return false;
    }
}
