<?php

namespace App\ClickUp;

use RuntimeException;

/**
 * A failed ClickUp call. Carries only status and ClickUp error code (ECODE), never the response
 * body or a token, so it is safe to log and to show (R11c). Status 0 means no answer
 * (connection lost or timeout).
 */
class ClickUpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $errorCode,
        string $method,
        string $path,
    ) {
        parent::__construct(sprintf('ClickUp %s %s failed: %d %s', $method, $path, $status, $errorCode ?? ''));
    }

    /**
     * The request may have been executed although no success came back.
     */
    public function isUncertain(): bool
    {
        return in_array($this->status, [0, 503, 504], true);
    }
}
