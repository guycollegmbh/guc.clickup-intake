<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Microsoft\GraphClient;
use App\Microsoft\GraphException;
use App\Microsoft\MicrosoftApp;
use App\Microsoft\Setup\AdminToken;
use App\Microsoft\Setup\AppRegistrar;
use App\Microsoft\Setup\DeviceCodeLogin;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Setup assistant «Mit Microsoft 365 verbinden» (docs/01-decisions.md E1).
 *
 * A Microsoft 365 admin signs in once with a device code. The command then creates the app
 * registration, the enterprise app with «assignment required», the tenant-wide consent for the
 * delegated scopes and a client secret, stores everything encrypted in `settings` and makes the
 * admin the first app admin (by object id, never by e-mail).
 */
#[Signature('intake:install
    {--renew-secret : Replace the client secret and set this installation\'s redirect URI}
    {--manual : Enter tenant id, client id and secret of a manually created app registration}
    {--force : Run the setup although the app is already configured}')]
#[Description('Connect the app to Microsoft 365 (setup assistant)')]
class Install extends Command
{
    public function handle(MicrosoftApp $app, DeviceCodeLogin $deviceCode, AppRegistrar $registrar): int
    {
        try {
            return match (true) {
                (bool) $this->option('manual') => $this->manual($app),
                (bool) $this->option('renew-secret') => $this->renewSecret($app, $deviceCode, $registrar),
                default => $this->install($app, $deviceCode, $registrar),
            };
        } catch (RuntimeException|ConnectionException $e) {
            // GraphException and setup errors carry no tokens or response bodies (R11c).
            $this->newLine();
            $this->error($e->getMessage());

            if ($e instanceof GraphException && $e->status === 403) {
                $this->line('Das Konto braucht die Rolle «Globaler Administrator» oder «Cloudanwendungsadministrator».');
            }

            $this->line('Der Lauf kann gefahrlos wiederholt werden.');

            return self::FAILURE;
        }
    }

    private function install(MicrosoftApp $app, DeviceCodeLogin $deviceCode, AppRegistrar $registrar): int
    {
        if ($app->isConfigured() && ! $this->option('force')) {
            $this->warn('Die App ist bereits mit Microsoft 365 verbunden.');
            $this->line('Secret erneuern: php artisan intake:install --renew-secret');

            return self::SUCCESS;
        }

        $redirectUri = $app->redirectUri();
        $this->checkRedirectUri($redirectUri);

        $this->info('Einrichtung ClickUp-Intake: Verbindung mit Microsoft 365');
        $this->line('Nötig ist ein Konto mit der Rolle «Globaler Administrator» oder «Cloudanwendungsadministrator».');
        $this->line('Bei der Anmeldung «Im Namen der Organisation zustimmen» NICHT ankreuzen.');
        $this->line("Rücksprung-Adresse: {$redirectUri}");
        $this->newLine();

        $admin = $this->signInAdmin($deviceCode);

        $configuredTenant = $app->tenantId();
        if ($configuredTenant !== null && $configuredTenant !== $admin->tenantId) {
            throw new RuntimeException('Das Admin-Konto gehört zu einem anderen Tenant als die bestehende Einrichtung.');
        }

        if (! $this->confirm("Im Tenant {$admin->tenantId} die App-Registration «".AppRegistrar::displayName().'» einrichten?', true)) {
            $this->line('Abgebrochen, nichts geändert.');

            return self::FAILURE;
        }

        $graph = new GraphClient($admin->accessToken);

        $registration = $registrar->register(
            graph: $graph,
            tenantId: $admin->tenantId,
            redirectUri: $redirectUri,
            scopes: $app->scopes(),
            knownAppObjectId: $app->appObjectId(),
            remember: fn (string $objectId, string $clientId) => $app->rememberApplication($admin->tenantId, $objectId, $clientId),
            log: fn (string $line) => $this->line("  · {$line}"),
        );

        $app->store($registration);
        $this->removeOtherSecrets($registrar, $graph, $registration->clientId, $registration->secretKeyId);

        $firstAdmin = $this->makeFirstAdmin($admin);

        $this->newLine();
        $this->info('Fertig. Die App ist mit Microsoft 365 verbunden.');
        if ($firstAdmin !== null) {
            $this->line("Erster Admin: {$firstAdmin->name} ({$firstAdmin->mailbox_upn})");
        }
        $this->line('Secret gültig bis: '.$registration->secretExpiresAt->timezone(config('intake.display_timezone'))->format('d.m.Y'));
        $this->newLine();
        $this->line('Nächster Schritt: der Enterprise-App die Gruppe zuweisen (Benutzer und Gruppen, Benutzer/Gruppe hinzufügen).');
        $this->line('Ohne Zuweisung kann sich niemand anmelden, auch der Admin nicht.');
        $this->line($this->enterpriseAppUrl($registration->servicePrincipalId, $registration->clientId));
        $this->line('Microsoft braucht danach bis zu einigen Minuten, bis die Anmeldung funktioniert.');

        return self::SUCCESS;
    }

    private function renewSecret(MicrosoftApp $app, DeviceCodeLogin $deviceCode, AppRegistrar $registrar): int
    {
        $clientId = $app->clientId();

        if (blank($clientId)) {
            $this->error('Die App ist noch nicht eingerichtet. Zuerst: php artisan intake:install');

            return self::FAILURE;
        }

        $redirectUri = $app->redirectUri();
        $this->checkRedirectUri($redirectUri);

        $this->info('Client-Secret erneuern');
        $admin = $this->signInAdmin($deviceCode);

        if ($admin->tenantId !== $app->tenantId()) {
            throw new RuntimeException('Das Admin-Konto gehört zu einem anderen Tenant als die App.');
        }

        $graph = new GraphClient($admin->accessToken);
        $secret = $registrar->renewSecret($graph, $clientId, $redirectUri);

        // Order matters: store the new secret first, only then remove the old ones.
        $app->storeSecret($secret['secretText'], $secret['keyId'], CarbonImmutable::parse($secret['endDateTime']));
        $this->info('Neues Secret gespeichert. Rücksprung-Adresse: '.$redirectUri);
        $this->line('Gültig bis: '.$app->secretExpiresAt()?->timezone(config('intake.display_timezone'))->format('d.m.Y'));

        $this->removeOtherSecrets($registrar, $graph, $clientId, $secret['keyId']);

        return self::SUCCESS;
    }

    /**
     * Fallback when the device code flow is blocked: values of a registration created by hand
     * (docs/02-setup.md). Still stored only in `settings` (E1).
     */
    private function manual(MicrosoftApp $app): int
    {
        if ($app->isConfigured() && ! $this->option('force')) {
            $this->warn('Die App ist bereits mit Microsoft 365 verbunden. Überschreiben nur mit --force.');

            return self::FAILURE;
        }

        $this->line('Werte der App-Registration aus dem Entra-Portal (Übersicht und «Zertifikate & Geheimnisse»).');
        $this->line('Rücksprung-Adresse dort eintragen: '.$app->redirectUri());

        $tenantId = Str::lower(trim((string) $this->ask('Verzeichnis-ID (Tenant-ID)')));
        $clientId = trim((string) $this->ask('Anwendungs-ID (Client-ID)'));
        $secret = (string) $this->secret('Wert des Client-Secrets');
        $expires = trim((string) $this->ask('Secret gültig bis (JJJJ-MM-TT)'));

        if (! Str::isUuid($tenantId) || ! Str::isUuid($clientId) || blank($secret)) {
            $this->error('Tenant-ID und Client-ID müssen GUIDs sein, das Secret darf nicht leer sein.');

            return self::FAILURE;
        }

        try {
            $expiresAt = CarbonImmutable::createFromFormat('!Y-m-d', $expires);
        } catch (Throwable) {
            $expiresAt = false;
        }

        if ($expiresAt === false || $expiresAt->isPast()) {
            $this->error('Das Ablaufdatum muss ein künftiges Datum im Format JJJJ-MM-TT sein.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($app, $tenantId, $clientId, $secret, $expiresAt): void {
            Setting::put(MicrosoftApp::SETTING_TENANT_ID, $tenantId);
            Setting::put(MicrosoftApp::SETTING_CLIENT_ID, $clientId);
            $app->storeSecret($secret, 'manual', $expiresAt);
        });

        $this->info('Gespeichert. Erster Admin nach dem ersten Login: php artisan intake:make-admin {email}');

        return self::SUCCESS;
    }

    private function signInAdmin(DeviceCodeLogin $deviceCode): AdminToken
    {
        $admin = $deviceCode->signIn(function (string $message): void {
            $this->warn($message);
            $this->line('Warte auf die Anmeldung …');
        });

        $this->line("Angemeldet als {$admin->name} ({$admin->upn})");

        return $admin;
    }

    /**
     * Old or orphaned secrets (earlier runs, aborted runs) are removed once the new one is stored.
     * A failure here is not fatal: the app works, the old secrets just stay until the next run.
     */
    private function removeOtherSecrets(AppRegistrar $registrar, GraphClient $graph, string $clientId, string $keepKeyId): void
    {
        try {
            $removed = $registrar->removeOtherSecrets($graph, $clientId, $keepKeyId);

            if ($removed > 0) {
                $this->line("  · {$removed} älteres Secret entfernt");
            }
        } catch (GraphException $e) {
            $this->warn('Ältere Secrets konnten nicht entfernt werden ('.$e->status.'). Im Entra-Portal prüfen.');
        }
    }

    /**
     * The admin is matched by object id. After confirmation created or promoted to admin, so the
     * first login works without make-admin. A deactivated user is not silently reactivated.
     */
    private function makeFirstAdmin(AdminToken $admin): ?User
    {
        $user = User::query()->firstWhere('entra_object_id', $admin->objectId);

        if ($user?->isAdmin()) {
            return $user;
        }

        if ($user !== null && ! $user->active) {
            $this->warn("{$user->name} ist in der App deaktiviert und wird nicht automatisch Admin.");

            return null;
        }

        if (! $this->confirm("{$admin->name} ({$admin->upn}) wird App-Admin und sieht die Mails aller Benutzer in der Queue. Einverstanden?", true)) {
            $this->line('Kein Admin gesetzt. Später: php artisan intake:make-admin {email}');

            return null;
        }

        $user ??= new User([
            'name' => $admin->name,
            'email' => $admin->upn,
            'mailbox_upn' => $admin->upn,
            'entra_object_id' => $admin->objectId,
        ]);

        $user->role = Role::Admin;
        $user->active = true;
        $user->save();

        return $user;
    }

    private function checkRedirectUri(string $redirectUri): void
    {
        $host = parse_url($redirectUri, PHP_URL_HOST);
        $isLocal = in_array($host, ['localhost', '127.0.0.1'], true);

        if (! $isLocal && ! str_starts_with($redirectUri, 'https://')) {
            throw new RuntimeException("Die Rücksprung-Adresse muss https verwenden: {$redirectUri}. APP_URL prüfen.");
        }
    }

    private function enterpriseAppUrl(string $servicePrincipalId, string $clientId): string
    {
        return 'https://entra.microsoft.com/#view/Microsoft_AAD_IAM/ManagedAppMenuBlade/~/Users/objectId/'
            .$servicePrincipalId.'/appId/'.$clientId;
    }
}
