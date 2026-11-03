<?php

namespace App\ClickUp;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * The ClickUp OAuth app and the one workspace of this installation (E2).
 *
 * Only source is the encrypted `settings` table, written on the admin page "ClickUp". No .env
 * (R11c). Client id and secret come from the OAuth app a ClickUp admin creates by hand.
 */
class ClickUpApp
{
    public const SETTING_CLIENT_ID = 'clickup.client_id';

    public const SETTING_CLIENT_SECRET = 'clickup.client_secret';

    public const SETTING_TEAM_ID = 'clickup.team_id';

    public const SETTING_TEAM_NAME = 'clickup.team_name';

    public const AUTHORIZE_URL = 'https://app.clickup.com/api';

    /**
     * ClickUp tokens do not expire: after disconnecting or deactivating, the authorization in
     * ClickUp stays until it is removed there.
     */
    public const REVOKE_HINT = 'In ClickUp unter Settings, Apps die Freigabe ebenfalls entfernen.';

    public function clientId(): ?string
    {
        return $this->read(self::SETTING_CLIENT_ID);
    }

    public function clientSecret(): ?string
    {
        return $this->read(self::SETTING_CLIENT_SECRET);
    }

    public function teamId(): ?string
    {
        return $this->read(self::SETTING_TEAM_ID);
    }

    public function teamName(): ?string
    {
        return $this->read(self::SETTING_TEAM_NAME);
    }

    /**
     * Users can connect (the OAuth app is known).
     */
    public function hasCredentials(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    /**
     * Ready for tasks: OAuth app and workspace are set.
     */
    public function isConfigured(): bool
    {
        return $this->hasCredentials() && filled($this->teamId());
    }

    /**
     * An empty secret keeps the stored one: the page never shows it again.
     */
    public function storeCredentials(string $clientId, #[SensitiveParameter] ?string $clientSecret): void
    {
        DB::transaction(function () use ($clientId, $clientSecret): void {
            Setting::put(self::SETTING_CLIENT_ID, trim($clientId));

            if (filled($clientSecret)) {
                Setting::put(self::SETTING_CLIENT_SECRET, trim((string) $clientSecret));
            }
        });
    }

    public function storeWorkspace(string $teamId, string $teamName): void
    {
        DB::transaction(function () use ($teamId, $teamName): void {
            Setting::put(self::SETTING_TEAM_ID, $teamId);
            Setting::put(self::SETTING_TEAM_NAME, $teamName);
        });
    }

    /**
     * Built from APP_URL, not from the request (as MicrosoftApp::redirectUri()). The admin
     * enters exactly this URL in the ClickUp OAuth app.
     */
    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').route('clickup.callback', absolute: false);
    }

    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    /**
     * A value that cannot be decrypted (APP_KEY changed) counts as missing.
     */
    private function read(string $key): ?string
    {
        try {
            return Setting::get($key);
        } catch (DecryptException) {
            Log::warning('ClickUp setting cannot be decrypted, APP_KEY changed?', ['key' => $key]);

            return null;
        }
    }
}
