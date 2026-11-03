<?php

namespace App\ClickUp;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use RuntimeException;
use SensitiveParameter;

/**
 * The only way to call ClickUp (R4).
 *
 * Bound to the OAuth token of one user (E2, R11b). Every request first takes a slot of the
 * limiter of that token: RATE_LIMIT per fixed window of one minute, kept in the cache, so it
 * holds across queue workers and web requests with Redis. Waiting for a free slot is limited
 * by $maxThrottleWaitSeconds (short in web requests, longer in jobs), then the call fails
 * with status 429 and code LOCAL_RATE_LIMIT without being sent.
 *
 * 429 from ClickUp is retried after X-RateLimit-Reset or Retry-After, capped at
 * MAX_WAIT_SECONDS. 503 and 504 are retried for GET only: a POST may have created the task,
 * attachment or comment despite the error.
 *
 * A 401 calls $onUnauthorized before the exception is thrown (UserClickUp marks the connection
 * broken there). Exceptions carry status and ECODE only, never the response text or the token
 * (R11c).
 */
class ClickUpClient
{
    public const BASE_URL = 'https://api.clickup.com/api/v2';

    public const RATE_LIMIT = 100;

    public const LOCAL_RATE_LIMIT = 'local_rate_limit';

    /**
     * Longest wait for a free slot in a web request (triage page, "Task erstellen").
     */
    public const INTERACTIVE_WAIT_SECONDS = 5;

    /**
     * Longest wait for a free slot in a queue job.
     */
    public const JOB_WAIT_SECONDS = 60;

    public const UPLOAD_TIMEOUT_SECONDS = 120;

    private const MAX_ATTEMPTS = 4;

