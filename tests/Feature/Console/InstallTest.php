<?php

use App\Enums\Role;
use App\Microsoft\MicrosoftApp;
use App\Microsoft\Setup\AppRegistrar;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const LOGIN = 'https://login.microsoftonline.com/organizations/oauth2/v2.0';
const GRAPH = 'https://graph.microsoft.com/v1.0';

beforeEach(function () {
    Sleep::fake();
    config(['app.url' => 'https://intake.guycolle.services']);
});

function graphScopes(): array
{
    return collect(config('intake.microsoft.scopes'))
        ->map(fn (string $scope, int $i) => ['id' => "perm-{$i}", 'value' => $scope])
        ->push(['id' => 'perm-x', 'value' => 'Calendars.Read'])
        ->all();
}

function fakeDeviceCodeAndToken(string $tenant = TEST_TENANT_ID): array
{
    return [
        LOGIN.'/devicecode' => Http::response([
            'device_code' => 'dc', 'user_code' => 'ABCD', 'interval' => 1, 'expires_in' => 900,
            'message' => 'To sign in, use a web browser to open https://microsoft.com/devicelogin and enter the code ABCD.',
        ]),
        LOGIN.'/token' => Http::sequence()
            ->push(['error' => 'authorization_pending'], 400)
            ->push([
                'access_token' => 'admin-access-token',
                'id_token' => fakeIdToken(['tid' => $tenant, 'oid' => 'oid-admin', 'name' => 'Guy Admin', 'preferred_username' => 'admin@guycolle.ch']),
            ]),
    ];
}

/**
 * @param  array<string, mixed>|null  $existingApp
 */
function fakeGraph(?array $existingApp = null, ?array $existingSp = null, ?array $existingGrant = null): void
{
    Http::fake([
        ...fakeDeviceCodeAndToken(),
        GRAPH."/servicePrincipals(appId='".AppRegistrar::GRAPH_APP_ID."')*" => Http::response(['id' => 'graph-sp', 'oauth2PermissionScopes' => graphScopes()]),
        GRAPH.'/applications?*' => Http::response(['value' => $existingApp ? [$existingApp] : []]),
        GRAPH.'/applications/app-object/addPassword' => Http::response([
            'keyId' => 'key-1', 'secretText' => 'new-secret', 'endDateTime' => '2028-11-02T00:00:00Z',
        ]),
        GRAPH.'/applications/app-object' => Http::response([], 204),
        GRAPH.'/applications' => Http::response(['id' => 'app-object', 'appId' => '22222222-2222-2222-2222-222222222222']),
        GRAPH.'/servicePrincipals?*' => Http::response(['value' => $existingSp ? [$existingSp] : []]),
        GRAPH.'/servicePrincipals/*' => Http::response([], 204),
        GRAPH.'/servicePrincipals' => Http::sequence()
            ->push(['error' => ['code' => 'Request_BadRequest']], 400) // replication delay
            ->push(['id' => 'sp-1', 'appId' => '22222222-2222-2222-2222-222222222222']),
        GRAPH.'/oauth2PermissionGrants?*' => Http::response(['value' => $existingGrant ? [$existingGrant] : []]),
        GRAPH.'/oauth2PermissionGrants/*' => Http::response([], 204),
        GRAPH.'/oauth2PermissionGrants' => Http::response(['id' => 'grant-1']),
    ]);
}

it('registers the app, grants consent and makes the signed-in admin the first admin', function () {
    fakeGraph();

    $this->artisan('intake:install')
        ->expectsOutputToContain('ABCD')
        ->expectsOutputToContain('Fertig')
        ->assertSuccessful();

    $app = app(MicrosoftApp::class);
    expect($app->isConfigured())->toBeTrue()
        ->and($app->tenantId())->toBe(TEST_TENANT_ID)
        ->and($app->clientId())->toBe('22222222-2222-2222-2222-222222222222')
        ->and($app->clientSecret())->toBe('new-secret')
        ->and($app->secretExpiresAt()->toDateString())->toBe('2028-11-02');

    $admin = User::firstWhere('entra_object_id', 'oid-admin');
    expect($admin->role)->toBe(Role::Admin)->and($admin->active)->toBeTrue();

    Http::assertSent(fn (Request $r) => $r->url() === GRAPH.'/applications' && $r->method() === 'POST'
        && $r['signInAudience'] === 'AzureADMyOrg'
        && $r['web']['redirectUris'] === ['https://intake.guycolle.services/auth/microsoft/callback']
        && count($r['requiredResourceAccess'][0]['resourceAccess']) === count(config('intake.microsoft.scopes')));

    Http::assertSent(fn (Request $r) => $r->url() === GRAPH.'/servicePrincipals' && $r->method() === 'POST'
        && $r['appRoleAssignmentRequired'] === true);

    Http::assertSent(fn (Request $r) => $r->url() === GRAPH.'/oauth2PermissionGrants' && $r->method() === 'POST'
        && $r['consentType'] === 'AllPrincipals'
        && $r['scope'] === implode(' ', config('intake.microsoft.scopes')));

    // The admin token is used for Graph but never stored.
    expect(DB::table('settings')->pluck('value')->implode(''))->not->toContain('admin-access-token');
});

