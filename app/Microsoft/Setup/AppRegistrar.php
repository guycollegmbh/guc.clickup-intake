<?php

namespace App\Microsoft\Setup;

use App\Microsoft\GraphClient;
use App\Microsoft\GraphException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Creates or updates the Entra app registration with the setup admin's token (E1).
 *
 * Idempotent: a second run finds the app by display name and brings it to the target state, so a
 * run that failed half-way can simply be repeated.
 */
class AppRegistrar
{
    public const DISPLAY_NAME = 'GUYCOLLE ClickUp-Intake';

    public const GRAPH_APP_ID = '00000003-0000-0000-c000-000000000000';

    public const SECRET_LIFETIME_MONTHS = 24;

    /** @var Closure(string): void */
    private Closure $log;

    public function __construct()
    {
        $this->log = fn (string $line) => null;
    }

    /**
     * @param  list<string>  $scopes  delegated Graph scopes, e.g. Mail.ReadWrite
     * @param  (Closure(string): void)|null  $log  progress output
     */
    public function register(GraphClient $graph, string $tenantId, string $redirectUri, array $scopes, ?Closure $log = null): Registration
    {
        $this->log = $log ?? fn (string $line) => null;

        $graphSp = $this->graphServicePrincipal($graph);
        $resourceAccess = $this->resourceAccess($graphSp, $scopes);

        $app = $this->findApplication($graph);

        if ($app === null) {
            ($this->log)('App-Registration anlegen');
            $app = $graph->post('applications', [
                'displayName' => self::DISPLAY_NAME,
                'signInAudience' => 'AzureADMyOrg',
                'web' => [
                    'redirectUris' => [$redirectUri],
                    'homePageUrl' => rtrim(config('app.url'), '/').'/',
                ],
                'requiredResourceAccess' => [$resourceAccess],
            ]);
        } else {
            ($this->log)('Bestehende App-Registration aktualisieren');
            $redirectUris = array_values(array_unique([...($app['web']['redirectUris'] ?? []), $redirectUri]));
            $graph->patch("applications/{$app['id']}", [
                'web' => ['redirectUris' => $redirectUris],
                'requiredResourceAccess' => [$resourceAccess],
            ]);
        }

        $sp = $this->ensureServicePrincipal($graph, $app['appId']);
        $this->ensureConsent($graph, $sp['id'], $graphSp['id'], $scopes);
        $secret = $this->addSecret($graph, $app['id']);

        return new Registration(
            tenantId: $tenantId,
            clientId: $app['appId'],
            clientSecret: $secret['secretText'],
            secretKeyId: $secret['keyId'],
            secretExpiresAt: CarbonImmutable::parse($secret['endDateTime']),
            appObjectId: $app['id'],
            servicePrincipalId: $sp['id'],
        );
    }

    /**
     * Adds a new secret and removes the previous one. The redirect URI of this installation is
     * added if missing (e.g. first setup was local, now production).
     *
     * @return array{secret: array<string, mixed>, app: array<string, mixed>}
     */
    public function renewSecret(GraphClient $graph, string $clientId, ?string $oldKeyId, string $redirectUri): array
    {
        $this->log = fn (string $line) => null;

        $app = $graph->get("applications(appId='{$clientId}')", ['$select' => 'id,appId,web']);

        $redirectUris = array_values(array_unique([...($app['web']['redirectUris'] ?? []), $redirectUri]));
        $graph->patch("applications/{$app['id']}", ['web' => ['redirectUris' => $redirectUris]]);

        $secret = $this->addSecret($graph, $app['id']);

        if ($oldKeyId !== null) {
            try {
                $graph->post("applications/{$app['id']}/removePassword", ['keyId' => $oldKeyId]);
            } catch (GraphException $e) {
                if (! $e->isNotFound() && $e->status !== 400) {
                    throw $e;
                }
            }
        }

        return ['secret' => $secret, 'app' => $app];
    }

    /**
     * @return array<string, mixed>
     */
    private function graphServicePrincipal(GraphClient $graph): array
    {
        return $graph->get("servicePrincipals(appId='".self::GRAPH_APP_ID."')", [
            '$select' => 'id,appId,oauth2PermissionScopes',
        ]);
    }

