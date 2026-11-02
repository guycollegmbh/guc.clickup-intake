<?php

namespace App\Microsoft;

use App\Enums\ConnectionStatus;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * The only place that renews a user's delegated Microsoft token (R4, E1).
 *
 * The access token is cached encrypted on the user and reused until shortly before it expires.
 * Renewal runs under a cache lock per user: Microsoft rotates the refresh token, and two jobs
 * refreshing in parallel would otherwise overwrite each other's new refresh token.
 *
 * A rejected refresh token marks the connection broken (MicrosoftConnectionBroken), the next
 * sign-in repairs it. Everything else (5xx, network, lock timeout, app credentials) throws
 * MicrosoftTokenUnavailable and leaves the status alone. Logs carry ids and OAuth error codes
 * only, never tokens or response text (R11c).
 */
class UserTokens
{
    /**
     * Renew this long before expiry, so a token is still valid for a whole poll run.
     */
    private const EXPIRY_BUFFER_SECONDS = 600;

    private const LOCK_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 20;

    /**
     * OAuth errors that only a new interactive sign-in can fix.
     */
    private const PERMANENT_ERRORS = ['invalid_grant', 'interaction_required', 'consent_required'];

    /**
     * Prefer header for every mailbox call: ids stay stable when a mail is moved (R7).
     */
    public const PREFER_IMMUTABLE_ID = 'IdType="ImmutableId"';

    public function __construct(private MicrosoftApp $app) {}

    /**
     * Graph client for the user's own mailbox (/me, E6).
     */
    public function graphFor(User $user): GraphClient
    {
        return new GraphClient($this->accessToken($user), [self::PREFER_IMMUTABLE_ID]);
    }

    public function accessToken(User $user): string
    {
        $this->assertConnected($user);

        if ($this->isUsable($user)) {
            return (string) $user->ms_access_token;
        }

        try {
            return Cache::lock('microsoft-token:'.$user->id, self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, function () use ($user): string {
                    // Another job may have renewed it while we waited for the lock.
                    $user->refresh();
                    $this->assertConnected($user);

                    return $this->isUsable($user) ? (string) $user->ms_access_token : $this->renew($user);
                });
        } catch (LockTimeoutException) {
            throw new MicrosoftTokenUnavailable($user->id, 'lock_timeout');
        }
    }

    private function renew(User $user): string
    {
        if (blank($user->ms_refresh_token)) {
            $this->markBroken($user, 'no_refresh_token');
        }

        if (! $this->app->isConfigured()) {
            throw new MicrosoftTokenUnavailable($user->id, 'app_not_configured');
        }

        try {
            $response = Http::asForm()->timeout(30)->post($this->tokenUrl(), [
                'grant_type' => 'refresh_token',
                'client_id' => $this->app->clientId(),
                'client_secret' => $this->app->clientSecret(),
                'refresh_token' => $user->ms_refresh_token,
                'scope' => implode(' ', $this->app->scopes()),
            ]);
        } catch (ConnectionException) {
            throw new MicrosoftTokenUnavailable($user->id, 'connection_failed');
        }

        $error = $response->json('error');
        $error = is_string($error) ? $error : null;

        if ($response->successful() && is_string($response->json('access_token'))) {
            return $this->store(
                $user,
                $response->json('access_token'),
                $response->json('refresh_token'),
                (int) $response->json('expires_in', 3600),
            );
        }

        if ($response->clientError() && in_array($error, self::PERMANENT_ERRORS, true)) {
            $this->markBroken($user, $error);
        }

        // invalid_client (expired or replaced secret) is a problem of the app, not of the
        // user: marking every user broken would force everyone to sign in again after the fix.
        Log::warning('Microsoft token refresh failed.', [
            'user_id' => $user->id,
            'status' => $response->status(),
            'error' => $error,
        ]);

        throw new MicrosoftTokenUnavailable($user->id, $error ?? 'http_'.$response->status());
    }

    private function store(
        User $user,
        #[SensitiveParameter] string $accessToken,
        #[SensitiveParameter] mixed $refreshToken,
        int $expiresIn,
    ): string {
        $user->ms_access_token = $accessToken;
        $user->ms_token_expires_at = now()->addSeconds(max(0, $expiresIn));

        // Microsoft rotates the refresh token. Store the new one right away, the old one may
        // stop working.
        if (is_string($refreshToken) && $refreshToken !== '') {
            $user->ms_refresh_token = $refreshToken;
        }

        $user->save();

        return $accessToken;
    }

    private function markBroken(User $user, string $reason): never
    {
        $user->ms_connection_status = ConnectionStatus::Broken;
        $user->ms_access_token = null;
        $user->ms_token_expires_at = null;
        $user->ms_refresh_token = null;
        $user->save();

        Log::warning('Microsoft connection broken, sign-in required.', [
            'user_id' => $user->id,
            'reason' => $reason,
        ]);

        throw new MicrosoftConnectionBroken($user->id, $reason);
    }

    /**
     * Drops the cached access token, e.g. after Graph answered 401 with it. The next call
     * renews it with the refresh token.
     */
    public function forget(User $user): void
    {
        $user->ms_access_token = null;
        $user->ms_token_expires_at = null;
        $user->save();
    }

    /**
     * A deactivated user gets no token, even if one is still stored.
     */
    private function assertConnected(User $user): void
    {
        if (! $user->active) {
            throw new MicrosoftConnectionBroken($user->id, 'inactive');
        }

        if ($user->ms_connection_status !== ConnectionStatus::Connected) {
            throw new MicrosoftConnectionBroken($user->id, 'not_connected');
        }
    }

    private function isUsable(User $user): bool
    {
        return filled($user->ms_access_token)
            && $user->ms_token_expires_at !== null
            && $user->ms_token_expires_at->isAfter(now()->addSeconds(self::EXPIRY_BUFFER_SECONDS));
    }

    private function tokenUrl(): string
    {
        return 'https://login.microsoftonline.com/'.$this->app->tenantId().'/oauth2/v2.0/token';
    }
}
