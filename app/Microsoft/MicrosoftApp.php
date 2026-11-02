<?php

namespace App\Microsoft;

use App\Models\Setting;

/**
 * Credentials of the Entra app registration.
 *
 * Source of truth is the encrypted `settings` table, written by the setup assistant.
 * config('intake.microsoft.*') is the fallback for a manual registration.
 */
class MicrosoftApp
{
    public const SETTING_TENANT_ID = 'microsoft.tenant_id';

    public const SETTING_CLIENT_ID = 'microsoft.client_id';

    public const SETTING_CLIENT_SECRET = 'microsoft.client_secret';

    public function tenantId(): ?string
    {
        return Setting::get(self::SETTING_TENANT_ID, config('intake.microsoft.tenant_id'));
    }

    public function clientId(): ?string
    {
        return Setting::get(self::SETTING_CLIENT_ID, config('intake.microsoft.client_id'));
    }

    public function clientSecret(): ?string
    {
        return Setting::get(self::SETTING_CLIENT_SECRET, config('intake.microsoft.client_secret'));
    }

    public function isConfigured(): bool
    {
        return filled($this->tenantId()) && filled($this->clientId()) && filled($this->clientSecret());
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
     * Socialite reads provider config from config('services.azure'), so it is set per request.
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
}
