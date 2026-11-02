<?php

use App\Enums\Role;
use App\Microsoft\MicrosoftApp;
use App\Microsoft\Setup\AppRegistrar;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\PendingCommand;

const LOGIN = 'https://login.microsoftonline.com/organizations/oauth2/v2.0';
const GRAPH = 'https://graph.microsoft.com/v1.0';
const CLIENT = '22222222-2222-2222-2222-222222222222';

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

function fakeDeviceCodeAndToken(string $tenant = TEST_TENANT_ID, string $oid = 'oid-admin'): array
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
                'id_token' => fakeIdToken(['tid' => $tenant, 'oid' => $oid, 'name' => 'Guy Admin', 'preferred_username' => 'admin@guycolle.ch']),
            ]),
    ];
}

/**
 * @param  list<string>  $existingKeyIds  password credentials present after the new one was added
 */
function fakeGraph(bool $existingSp = false, array $existingKeyIds = ['key-1'], array $overrides = []): void
{
    // array_merge keeps the position of the base pattern but takes the overriding response.
    Http::fake(array_merge([
        ...fakeDeviceCodeAndToken(),
        GRAPH."/servicePrincipals(appId='".AppRegistrar::GRAPH_APP_ID."')*" => Http::response(['id' => 'graph-sp', 'oauth2PermissionScopes' => graphScopes()]),
        GRAPH."/applications(appId='".CLIENT."')*" => Http::response([
            'id' => 'app-object', 'appId' => CLIENT,
            'passwordCredentials' => array_map(fn ($k) => ['keyId' => $k], $existingKeyIds),
        ]),
        GRAPH.'/applications/app-object/addPassword' => Http::response([
            'keyId' => 'key-1', 'secretText' => 'new-secret', 'endDateTime' => '2028-11-02T00:00:00Z',
        ]),
        GRAPH.'/applications/app-object/removePassword' => Http::response([], 204),
        GRAPH.'/applications/app-object*' => Http::response(['id' => 'app-object', 'appId' => CLIENT]),
        GRAPH.'/applications' => Http::response(['id' => 'app-object', 'appId' => CLIENT]),
        GRAPH.'/servicePrincipals?*' => Http::response(['value' => $existingSp ? [['id' => 'sp-1', 'appId' => CLIENT]] : []]),
        GRAPH.'/servicePrincipals/*' => Http::response([], 204),
        GRAPH.'/servicePrincipals' => Http::sequence()
            ->push(['error' => ['code' => 'Request_BadRequest']], 400) // replication delay
            ->push(['id' => 'sp-1', 'appId' => CLIENT]),
        GRAPH.'/oauth2PermissionGrants?*' => Http::response(['value' => []]),
        GRAPH.'/oauth2PermissionGrants' => Http::response(['id' => 'grant-1']),
    ], $overrides));
}

function runInstall(array $options = []): PendingCommand
{
    return test()->artisan('intake:install', $options)
        ->expectsConfirmation('Im Tenant '.TEST_TENANT_ID.' die App-Registration «'.AppRegistrar::displayName().'» einrichten?', 'yes');
}

it('registers the app, grants consent and makes the signed-in admin the first admin', function () {
    fakeGraph();

    runInstall()
        ->expectsConfirmation('Guy Admin (admin@guycolle.ch) wird App-Admin und sieht die Mails aller Benutzer in der Queue. Einverstanden?', 'yes')
        ->expectsOutputToContain('ABCD')
        ->expectsOutputToContain('Fertig')
        ->assertSuccessful();

    $app = app(MicrosoftApp::class);
    expect($app->isConfigured())->toBeTrue()
        ->and($app->tenantId())->toBe(TEST_TENANT_ID)
        ->and($app->clientId())->toBe(CLIENT)
        ->and($app->appObjectId())->toBe('app-object')
        ->and($app->clientSecret())->toBe('new-secret')
        ->and($app->secretExpiresAt()->toDateString())->toBe('2028-11-02');

    $admin = User::firstWhere('entra_object_id', 'oid-admin');
    expect($admin->role)->toBe(Role::Admin)->and($admin->active)->toBeTrue();

    Http::assertSent(fn (Request $r) => $r->url() === GRAPH.'/applications' && $r->method() === 'POST'
        && $r['displayName'] === 'GUYCOLLE ClickUp-Intake (testing)'
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

it('never adopts a registration found by display name', function () {
    fakeGraph();

    runInstall()
        ->expectsConfirmation('Guy Admin (admin@guycolle.ch) wird App-Admin und sieht die Mails aller Benutzer in der Queue. Einverstanden?', 'yes')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), GRAPH.'/applications?'));
});

