<?php

namespace App\Microsoft;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use SensitiveParameter;

/**
 * The only way to call Microsoft Graph (R4).
 *
 * Always bound to one delegated access token: the admin's during setup, a user's for that
 * user's mailbox (E1, E6). Retries 429 honouring Retry-After. 503 and 504 are retried only for
 * reads and updates: a POST may have been executed despite the error, and repeating it could
 * create a second object or secret.
 */
class GraphClient
{
    public const BASE_URL = 'https://graph.microsoft.com/v1.0';

    private const MAX_ATTEMPTS = 4;

    private const MAX_RETRY_AFTER_SECONDS = 60;

    public function __construct(#[SensitiveParameter] private readonly string $accessToken) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->send('POST', $path, ['json' => $body]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function patch(string $path, array $body): array
    {
        return $this->send('PATCH', $path, ['json' => $body]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $options): array
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $this->request($method, $path, $options);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            if ($attempt < self::MAX_ATTEMPTS && $this->isRetryable($method, $response->status())) {
                Sleep::for($this->retryAfter($response, $attempt))->seconds();

                continue;
            }

            throw new GraphException($response->status(), $response->json('error.code'), $method, $path);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function request(string $method, string $path, array $options): Response
    {
        try {
            return Http::withToken($this->accessToken)
                ->acceptJson()
                ->timeout(30)
                ->send($method, self::BASE_URL.'/'.ltrim($path, '/'), $options);
        } catch (ConnectionException) {
            throw new GraphException(0, 'connection_failed', $method, $path);
        }
    }

    private function isRetryable(string $method, int $status): bool
    {
        return $status === 429 || ($method !== 'POST' && in_array($status, [503, 504], true));
    }

    private function retryAfter(Response $response, int $attempt): int
    {
        $header = (int) $response->header('Retry-After');

        return min($header > 0 ? $header : 2 ** $attempt, self::MAX_RETRY_AFTER_SECONDS);
    }
}