    /**
     * Permission ids are looked up by name instead of hard-coded.
     *
     * @param  array<string, mixed>  $graphSp
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    private function resourceAccess(array $graphSp, array $scopes): array
    {
        $ids = collect($graphSp['oauth2PermissionScopes'] ?? [])->pluck('id', 'value');

        $missing = array_diff($scopes, $ids->keys()->all());

        if ($missing !== []) {
            throw new RuntimeException('Unbekannte Graph-Berechtigungen: '.implode(', ', $missing));
        }

        return [
            'resourceAppId' => self::GRAPH_APP_ID,
            'resourceAccess' => array_map(fn (string $scope): array => ['id' => $ids[$scope], 'type' => 'Scope'], $scopes),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findApplication(GraphClient $graph): ?array
    {
        $apps = $graph->get('applications', [
            '$filter' => "displayName eq '".self::DISPLAY_NAME."'",
            '$select' => 'id,appId,web',
        ])['value'] ?? [];

        if (count($apps) > 1) {
            throw new RuntimeException('Es gibt mehrere App-Registrations «'.self::DISPLAY_NAME.'». Bitte überzählige im Entra-Portal löschen.');
        }

        return $apps[0] ?? null;
    }

    /**
     * «Zuweisung erforderlich»: only users or groups assigned to the enterprise app can sign in (E3).
     *
     * @return array<string, mixed>
     */
    private function ensureServicePrincipal(GraphClient $graph, string $appId): array
    {
        $existing = $graph->get('servicePrincipals', [
            '$filter' => "appId eq '{$appId}'",
            '$select' => 'id,appId',
        ])['value'][0] ?? null;

        if ($existing !== null) {
            $graph->patch("servicePrincipals/{$existing['id']}", ['appRoleAssignmentRequired' => true]);

            return $existing;
        }

        ($this->log)('Enterprise-App anlegen (Zuweisung erforderlich)');

        // A freshly created application needs a moment until it is visible for this call.
        return $this->retryWhileReplicating(fn () => $graph->post('servicePrincipals', [
            'appId' => $appId,
            'appRoleAssignmentRequired' => true,
            'tags' => ['WindowsAzureActiveDirectoryIntegratedApp'],
        ]));
    }

    /**
     * Tenant-wide admin consent for the delegated scopes, so users are never asked.
     *
     * @param  list<string>  $scopes
     */
    private function ensureConsent(GraphClient $graph, string $spId, string $graphSpId, array $scopes): void
    {
        ($this->log)('Admin-Zustimmung für alle Benutzer erteilen');

        $scope = implode(' ', $scopes);

        $existing = $graph->get('oauth2PermissionGrants', [
            '$filter' => "clientId eq '{$spId}' and consentType eq 'AllPrincipals' and resourceId eq '{$graphSpId}'",
        ])['value'][0] ?? null;

        if ($existing !== null) {
            $graph->patch("oauth2PermissionGrants/{$existing['id']}", ['scope' => $scope]);

            return;
        }

        $this->retryWhileReplicating(fn () => $graph->post('oauth2PermissionGrants', [
            'clientId' => $spId,
            'consentType' => 'AllPrincipals',
            'resourceId' => $graphSpId,
            'scope' => $scope,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function addSecret(GraphClient $graph, string $appObjectId): array
    {
        ($this->log)('Client-Secret erzeugen ('.self::SECRET_LIFETIME_MONTHS.' Monate)');

        return $graph->post("applications/{$appObjectId}/addPassword", [
            'passwordCredential' => [
                'displayName' => 'guc.clickup-intake '.now()->toDateString(),
                'endDateTime' => now()->addMonths(self::SECRET_LIFETIME_MONTHS)->toIso8601ZuluString(),
            ],
        ]);
    }

    /**
     * Entra is eventually consistent: objects created a second ago may answer 400 or 404.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function retryWhileReplicating(Closure $call): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $call();
            } catch (GraphException $e) {
                if ($attempt >= 6 || ! in_array($e->status, [400, 404], true)) {
                    throw $e;
                }

                Sleep::for(5)->seconds();
            }
        }
    }
}
