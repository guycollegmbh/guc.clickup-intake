<?php

namespace App\Microsoft;

use RuntimeException;

/**
 * No access token right now, but the connection itself may be fine (token endpoint down,
 * network, lock timeout, app credentials missing or rejected). The user's status is not
 * changed, the next attempt may succeed. Carries no token and no response text (R11c).
 */
class MicrosoftTokenUnavailable extends RuntimeException
{
    public function __construct(public readonly int $userId, public readonly string $reason)
    {
        parent::__construct("No Microsoft access token for user {$userId} ({$reason}).");
    }
}
