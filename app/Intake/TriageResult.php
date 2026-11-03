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

    /** Nothing done: the acting user has no working ClickUp connection (E2). */
    case NotConnected;

    /** Nothing done: the ClickUp rate limit of the acting user is used up for the moment. */
    case Busy;
}
