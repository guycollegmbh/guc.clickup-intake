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
 * Security rules (review 1b):
 * - An existing registration is only ever found by the object id this installation stored
 *   itself, never by display name. Otherwise anyone allowed to register apps could pre-create
 *   one with their own redirect URI and secret and have it granted tenant-wide mail access.
 * - Redirect URIs are set to exactly this installation's URI, not merged.
 * - After a new secret is stored, all other secrets of the registration are removed.
 */
class AppRegistrar
{
    public const BASE_DISPLAY_NAME = 'GUYCOLLE ClickUp-Intake';

    public const GRAPH_APP_ID = '00000003-0000-0000-c000-000000000000';

    public const SECRET_LIFETIME_MONTHS = 24;

    /** @var Closure(string): void */
    private Closure $log;

    public function __construct()
    {
        $this->log = fn (string $line) => null;
    }

    /**
     * One registration per environment, so a laptop never holds a secret of production.
     */
    public static function displayName(): string
    {
        $env = app()->environment();

        return $env === 'production' ? self::BASE_DISPLAY_NAME : self::BASE_DISPLAY_NAME." ({$env})";
    }

    /**
     * @param  list<string>  $scopes  delegated Graph scopes, e.g. Mail.ReadWrite
     * @param  string|null  $knownAppObjectId  stored by an earlier (possibly aborted) run
     * @param  Closure(string, string): void  $remember  persists object id and client id right after
     *                                                   creation, so an aborted run resumes instead of creating a second app
     * @param  (Closure(string): void)|null  $log  progress output
     */
    public function register(
        GraphClient $graph,
        string $tenantId,
        string $redirectUri,
        array $scopes,
        ?string $knownAppObjectId,
        Closure $remember,
        ?Closure $log = null,
    ): Registration {
        $this->log = $log ?? fn (string $line) => null;

        $graphSp = $this->graphServicePrincipal($graph);
        $resourceAccess = $this->resourceAccess($graphSp, $scopes);
        $target = [
            'signInAudience' => 'AzureADMyOrg',
            'web' => $this->web($redirectUri),
            'requiredResourceAccess' => [$resourceAccess],
        ];

        $app = $knownAppObjectId === null ? null : $this->findOwnApplication($graph, $knownAppObjectId);

        if ($app === null) {
            ($this->log)('App-Registration «'.self::displayName().'» anlegen');
            $app = $graph->post('applications', ['displayName' => self::displayName(), ...$target]);
            $remember($app['id'], $app['appId']);
        } else {
            ($this->log)('Eigene App-Registration aus früherem Lauf aktualisieren');
            $graph->patch("applications/{$app['id']}", ['displayName' => self::displayName(), ...$target]);
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
     * Adds a new secret and sets the redirect URI to this installation's. The old secrets are
     * removed separately with removeOtherSecrets(), after the new one is stored.
     *
     * @return array<string, mixed> the new password credential incl. secretText
     */
    public function renewSecret(GraphClient $graph, string $clientId, string $redirectUri): array
    {
        $app = $graph->get("applications(appId='{$clientId}')", ['$select' => 'id,appId']);
        $graph->patch("applications/{$app['id']}", ['web' => $this->web($redirectUri)]);

        return $this->addSecret($graph, $app['id']);
    }

    /**
     * Removes every secret except the one in use. Called only after the new secret is stored, so
     * an abort never leaves the installation with a secret that no longer exists in Entra.
     *
     * @return int number of removed secrets
     */
    public function removeOtherSecrets(GraphClient $graph, string $clientId, string $keepKeyId): int
    {
        $app = $graph->get("applications(appId='{$clientId}')", ['$select' => 'id,passwordCredentials']);
        $removed = 0;

        foreach ($app['passwordCredentials'] ?? [] as $credential) {
            if ($credential['keyId'] === $keepKeyId) {
                continue;
            }

            $graph->post("applications/{$app['id']}/removePassword", ['keyId' => $credential['keyId']]);
            $removed++;
        }

        return $removed;
    }

    /**
     * @return array<string, mixed>
     */
    private function web(string $redirectUri): array
    {
        // `web` is replaced as a whole by PATCH, so it is always sent complete.
        return [
            'redirectUris' => [$redirectUri],
            'homePageUrl' => rtrim((string) config('app.url'), '/').'/',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOwnApplication(GraphClient $graph, string $appObjectId): ?array
    {
        try {
            return $graph->get("applications/{$appObjectId}", ['$select' => 'id,appId']);
        } catch (GraphException $e) {
            if ($e->isNotFound()) {
                return null; // deleted in the portal meanwhile: create a new one
            }

            throw $e;
        }
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
     * «Zuweisung erforderlich»: only users or groups assigned to the enterprise app can sign in (E3).
     *
     * @return array<string, mixed>
     */
    private function ensureServicePrincipal(GraphClient $graph, string $appId): array
    {
        $existing = $this->findServicePrincipal($graph, $appId);

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
     * @return array<string, mixed>|null
     */
    private function findServicePrincipal(GraphClient $graph, string $appId): ?array
    {
        return $graph->get('servicePrincipals', [
            '$filter' => "appId eq '{$appId}'",
            '$select' => 'id,appId',
        ])['value'][0] ?? null;
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
     * Entra is eventually consistent: objects created a second ago may answer 404 or a generic
     * 400. Other errors fail immediately.
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
                $replicating = $e->status === 404
                    || ($e->status === 400 && in_array($e->graphCode, ['Request_BadRequest', 'Request_ResourceNotFound'], true));

                if ($attempt >= 4 || ! $replicating) {
                    throw $e;
                }

                Sleep::for(5)->seconds();
            }
        }
    }
}