it('brings an existing registration to the target state instead of creating a second one', function () {
    fakeGraph(
        existingApp: ['id' => 'app-object', 'appId' => '22222222-2222-2222-2222-222222222222', 'web' => ['redirectUris' => ['http://localhost:8000/auth/microsoft/callback']]],
        existingSp: ['id' => 'sp-1', 'appId' => '22222222-2222-2222-2222-222222222222'],
        existingGrant: ['id' => 'grant-1'],
    );

    $this->artisan('intake:install')->assertSuccessful();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && in_array($r->url(), [
        GRAPH.'/applications', GRAPH.'/servicePrincipals', GRAPH.'/oauth2PermissionGrants',
    ], true));

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === GRAPH.'/applications/app-object'
        && $r['web']['redirectUris'] === [
            'http://localhost:8000/auth/microsoft/callback',
            'https://intake.guycolle.services/auth/microsoft/callback',
        ]);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === GRAPH.'/servicePrincipals/sp-1'
        && $r['appRoleAssignmentRequired'] === true);
});

it('does nothing when already configured', function () {
    configureMicrosoftApp();
    Http::fake();

    $this->artisan('intake:install')->expectsOutputToContain('bereits')->assertSuccessful();

    Http::assertNothingSent();
});

it('refuses a non-https redirect URI outside localhost', function () {
    config(['app.url' => 'http://intake.guycolle.services']);
    Http::fake();

    $this->artisan('intake:install')->assertFailed();

    Http::assertNothingSent();
});

it('explains missing admin rights', function () {
    Http::fake([
        ...fakeDeviceCodeAndToken(),
        GRAPH.'/*' => Http::response(['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'secret detail']], 403),
    ]);

    $this->artisan('intake:install')
        ->expectsOutputToContain('Cloudanwendungsadministrator')
        ->doesntExpectOutputToContain('secret detail')
        ->assertFailed();

    expect(app(MicrosoftApp::class)->isConfigured())->toBeFalse();
});

it('stops when the admin declines the sign-in', function () {
    Http::fake([
        LOGIN.'/devicecode' => Http::response(['device_code' => 'dc', 'interval' => 1, 'expires_in' => 900, 'message' => 'code']),
        LOGIN.'/token' => Http::response(['error' => 'authorization_declined'], 400),
    ]);

    $this->artisan('intake:install')->expectsOutputToContain('abgelehnt')->assertFailed();
});

it('renews the secret and removes the old one', function () {
    configureMicrosoftApp();
    Setting::put(MicrosoftApp::SETTING_SECRET_KEY_ID, 'key-old');

    Http::fake([
        ...fakeDeviceCodeAndToken(),
        GRAPH."/applications(appId='client-id')*" => Http::response(['id' => 'app-object', 'appId' => 'client-id', 'web' => ['redirectUris' => []]]),
        GRAPH.'/applications/app-object/addPassword' => Http::response(['keyId' => 'key-new', 'secretText' => 'renewed', 'endDateTime' => '2028-11-02T00:00:00Z']),
        GRAPH.'/applications/app-object/removePassword' => Http::response([], 204),
        GRAPH.'/applications/app-object' => Http::response([], 204),
    ]);

    $this->artisan('intake:install', ['--renew-secret' => true])->assertSuccessful();

    $app = app(MicrosoftApp::class);
    expect($app->clientSecret())->toBe('renewed')->and($app->secretKeyId())->toBe('key-new');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/removePassword') && $r['keyId'] === 'key-old');
});

it('refuses to renew the secret with an admin of another tenant', function () {
    configureMicrosoftApp();
    Http::fake(fakeDeviceCodeAndToken(tenant: '99999999-9999-9999-9999-999999999999'));

    $this->artisan('intake:install', ['--renew-secret' => true])->assertFailed();

    expect(app(MicrosoftApp::class)->clientSecret())->toBe('client-secret');
});

it('stores a manually created registration in settings', function () {
    $this->artisan('intake:install', ['--manual' => true])
        ->expectsQuestion('Verzeichnis-ID (Tenant-ID)', strtoupper(TEST_TENANT_ID))
        ->expectsQuestion('Anwendungs-ID (Client-ID)', '22222222-2222-2222-2222-222222222222')
        ->expectsQuestion('Wert des Client-Secrets', 'manual-secret')
        ->expectsQuestion('Secret gültig bis (JJJJ-MM-TT)', '2028-01-31')
        ->assertSuccessful();

    expect(app(MicrosoftApp::class)->isConfigured())->toBeTrue()
        ->and(app(MicrosoftApp::class)->tenantId())->toBe(TEST_TENANT_ID);
});
