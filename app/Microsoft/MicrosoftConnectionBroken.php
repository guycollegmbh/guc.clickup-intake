<?php

namespace App\Microsoft;

use RuntimeException;

/**
 * The user's Microsoft connection is gone for good (refresh token rejected, revoked, password
 * reset, group assignment removed). The user's status is broken until the next sign-in.
 * Carries no token and no response text (R11c).
 */
class MicrosoftConnectionBroken extends RuntimeException
{
    public function __construct(public readonly int $userId, public readonly string $reason)
    {
        parent::__construct("Microsoft connection of user {$userId} is broken ({$reason}).");
    }
}
