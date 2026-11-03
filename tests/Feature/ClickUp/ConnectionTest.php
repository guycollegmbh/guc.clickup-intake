<?php

use App\ClickUp\ClickUpApp;
use App\ClickUp\ClickUpException;
use App\ClickUp\ClickUpNotConnected;
use App\ClickUp\UserClickUp;
use App\Enums\ConnectionStatus;
use App\Models\User;
use App\Users\UserAdministration;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeClickUp;

beforeEach(fn () => configureClickUpApp());

/**
 * @param  array<string, mixed>  $teams
 */
function fakeClickUpOAuth(array $teams, string $token = 'oauth-token-new'): void
{
    FakeClickUp::fake([
        'POST oauth/token?*' => Http::response(['access_token' => $token]),
        'GET user' => Http::response(['user' => ['id' => FakeClickUp::OTHER_MEMBER_ID, 'username' => 'Max']]),
        'GET team' => Http::response($teams),
    ]);
}

function startClickUpConnect(User $user, string $from = '/eingang'): string
{
    test()->actingAs($user)
        ->from(rtrim(config('app.url'), '/').$from)
        ->get('/clickup/connect')
        ->assertRedirect();

    return (string) session('clickup_oauth_state');
}

it('redirects to ClickUp with client id, redirect uri from APP_URL and a state', function () {
    config(['app.url' => 'https://intake.example.test']);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/clickup/connect');

    $state = session('clickup_oauth_state');
    expect($state)->toBeString()->toHaveLength(40);
    $response->assertRedirect('https://app.clickup.com/api?'.http_build_query([
        'client_id' => 'clickup-client-id',
        'redirect_uri' => 'https://intake.example.test/clickup/callback',
        'state' => $state,
    ]));
});

it('requires a sign-in for connecting', function () {
    $this->get('/clickup/connect')->assertRedirect('/login');
    $this->get('/clickup/callback?state=x&code=y')->assertRedirect('/login');
});

it('connects, stores the token encrypted and sets the member id', function () {
    $user = User::factory()->create();
    $state = startClickUpConnect($user);
    fakeClickUpOAuth(FakeClickUp::teams());

    $this->get("/clickup/callback?state={$state}&code=the-code")->assertRedirect('/eingang');

    $user->refresh();
    expect($user->clickup_token)->toBe('oauth-token-new')
        ->and($user->clickup_connection_status)->toBe(ConnectionStatus::Connected)
        ->and($user->clickup_member_id)->toBe((string) FakeClickUp::OTHER_MEMBER_ID)
        ->and(DB::table('users')->where('id', $user->id)->value('clickup_token'))->not->toContain('oauth-token-new');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth/token') && str_contains($r->url(), 'code=the-code'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/user') && $r->hasHeader('Authorization', 'Bearer oauth-token-new'));
});

it('rejects a wrong state without calling ClickUp', function () {
    $user = User::factory()->create();
    startClickUpConnect($user);
    Http::fake();

    $this->get('/clickup/callback?state=forged&code=the-code')->assertRedirect('/eingang');

    Http::assertNothingSent();
    expect($user->fresh()->clickup_token)->toBeNull();
    Notification::assertNotified('Die Verbindung mit ClickUp ist abgelaufen. Bitte erneut versuchen.');
});

it('rejects a callback without state in the session', function () {
    $user = User::factory()->create();
    Http::fake();

    $this->actingAs($user)->get('/clickup/callback?state=abc&code=the-code')->assertRedirect('/');

    Http::assertNothingSent();
});

it('uses a state only once', function () {
    $user = User::factory()->create();
    $state = startClickUpConnect($user);
    fakeClickUpOAuth(FakeClickUp::teams());

    $this->get("/clickup/callback?state={$state}&code=the-code");
    $user->forceFill(['clickup_token' => null, 'clickup_connection_status' => ConnectionStatus::Disconnected])->save();
    $this->get("/clickup/callback?state={$state}&code=the-code");

    expect($user->fresh()->clickup_token)->toBeNull();
});