    private const MAX_WAIT_SECONDS = 60;

    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        #[SensitiveParameter] private readonly string $token,
        private readonly ?Closure $onUnauthorized = null,
        private readonly int $maxThrottleWaitSeconds = self::JOB_WAIT_SECONDS,
    ) {}

    /**
     * Exchanges an OAuth authorization code for an access token. The parameters go in the query
     * string, as in the examples of the ClickUp documentation. ClickUp tokens currently do not
     * expire and come without refresh token (E2). No retry: a code is valid once.
     */
    public static function exchangeCode(
        #[SensitiveParameter] string $clientId,
        #[SensitiveParameter] string $clientSecret,
        #[SensitiveParameter] string $code,
    ): string {
        try {
            $response = Http::acceptJson()->timeout(self::TIMEOUT_SECONDS)->send('POST', self::BASE_URL.'/oauth/token', [
                'query' => ['client_id' => $clientId, 'client_secret' => $clientSecret, 'code' => $code],
            ]);
        } catch (ConnectionException) {
            throw new ClickUpException(0, 'connection_failed', 'POST', 'oauth/token');
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new ClickUpException($response->status(), self::errorCode($response) ?? 'no_access_token', 'POST', 'oauth/token');
        }

        return $token;
    }

    /**
     * The user of the token.
     *
     * @return array{id: int, name: string}
     */
    public function user(): array
    {
        $user = $this->get('user')['user'] ?? [];

        if (! is_array($user) || ! is_numeric($user['id'] ?? null)) {
            throw new ClickUpException(200, 'unexpected_response', 'GET', 'user');
        }

        return ['id' => (int) $user['id'], 'name' => self::personName($user)];
    }

    /**
     * Workspaces ("teams" in API v2) the token has access to, with their members.
     *
     * @return list<array{id: string, name: string, members: list<array{id: int, name: string}>}>
     */
    public function teams(): array
    {
        $teams = [];

        foreach ($this->items($this->get('team'), 'teams') as $team) {
            $members = [];

            foreach (is_array($team['members'] ?? null) ? $team['members'] : [] as $member) {
                $user = is_array($member) && is_array($member['user'] ?? null) ? $member['user'] : null;

                if ($user !== null && is_numeric($user['id'] ?? null)) {
                    $members[] = ['id' => (int) $user['id'], 'name' => self::personName($user)];
                }
            }

            $teams[] = ['id' => (string) $team['id'], 'name' => (string) ($team['name'] ?? $team['id']), 'members' => $members];
        }

        return $teams;
    }

    /**
     * Members of one workspace, empty if the token has no access to it.
     *
     * @return list<array{id: int, name: string}>
     */
    public function members(string $teamId): array
    {
        foreach ($this->teams() as $team) {
            if ($team['id'] === $teamId) {
                return $team['members'];
            }
        }

        return [];
    }

    /**
     * All lists of a workspace the token can see, not archived, labelled "Space / Folder / List"
     * or "Space / List" for lists outside a folder.
     *
     * @return list<array{id: string, label: string}>
     */
    public function hierarchy(string $teamId): array
    {
        $lists = [];
        $archived = ['archived' => 'false'];

        foreach ($this->items($this->get('team/'.rawurlencode($teamId).'/space', $archived), 'spaces') as $space) {
            $spaceName = (string) ($space['name'] ?? '');
            $spacePath = 'space/'.rawurlencode((string) $space['id']);

            foreach ($this->items($this->get($spacePath.'/folder', $archived), 'folders') as $folder) {
                $folderPath = 'folder/'.rawurlencode((string) $folder['id']).'/list';

                foreach ($this->items($this->get($folderPath, $archived), 'lists') as $list) {
                    $lists[] = ['id' => (string) $list['id'], 'label' => implode(' / ', [$spaceName, (string) ($folder['name'] ?? ''), (string) ($list['name'] ?? '')])];
                }
            }

            foreach ($this->items($this->get($spacePath.'/list', $archived), 'lists') as $list) {
                $lists[] = ['id' => (string) $list['id'], 'label' => $spaceName.' / '.(string) ($list['name'] ?? '')];
            }
        }

        return $lists;
    }

    /**
     * Creates a task. $fields are sent as they are (name, markdown_content, assignees,
     * priority, due_date, due_date_time, see E10).
     *
     * @param  array<string, mixed>  $fields
     * @return array{id: string, url: string}
     */
    public function createTask(string $listId, array $fields): array
    {
        $path = 'list/'.rawurlencode($listId).'/task';
        $task = $this->send('POST', $path, fn (): array => ['json' => $fields])->json();
        $id = is_array($task) ? ($task['id'] ?? null) : null;

        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
            // Created, but without a usable id: treat as uncertain, never as "not created".
            throw new ClickUpException(0, 'unexpected_response', 'POST', $path);
        }

        $url = is_string($task['url'] ?? null) && str_starts_with($task['url'], 'https://') ? $task['url'] : null;

        return ['id' => $id, 'url' => $url ?? 'https://app.clickup.com/t/'.$id];
    }

    /**
     * Uploads a file as task attachment (multipart field "attachment"). The file is streamed
     * from disk and never read into memory as a whole (R5). A 429 reopens the file for the
     * next attempt.
     */
    public function attach(string $taskId, string $absolutePath, string $filename): void
    {
        $handles = [];

        try {
            $this->send('POST', 'task/'.rawurlencode($taskId).'/attachment', function () use ($absolutePath, $filename, &$handles): array {
                $handle = @fopen($absolutePath, 'rb');

                if ($handle === false) {
                    throw new RuntimeException('Cannot open the attachment file for the upload.');
                }

                $handles[] = $handle;

                return ['multipart' => [['name' => 'attachment', 'contents' => $handle, 'filename' => $filename]]];
            }, self::UPLOAD_TIMEOUT_SECONDS);
        } finally {
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }
    }

    /**
     * Attachments of a task as ClickUp lists them, to check whether an upload with unclear
     * outcome arrived (E10).
     *
     * @return list<array{name: string, size: int}>
     */
    public function taskAttachments(string $taskId): array
    {
        $task = $this->get('task/'.rawurlencode($taskId));
        $attachments = [];

        foreach (is_array($task['attachments'] ?? null) ? $task['attachments'] : [] as $attachment) {
            if (is_array($attachment)) {
                $attachments[] = [
                    'name' => (string) ($attachment['title'] ?? ''),
                    'size' => (int) ($attachment['size'] ?? -1),
                ];
            }
        }

        return $attachments;
    }

    /**
     * Texts of the comments of a task, to check whether a comment with unclear outcome
     * arrived (E10).
     *
     * @return list<string>
     */
    public function commentTexts(string $taskId): array
    {
        $comments = $this->get('task/'.rawurlencode($taskId).'/comment')['comments'] ?? [];

        return array_values(array_map(
            fn (array $comment): string => (string) ($comment['comment_text'] ?? ''),
            array_filter(is_array($comments) ? $comments : [], 'is_array'),
        ));
    }

    /**
     * Waits up to $maxThrottleWaitSeconds until this token has a free slot. Does not take it:
     * a check before an action is started, so a busy token stops it before anything changes.
     */
    public function waitForCapacity(): bool
    {
        $key = $this->rateKey();
        $waited = 0;

        while (RateLimiter::remaining($key, self::RATE_LIMIT) < 1) {
            if ($waited >= $this->maxThrottleWaitSeconds) {
                return false;
            }

            $seconds = min(max(RateLimiter::availableIn($key), 1), $this->maxThrottleWaitSeconds - $waited);
            Sleep::for($seconds)->seconds();
            $waited += $seconds;
        }

        return true;
    }

    /**
     * Adds a comment to a task without notifying everyone.
     */
    public function comment(string $taskId, string $text): void
    {
        $this->send('POST', 'task/'.rawurlencode($taskId).'/comment', fn (): array => [
            'json' => ['comment_text' => $text, 'notify_all' => false],
        ]);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $json = $this->send('GET', $path, fn (): array => $query === [] ? [] : ['query' => $query])->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>> entries with an id
     */
    private function items(array $json, string $key): array
    {
        $items = is_array($json[$key] ?? null) ? $json[$key] : [];

        return array_values(array_filter(
            $items,
            fn (mixed $item): bool => is_array($item) && (is_string($item['id'] ?? null) || is_int($item['id'] ?? null)),
        ));
    }

    /**
     * @param  Closure(): array<string, mixed>  $options  built per attempt (a stream is used up)
     */
    private function send(string $method, string $path, Closure $options, int $timeout = self::TIMEOUT_SECONDS): Response
    {
        for ($attempt = 1; ; $attempt++) {
            $this->throttle($method, $path);
            $response = $this->request($method, $path, $options(), $timeout);

            if ($response->successful()) {
                return $response;
            }

            if ($response->status() === 401 && $this->onUnauthorized !== null) {
                ($this->onUnauthorized)();
            }

            if ($attempt < self::MAX_ATTEMPTS && $this->isRetryable($method, $response->status())) {
                Sleep::for($this->waitSeconds($response, $attempt))->seconds();

                continue;
            }

            throw new ClickUpException($response->status(), self::errorCode($response), $method, $path);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function request(string $method, string $path, array $options, int $timeout): Response
    {
        $request = Http::withToken($this->token)->acceptJson()->timeout($timeout);

        if (isset($options['multipart'])) {
            $request->asMultipart();
        }

        try {
            return $request->send($method, self::BASE_URL.'/'.$path, $options);
        } catch (ConnectionException) {
            throw new ClickUpException(0, 'connection_failed', $method, $path);
        }
    }

    /**
     * Takes a slot of this token's window, waiting up to $maxThrottleWaitSeconds.
     *
     * Check and count are one step: hit() increments atomically (Redis INCR) and returns the
     * new count, a count above RATE_LIMIT means no slot. RateLimiter::attempt() would check and
     * count in two steps, so two workers could both take the last slot. Rejected hits only
     * raise the count of a window that is full anyway.
     */
    private function throttle(string $method, string $path): void
    {
        $key = $this->rateKey();
        $waited = 0;

        while (RateLimiter::hit($key, 60) > self::RATE_LIMIT) {
            if ($waited >= $this->maxThrottleWaitSeconds) {
                throw new ClickUpException(429, self::LOCAL_RATE_LIMIT, $method, $path);
            }

            $seconds = min(max(RateLimiter::availableIn($key), 1), $this->maxThrottleWaitSeconds - $waited);
            Sleep::for($seconds)->seconds();
            $waited += $seconds;
        }
    }

    /**
     * A hash, the token never goes into the cache.
     */
    private function rateKey(): string
    {
        return 'clickup-rate:'.hash('sha256', $this->token);
    }

    private function isRetryable(string $method, int $status): bool
    {
        return $status === 429 || ($method === 'GET' && in_array($status, [503, 504], true));
    }

    /**
     * X-RateLimit-Reset is the Unix time when the limit resets, Retry-After a number of
     * seconds. Without either: 2, 4, 8 seconds.
     */
    private function waitSeconds(Response $response, int $attempt): int
    {
        $reset = (int) $response->header('X-RateLimit-Reset');
        $retryAfter = (int) $response->header('Retry-After');

        $seconds = match (true) {
            $reset > 0 => $reset - now()->getTimestamp(),
            $retryAfter > 0 => $retryAfter,
            default => 2 ** $attempt,
        };

        return min(max($seconds, 1), self::MAX_WAIT_SECONDS);
    }

    private static function errorCode(Response $response): ?string
    {
        $code = $response->json('ECODE');

        return is_string($code) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $code) === 1 ? $code : null;
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private static function personName(array $user): string
    {
        foreach (['username', 'email'] as $field) {
            if (is_string($user[$field] ?? null) && trim($user[$field]) !== '') {
                return trim($user[$field]);
            }
        }

        return '#'.$user['id'];
    }
}
