<?php

namespace App\ClickUp;

use App\Enums\ConnectionStatus;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * The only place that stores, hands out and drops a user's ClickUp token (R4, E2, R11b).
 *
 * client() gives a ClickUpClient bound to the user's token, only for active users with a
 * working connection. A 401 marks the connection broken and drops the token, the user connects
 * again. The list hierarchy and the members of the app workspace are cached per user for an
 * hour, each user sees only what ClickUp shows them.
 *
 * Logs carry ids and error codes only, never tokens or response text (R11c).
 */
class UserClickUp
{
    public const CACHE_SECONDS = 3600;

    public function __construct(private ClickUpApp $app) {}

    public function isConnected(User $user): bool
    {
        return $user->active
            && $user->clickup_connection_status === ConnectionStatus::Connected
            && filled($user->clickup_token);
    }

    /**
     * $interactive: for web requests, waits only a few seconds for a free rate limit slot.
     */
    public function client(User $user, bool $interactive = false): ClickUpClient
    {
        if (! $user->active) {
            throw new ClickUpNotConnected($user->id, 'inactive');
        }

        if (! $this->isConnected($user)) {
            throw new ClickUpNotConnected($user->id, 'not_connected');
        }

        $token = (string) $user->clickup_token;

        return new ClickUpClient(
            $token,
            fn () => $this->markBroken($user, $token),
            $interactive ? ClickUpClient::INTERACTIVE_WAIT_SECONDS : ClickUpClient::JOB_WAIT_SECONDS,
        );
    }

    /**
     * Completes the OAuth flow: exchanges the code, reads the ClickUp user and checks that the
     * token reaches the app workspace (E2). Nothing is stored unless all of that succeeds.
     * Before the workspace is chosen, only admins can connect (they choose it afterwards).
     *
     * @throws ClickUpConnectFailed with a German text for the user
     */
    public function connect(User $user, #[SensitiveParameter] string $code): void
    {
        if (! $user->active) {
            throw new ClickUpConnectFailed('Dein Benutzer ist deaktiviert.');
        }

        $teamId = $this->app->teamId();

        if (! $this->app->hasCredentials() || ($teamId === null && ! $user->isAdmin())) {
            throw new ClickUpConnectFailed('ClickUp ist noch nicht eingerichtet. Bitte einen Admin fragen.');
        }

        try {
            $token = ClickUpClient::exchangeCode((string) $this->app->clientId(), (string) $this->app->clientSecret(), $code);
            $client = new ClickUpClient($token);
            $clickUpUser = $client->user();
            $teamIds = array_column($client->teams(), 'id');
        } catch (ClickUpException $e) {
            Log::warning('ClickUp connection failed.', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'status' => $e->status,
                'error_code' => $e->errorCode,
            ]);

            throw new ClickUpConnectFailed('Die Verbindung mit ClickUp ist fehlgeschlagen. Bitte erneut versuchen.');
        }

        if ($teamId !== null && ! in_array($teamId, $teamIds, true)) {
            Log::notice('ClickUp connection refused, the account has no access to the app workspace.', ['user_id' => $user->id]);

            throw new ClickUpConnectFailed('Dein ClickUp-Konto hat keinen Zugriff auf den Workspace «'.$this->app->teamName().'». Bitte mit dem richtigen Konto verbinden.');
        }

        $this->store($user, $token, $clickUpUser['id']);
    }

    public function disconnect(User $user): void
    {
        $user->clickup_token = null;
        $user->clickup_connection_status = ConnectionStatus::Disconnected;
        $user->save();

        $this->forget($user);
    }

    /**
     * Lists of the app workspace the user can see, id => "Space / Folder / List".
     * Empty while no workspace is chosen.
     *
     * @return array<string, string>
     */
    public function lists(User $user): array
    {
        $teamId = $this->app->teamId();

        if ($teamId === null) {
            return [];
        }

        $client = $this->client($user, interactive: true);
        $lists = Cache::remember(self::cacheKey('lists', $user, $teamId), self::CACHE_SECONDS, fn (): array => $client->hierarchy($teamId));

        return array_column($lists, 'label', 'id');
    }

    /**
     * Lists from the cache only, never loaded from ClickUp (empty if not cached).
     *
     * @return array<string, string>
     */
    public function cachedLists(User $user): array
    {
        $teamId = $this->app->teamId();
        $lists = $teamId === null ? null : Cache::get(self::cacheKey('lists', $user, $teamId));

        return is_array($lists) ? array_column($lists, 'label', 'id') : [];
    }

    /**
     * Members of the app workspace, ClickUp user id => name.
     *
     * @return array<int, string>
     */
    public function members(User $user): array
    {
        $teamId = $this->app->teamId();

        if ($teamId === null) {
            return [];
        }

        $client = $this->client($user, interactive: true);
        $members = Cache::remember(self::cacheKey('members', $user, $teamId), self::CACHE_SECONDS, fn (): array => $client->members($teamId));

        return array_column($members, 'name', 'id');
    }

    /**
     * Workspaces of the user's own ClickUp account, id => name (admin page, not cached).
     *
     * @return array<string, string>
     */
    public function teams(User $user): array
    {
        return array_column($this->client($user, interactive: true)->teams(), 'name', 'id');
    }

    /**
     * "ClickUp-Listen neu laden": the next call reads hierarchy and members again.
     */
    public function forget(User $user): void
    {
        $teamId = $this->app->teamId();

        if ($teamId !== null) {
            Cache::forget(self::cacheKey('lists', $user, $teamId));
            Cache::forget(self::cacheKey('members', $user, $teamId));
        }
    }

    private function store(User $user, #[SensitiveParameter] string $token, int $memberId): void
    {
        $user->clickup_token = $token;
        $user->clickup_connection_status = ConnectionStatus::Connected;
        $user->clickup_member_id = (string) $memberId;

        // Written only while the user is active: a deactivation in the meantime wins.
        $stored = User::query()
            ->whereKey($user->getKey())
            ->where('active', true)
            ->update($user->getDirty()) === 1;

        $user->refresh();

        if (! $stored) {
            throw new ClickUpConnectFailed('Dein Benutzer ist deaktiviert.');
        }

        $this->forget($user);
    }

    /**
     * ClickUp rejected the token (401). Only the token that was rejected is dropped: if the user
     * connected again in the meantime, the new one stays.
     */
    private function markBroken(User $user, #[SensitiveParameter] string $token): void
    {
        $user->refresh();

        if ($user->clickup_token !== $token) {
            return;
        }

        $user->clickup_token = null;
        $user->clickup_connection_status = ConnectionStatus::Broken;
        $user->save();
        $this->forget($user);

        Log::warning('ClickUp connection broken, the user has to connect again.', ['user_id' => $user->id]);
    }

    private static function cacheKey(string $kind, User $user, string $teamId): string
    {
        return "clickup:{$kind}:{$user->id}:".hash('xxh128', $teamId);
    }
}
