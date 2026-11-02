<?php

namespace App\Microsoft;

use App\Microsoft\Setup\Registration;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SensitiveParameter;

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

    public const SETTING_SECRET_KEY_ID = 'microsoft.client_secret_key_id';

    public const SETTING_SECRET_EXPIRES_AT = 'microsoft.client_secret_expires_at';

    public const SETTING_APP_OBJECT_ID = 'microsoft.app_object_id';

    public const SETTING_SERVICE_PRINCIPAL_ID = 'microsoft.service_principal_id';

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

    public function secretKeyId(): ?string
    {
        return $this->read(self::SETTING_SECRET_KEY_ID);
    }

    public function secretExpiresAt(): ?CarbonImmutable
    {
        $value = $this->read(self::SETTING_SECRET_EXPIRES_AT);

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    public function servicePrincipalId(): ?string
    {
        return $this->read(self::SETTING_SERVICE_PRINCIPAL_ID);
    }

    public function store(Registration $registration): void
    {
        DB::transaction(function () use ($registration): void {
            Setting::put(self::SETTING_TENANT_ID, $registration->tenantId);
            Setting::put(self::SETTING_CLIENT_ID, $registration->clientId);
            Setting::put(self::SETTING_APP_OBJECT_ID, $registration->appObjectId);
            Setting::put(self::SETTING_SERVICE_PRINCIPAL_ID, $registration->servicePrincipalId);
            $this->storeSecret($registration->clientSecret, $registration->secretKeyId, $registration->secretExpiresAt);
        });
    }

    public function storeSecret(#[SensitiveParameter] string $secret, string $keyId, CarbonImmutable $expiresAt): void
    {
        DB::transaction(function () use ($secret, $keyId, $expiresAt): void {
            Setting::put(self::SETTING_CLIENT_SECRET, $secret);
            Setting::put(self::SETTING_SECRET_KEY_ID, $keyId);
            Setting::put(self::SETTING_SECRET_EXPIRES_AT, $expiresAt->toIso8601String());
        });
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

    /**
     * Built from APP_URL, not from the request: login and setup always use the same URI, and a
     * spoofed Host header cannot change it.
     */
    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').route('auth.microsoft.callback', absolute: false);
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
