<?php

namespace App\ClickUp;

use RuntimeException;

/**
 * The user has no usable ClickUp connection (never connected, disconnected, broken after a 401,
 * or deactivated). Carries no token (R11c).
 */
class ClickUpNotConnected extends RuntimeException
{
    public function __construct(public readonly int $userId, public readonly string $reason)
    {
        parent::__construct("ClickUp connection of user {$userId} is not usable ({$reason}).");
    }
}