it('refuses an account without access to the app workspace and stores nothing', function () {
    $user = User::factory()->create();
    $state = startClickUpConnect($user);
    fakeClickUpOAuth(FakeClickUp::teams(withAppTeam: false));
    $logs = captureLogs();

    $this->get("/clickup/callback?state={$state}&code=the-code")->assertRedirect('/eingang');

    $user->refresh();
    expect($user->clickup_token)->toBeNull()
        ->and($user->clickup_connection_status)->toBe(ConnectionStatus::Disconnected)
        ->and($user->clickup_member_id)->toBeNull()
        ->and(logText($logs))->not->toContain('oauth-token-new');
    Notification::assertNotified('Dein ClickUp-Konto hat keinen Zugriff auf den Workspace «GUYCOLLE Test». Bitte mit dem richtigen Konto verbinden.');
});

it('reports a failed code exchange without token or response text in the log', function () {
    $user = User::factory()->create();
    $state = startClickUpConnect($user);
    FakeClickUp::fake(['POST oauth/token?*' => Http::response(['err' => 'secret detail of ceo@guycolle.ch', 'ECODE' => 'OAUTH_015'], 400)]);
    $logs = captureLogs();

    $this->get("/clickup/callback?state={$state}&code=the-code");

    expect($user->fresh()->clickup_token)->toBeNull()
        ->and(logText($logs))->toContain('OAUTH_015')
        ->and(logText($logs))->not->toContain('ceo@')
        ->and(logText($logs))->not->toContain('the-code')
        ->and(logText($logs))->not->toContain('clickup-client-secret');
});

it('lets only admins connect before the workspace is chosen, without workspace check', function () {
    DB::table('settings')->whereIn('key', [ClickUpApp::SETTING_TEAM_ID, ClickUpApp::SETTING_TEAM_NAME])->delete();
    $member = User::factory()->create();

    $this->actingAs($member)->get('/clickup/connect')->assertRedirect('/');
    Notification::assertNotified('ClickUp ist noch nicht eingerichtet. Bitte einen Admin fragen.');

    $admin = User::factory()->admin()->create();
    $state = startClickUpConnect($admin, '/clickup');
    fakeClickUpOAuth(FakeClickUp::teams(withAppTeam: false));

    $this->get("/clickup/callback?state={$state}&code=the-code")->assertRedirect('/clickup');

    expect($admin->fresh()->clickup_connection_status)->toBe(ConnectionStatus::Connected);
});

it('does not connect without OAuth app', function () {
    DB::table('settings')->delete();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/clickup/connect')->assertRedirect('/');
});

it('disconnects the own account only with a POST', function () {
    $user = User::factory()->clickUpConnected()->create();

    $this->actingAs($user)->get('/clickup/disconnect')->assertMethodNotAllowed();
    $this->actingAs($user)->post('/clickup/disconnect')->assertRedirect('/');
    Notification::assertNotified(Notification::make()
        ->title('ClickUp ist getrennt.')
        ->body('In ClickUp unter Settings, Apps die Freigabe ebenfalls entfernen.')
        ->success()
        ->persistent());

    $user->refresh();
    expect($user->clickup_token)->toBeNull()
        ->and($user->clickup_connection_status)->toBe(ConnectionStatus::Disconnected);
});

it('marks the connection broken on 401 and drops only the rejected token', function () {
    $user = User::factory()->clickUpConnected('old-token')->create();
    $client = app(UserClickUp::class)->client($user);
    FakeClickUp::fake(['GET user' => Http::response([], 401)]);
    $logs = captureLogs();

    expect(fn () => $client->user())->toThrow(ClickUpException::class);

    $user->refresh();
    expect($user->clickup_connection_status)->toBe(ConnectionStatus::Broken)
        ->and($user->clickup_token)->toBeNull()
        ->and(logText($logs))->not->toContain('old-token');
});

