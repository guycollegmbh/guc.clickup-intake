<?php

namespace App\Microsoft;

use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * The only way to call Microsoft Graph (R4).
 *
 * Always bound to one delegated access token: the admin's during setup, a user's for that
 * user's mailbox (E1, E6). Retries 429 honouring Retry-After. 503 and 504 are retried only for
 * reads and updates: a POST may have been executed despite the error, and repeating it could
 * create a second object or secret.
 *
 * Prefer values given to the constructor go with every request (mailbox clients always send
 * IdType="ImmutableId"), per-call values are added. Absolute URLs are accepted only below
 * BASE_URL (@odata.nextLink), so the token never goes to another host.
 */
class GraphClient
{
    public const BASE_URL = 'https://graph.microsoft.com/v1.0';

    private const MAX_ATTEMPTS = 4;

    private const MAX_RETRY_AFTER_SECONDS = 60;

    /**
     * @param  list<string>  $prefer  values of the Prefer header sent with every request
     */
    public function __construct(
        #[SensitiveParameter] private readonly string $accessToken,
        private readonly array $prefer = [],
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  list<string>  $prefer  additional Prefer values for this call
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], array $prefer = []): array
    {
        // An empty query option would replace the query string of a nextLink.
        return $this->send('GET', $path, $query === [] ? [] : ['query' => $query], $prefer);
    }

    /**
     * Raw content of a resource (e.g. attachments/{id}/$value) as a readable stream. The body
     * is never read into memory here (R5). The caller must close the stream.
     *
     * @return resource
     */
    public function stream(string $path)
    {
        $response = $this->send('GET', $path, ['stream' => true], [], decode: false);

        return StreamWrapper::getResource($response->toPsrResponse()->getBody());
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
     * @param  list<string>  $prefer
     * @return ($decode is true ? array<string, mixed> : Response)
     */
    private function send(string $method, string $path, array $options, array $prefer = [], bool $decode = true): array|Response
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $this->request($method, $path, $options, $prefer);

            if ($response->successful()) {
                return $decode ? ($response->json() ?? []) : $response;
            }

            if ($attempt < self::MAX_ATTEMPTS && $this->isRetryable($method, $response->status())) {
                Sleep::for($this->retryAfter($response, $attempt))->seconds();

                continue;
            }

            $code = $response->json('error.code');

            throw new GraphException($response->status(), is_string($code) ? $code : null, $method, $this->logPath($path));
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $prefer
     */
    private function request(string $method, string $path, array $options, array $prefer): Response
    {
        $headers = [];
        $preferValues = array_values(array_unique([...$this->prefer, ...$prefer]));

        if ($preferValues !== []) {
            $headers['Prefer'] = implode(', ', $preferValues);
        }

        // A stream may run longer than a JSON call: allow time for large attachments.
        $timeout = ($options['stream'] ?? false) === true ? 120 : 30;

        try {
            return Http::withToken($this->accessToken)
                ->acceptJson()
                ->withHeaders($headers)
                ->timeout($timeout)
                ->send($method, $this->url($path), $options);
        } catch (ConnectionException) {
            throw new GraphException(0, 'connection_failed', $method, $this->logPath($path));
        }
    }

    private function url(string $path): string
    {
        if (str_starts_with($path, self::BASE_URL.'/')) {
            return $path;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) === 1) {
            throw new InvalidArgumentException('Graph URLs must point to '.self::BASE_URL.'.');
        }

        return self::BASE_URL.'/'.ltrim($path, '/');
    }

    /**
     * Path for exception messages: no query string (a nextLink may carry a skip token).
     */
    private function logPath(string $path): string
    {
        $path = str_starts_with($path, self::BASE_URL.'/') ? substr($path, strlen(self::BASE_URL) + 1) : $path;

        return strtok($path, '?') ?: $path;
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