it('resumes its own registration by stored object id after an aborted run', function () {
    Setting::put(MicrosoftApp::SETTING_APP_OBJECT_ID, 'app-object');
    Setting::put(MicrosoftApp::SETTING_TENANT_ID, TEST_TENANT_ID);
    fakeGraph(existingSp: true, existingKeyIds: ['key-orphan', 'key-1']);

    runInstall()
        ->expectsConfirmation('Guy Admin (admin@guycolle.ch) wird App-Admin und sieht die Mails aller Benutzer in der Queue. Einverstanden?', 'yes')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && in_array($r->url(), [GRAPH.'/applications', GRAPH.'/servicePrincipals'], true));

    // Redirect URIs are replaced, not merged, and `web` is sent complete.
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === GRAPH.'/applications/app-object'
        && $r['web'] === [
            'redirectUris' => ['https://intake.guycolle.services/auth/microsoft/callback'],
            'homePageUrl' => 'https://intake.guycolle.services/',
        ]
        && $r['signInAudience'] === 'AzureADMyOrg');

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === GRAPH.'/servicePrincipals/sp-1'
        && $r['appRoleAssignmentRequired'] === true);

    // The orphaned secret of the aborted run is removed, the new one kept.
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/removePassword') && $r['keyId'] === 'key-orphan');
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/removePassword') && $r['keyId'] === 'key-1');
});

it('remembers the new registration before anything else can fail', function () {
    fakeGraph(overrides: [
        GRAPH.'/servicePrincipals?*' => Http::response(['error' => ['code' => 'Authorization_RequestDenied']], 403),
    ]);

    runInstall()->assertFailed();

    expect(app(MicrosoftApp::class)->appObjectId())->toBe('app-object')
        ->and(app(MicrosoftApp::class)->isConfigured())->toBeFalse();
});

it('does not retry a POST that failed with 504', function () {
    fakeGraph(overrides: [
        GRAPH.'/applications' => Http::response([], 504),
    ]);

    runInstall()->assertFailed();

    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST' && $pair[0]->url() === GRAPH.'/applications'))->toHaveCount(1);
});

it('does nothing when already configured', function () {
    configureMicrosoftApp();
    Http::fake();

    $this->artisan('intake:install')->expectsOutputToContain('bereits')->assertSuccessful();

    Http::assertNothingSent();
});

it('refuses --force with an admin of another tenant', function () {
    configureMicrosoftApp();
    Http::fake(fakeDeviceCodeAndToken(tenant: '99999999-9999-9999-9999-999999999999'));

    $this->artisan('intake:install', ['--force' => true])->assertFailed();

    expect(app(MicrosoftApp::class)->tenantId())->toBe(TEST_TENANT_ID);
    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), GRAPH));
});

it('changes nothing when the admin does not confirm the tenant', function () {
    Http::fake(fakeDeviceCodeAndToken());

    $this->artisan('intake:install')
        ->expectsConfirmation('Im Tenant '.TEST_TENANT_ID.' die App-Registration «'.AppRegistrar::displayName().'» einrichten?', 'no')
        ->assertFailed();

    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), GRAPH));
});

