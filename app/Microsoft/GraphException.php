<?php

namespace App\Microsoft;

use RuntimeException;

/**
 * A failed Graph call. Carries only status and Graph error code, never the response body or
 * tokens, so it is safe to log and to show (R11c).
 */
class GraphException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $graphCode,
        string $method,
        string $path,
    ) {
        parent::__construct(sprintf('Graph %s %s failed: %d %s', $method, $path, $status, $graphCode ?? ''));
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
