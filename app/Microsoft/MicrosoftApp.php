<?php

namespace App\Microsoft;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Credentials of the Entra app registration.
 *
 * Only source is the encrypted `settings` table, written by the setup assistant (E1, R11c).
 * There is deliberately no .env fallback, so a configuration is never half from one place and
 * half from another.
 */
class MicrosoftApp
{
    public const SETTING_TENANT_ID = 'microsoft.tenant_id';

    public const SETTING_CLIENT_ID = 'microsoft.client_id';

    public const SETTING_CLIENT_SECRET = 'microsoft.client_secret';

    public function tenantId(): ?string
    {
        $tenantId = $this->read(self::SETTING_TENANT_ID);

        return $tenantId === null ? null : Str::lower($tenantId);
    }

    public function clientId(): ?string
    {
        return $this->read(self::SETTING_CLIENT_ID);
    }

    public function clientSecret(): ?string
    {
        return $this->read(self::SETTING_CLIENT_SECRET);
    }

    /**
     * The tenant must be the GUID: it is compared with the `tid` claim, which is never a domain.
     */
    public function isConfigured(): bool
    {
        return Str::isUuid((string) $this->tenantId())
            && filled($this->clientId())
            && filled($this->clientSecret());
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return config('intake.microsoft.scopes');
    }

    public function redirectUri(): string
    {
        return route('auth.microsoft.callback');
    }

    /**
     * Socialite reads provider config from config('services.azure'). Only used in web requests
     * (login), never in queue workers.
     */
    public function configureSocialite(): void
    {
        config(['services.azure' => [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect' => $this->redirectUri(),
            'tenant' => $this->tenantId(),
        ]]);
    }

    /**
     * A value that cannot be decrypted (APP_KEY changed, e.g. after a restore on a new host)
     * counts as missing: the login page then asks for the setup instead of failing with 500.
     */
    private function read(string $key): ?string
    {
        try {
            return Setting::get($key);
        } catch (DecryptException) {
            Log::warning('Microsoft setting cannot be decrypted, APP_KEY changed?', ['key' => $key]);

            return null;
        }
    }
}