it('keeps a new token when an old one is rejected afterwards', function () {
    $user = User::factory()->clickUpConnected('old-token')->create();
    $client = app(UserClickUp::class)->client($user);
    User::query()->whereKey($user->id)->first()->forceFill(['clickup_token' => 'new-token'])->save();
    FakeClickUp::fake(['GET user' => Http::response([], 401)]);

    expect(fn () => $client->user())->toThrow(ClickUpException::class);

    expect($user->fresh()->clickup_token)->toBe('new-token')
        ->and($user->fresh()->clickup_connection_status)->toBe(ConnectionStatus::Connected);
});

it('gives no client to inactive or unconnected users', function (Closure $make, string $reason) {
    expect(fn () => app(UserClickUp::class)->client($make()))
        ->toThrow(fn (ClickUpNotConnected $e) => expect($e->reason)->toBe($reason));
})->with([
    'inactive' => [fn () => User::factory()->clickUpConnected()->inactive()->create(), 'inactive'],
    'not connected' => [fn () => User::factory()->create(), 'not_connected'],
    'broken' => [fn () => User::factory()->create(['clickup_connection_status' => ConnectionStatus::Broken]), 'not_connected'],
]);

it('caches hierarchy and members per user for an hour and reloads on request', function () {
    $user = User::factory()->clickUpConnected()->create();
    FakeClickUp::fake(FakeClickUp::workspaceRoutes());
    $clickUp = app(UserClickUp::class);

    expect($clickUp->lists($user))->toBe(['901' => 'Kunden / Projekte / Website', '902' => 'Kunden / Allgemein'])
        ->and($clickUp->members($user))->toBe([501 => 'Ada Admin', 502 => 'max@guycolle.test']);
    $clickUp->lists($user);
    $clickUp->members($user);
    Http::assertSentCount(5);

    $other = User::factory()->clickUpConnected('other-token')->create();
    $clickUp->lists($other);
    Http::assertSentCount(9);

    $clickUp->forget($user);
    $clickUp->lists($user);
    Http::assertSentCount(13);

    $this->travel(61)->minutes();
    $clickUp->members($user);
    Http::assertSentCount(14);
});

it('does not reconnect a user deactivated during the connect', function () {
    $user = User::factory()->create();
    $state = startClickUpConnect($user);
    FakeClickUp::fake([
        'POST oauth/token?*' => Http::response(['access_token' => 'oauth-token-new']),
        'GET user' => Http::response(['user' => ['id' => 502]]),
        'GET team' => function () use ($user) {
            User::query()->whereKey($user->id)->update(['active' => false]);

            return Http::response(FakeClickUp::teams());
        },
    ]);

    $this->get("/clickup/callback?state={$state}&code=the-code");

    expect($user->fresh()->clickup_token)->toBeNull();
});

it('removes the ClickUp token on deactivation', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->clickUpConnected()->create();

    app(UserAdministration::class)->deactivate($user, $admin);

    expect($user->fresh()->clickup_token)->toBeNull()
        ->and($user->fresh()->clickup_connection_status)->toBe(ConnectionStatus::Disconnected);
});

it('shows a banner with connect button while ClickUp is set up and the user is not connected', function () {
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('app'));
    $user = User::factory()->create()->fresh();

    $this->actingAs($user)->get('/eingang')
        ->assertOk()
        ->assertSee('Verbinde dein ClickUp-Konto, um Tasks zu erstellen.')
        ->assertSee(route('clickup.connect'), escape: false);

    $user->forceFill(['clickup_connection_status' => ConnectionStatus::Broken])->save();
    $this->actingAs($user->fresh())->get('/eingang')
        ->assertSee('Die Verbindung zu ClickUp ist unterbrochen.');

    $connected = User::factory()->clickUpConnected()->create()->fresh();
    $this->actingAs($connected)->get('/eingang')
        ->assertDontSee('Verbinde dein ClickUp-Konto')
        ->assertSee('ClickUp trennen')
        ->assertSee(route('clickup.disconnect'), escape: false);
});

it('shows no banner while ClickUp is not set up', function () {
    DB::table('settings')->delete();

    $this->actingAs(User::factory()->create()->fresh())->get('/eingang')
        ->assertOk()
        ->assertDontSee('Verbinde dein ClickUp-Konto');
});