it('does not make the admin an app admin without confirmation', function () {
    fakeGraph();

    runInstall()
        ->expectsConfirmation('Guy Admin (admin@guycolle.ch) wird App-Admin und sieht die Mails aller Benutzer in der Queue. Einverstanden?', 'no')
        ->assertSuccessful();

    expect(User::count())->toBe(0);
});

it('does not reactivate a deactivated admin account', function () {
    User::factory()->inactive()->create(['entra_object_id' => 'oid-admin']);
    fakeGraph();

    runInstall()->expectsOutputToContain('deaktiviert')->assertSuccessful();

    expect(User::first()->active)->toBeFalse()->and(User::first()->role)->toBe(Role::Member);
});

it('refuses a non-https redirect URI outside localhost', function () {
    config(['app.url' => 'http://intake.guycolle.services']);
    Http::fake();

    $this->artisan('intake:install')->assertFailed();

    Http::assertNothingSent();
});

it('explains missing admin rights without leaking the response', function () {
    Http::fake([
        ...fakeDeviceCodeAndToken(),
        GRAPH.'/*' => Http::response(['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'secret detail']], 403),
    ]);

    runInstall()
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

it('renews the secret, stores it first and then removes the old ones', function () {
    configureMicrosoftApp();
    Setting::put(MicrosoftApp::SETTING_CLIENT_ID, CLIENT);

    Http::fake([
        ...fakeDeviceCodeAndToken(),
        GRAPH."/applications(appId='".CLIENT."')*" => Http::sequence()
            ->push(['id' => 'app-object', 'appId' => CLIENT])
            ->push(['id' => 'app-object', 'passwordCredentials' => [['keyId' => 'key-old'], ['keyId' => 'key-new']]]),
        GRAPH.'/applications/app-object/addPassword' => Http::response(['keyId' => 'key-new', 'secretText' => 'renewed', 'endDateTime' => '2028-11-02T00:00:00Z']),
        GRAPH.'/applications/app-object/removePassword' => function (Request $r) {
            // The new secret must already be stored when the old one is removed.
            expect(app(MicrosoftApp::class)->clientSecret())->toBe('renewed');

            return Http::response([], 204);
        },
        GRAPH.'/applications/app-object' => Http::response([], 204),
    ]);

    $this->artisan('intake:install', ['--renew-secret' => true])->assertSuccessful();

    $app = app(MicrosoftApp::class);
    expect($app->clientSecret())->toBe('renewed')->and($app->secretKeyId())->toBe('key-new');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/removePassword') && $r['keyId'] === 'key-old');
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/removePassword') && $r['keyId'] === 'key-new');
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
        ->expectsQuestion('Anwendungs-ID (Client-ID)', CLIENT)
        ->expectsQuestion('Wert des Client-Secrets', 'manual-secret')
        ->expectsQuestion('Secret gültig bis (JJJJ-MM-TT)', now()->addYear()->format('Y-m-d'))
        ->assertSuccessful();

    expect(app(MicrosoftApp::class)->isConfigured())->toBeTrue()
        ->and(app(MicrosoftApp::class)->tenantId())->toBe(TEST_TENANT_ID);
});

it('rejects an invalid expiry date in manual mode without storing anything', function (string $date) {
    $this->artisan('intake:install', ['--manual' => true])
        ->expectsQuestion('Verzeichnis-ID (Tenant-ID)', TEST_TENANT_ID)
        ->expectsQuestion('Anwendungs-ID (Client-ID)', CLIENT)
        ->expectsQuestion('Wert des Client-Secrets', 'manual-secret')
        ->expectsQuestion('Secret gültig bis (JJJJ-MM-TT)', $date)
        ->assertFailed();

    expect(Setting::count())->toBe(0);
})->with(['', 'foo', '31.01.2028', '2020-01-01']);

it('does not overwrite an existing setup in manual mode without --force', function () {
    configureMicrosoftApp();

    $this->artisan('intake:install', ['--manual' => true])->assertFailed();

    expect(app(MicrosoftApp::class)->clientSecret())->toBe('client-secret');
});
