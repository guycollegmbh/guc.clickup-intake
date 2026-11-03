<?php

namespace App\Intake;

/**
 * Outcome of a triage action, for the message shown to the user.
 */
enum TriageResult
{
    /** Status changed and the Outlook category follows it. */
    case Done;

    /** Status changed, the Outlook category is updated later by the poll (E7). */
    case CategoryPending;

    /** Status changed, but the owner is deactivated: Outlook cannot be updated any more (E9). */
    case OwnerInactive;

    /** The action failed, the item is failed with an error text (R6). */
    case Failed;

    /** Nothing done: the item was processed by someone else in the meantime, or is being. */
    case Skipped;
}
