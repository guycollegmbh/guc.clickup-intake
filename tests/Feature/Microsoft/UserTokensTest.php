<?php

use App\Enums\ConnectionStatus;
use App\Microsoft\MicrosoftConnectionBroken;
use App\Microsoft\MicrosoftTokenUnavailable;
use App\Microsoft\UserTokens;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const TOKEN_URL = 'https://login.microsoftonline.com/'.TEST_TENANT_ID.'/oauth2/v2.0/token';

beforeEach(fn () => configureMicrosoftApp());

function expiringUser(): User
{
    return User::factory()->connected()->create([
        'ms_access_token' => 'access-token-old',
        'ms_token_expires_at' => now()->addMinutes(2),
    ]);
}

it('reuses a valid access token without calling Microsoft', function () {
    Http::fake();
    $user = User::factory()->connected()->create();

    expect(app(UserTokens::class)->accessToken($user))->toBe('access-token-valid');

    Http::assertNothingSent();
});

it('refreshes an expiring token and stores the rotated refresh token right away', function () {
    Http::fake([TOKEN_URL => Http::response([
        'access_token' => 'access-token-new',
        'refresh_token' => 'refresh-token-new',
        'expires_in' => 3600,
    ])]);
    $user = expiringUser();

    expect(app(UserTokens::class)->accessToken($user))->toBe('access-token-new');

    Http::assertSent(fn (Request $request): bool => $request->url() === TOKEN_URL
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-token-old'
        && $request['client_id'] === 'client-id'
        && $request['client_secret'] === 'client-secret'
        && str_contains($request['scope'], 'offline_access')
        && str_contains($request['scope'], 'Mail.ReadWrite'));

    $user = $user->fresh();
    expect($user->ms_refresh_token)->toBe('refresh-token-new')
        ->and($user->ms_access_token)->toBe('access-token-new')
        ->and($user->ms_token_expires_at->isAfter(now()->addMinutes(59)))->toBeTrue()
        ->and($user->ms_connection_status)->toBe(ConnectionStatus::Connected);

    $raw = DB::table('users')->where('id', $user->id)->first();
    expect($raw->ms_refresh_token)->not->toContain('refresh-token-new')
        ->and($raw->ms_access_token)->not->toContain('access-token-new');
});

it('keeps the refresh token when Microsoft does not rotate it', function () {
    Http::fake([TOKEN_URL => Http::response(['access_token' => 'access-token-new', 'expires_in' => 3600])]);
    $user = expiringUser();

    app(UserTokens::class)->accessToken($user);

    expect($user->fresh()->ms_refresh_token)->toBe('refresh-token-old');
});

it('releases the per-user lock after a refresh', function () {
    Http::fake([TOKEN_URL => Http::response(['access_token' => 'access-token-new', 'expires_in' => 3600])]);
    $user = expiringUser();

    app(UserTokens::class)->accessToken($user);

    expect(Cache::lock('microsoft-token:'.$user->id, 1)->get())->toBeTrue();
});

it('marks the connection broken on invalid_grant and logs no token', function () {
    $logs = captureLogs();
    Http::fake([TOKEN_URL => Http::response([
        'error' => 'invalid_grant',
        'error_description' => 'AADSTS50173: token refresh-token-old was revoked',
    ], 400)]);
    $user = expiringUser();

    expect(fn () => app(UserTokens::class)->accessToken($user))
        ->toThrow(fn (MicrosoftConnectionBroken $e) => expect($e->getMessage())->not->toContain('refresh-token-old'));

    $user = $user->fresh();
    expect($user->ms_connection_status)->toBe(ConnectionStatus::Broken)
        ->and($user->ms_refresh_token)->toBeNull()
        ->and($user->ms_access_token)->toBeNull();

    expect(logText($logs))->toContain('Microsoft connection broken')
        ->not->toContain('refresh-token-old')
        ->not->toContain('access-token-old')
        ->not->toContain('AADSTS50173');
});

it('marks the connection broken on interaction_required', function () {
    Http::fake([TOKEN_URL => Http::response(['error' => 'interaction_required'], 400)]);
    $user = expiringUser();

    expect(fn () => app(UserTokens::class)->accessToken($user))->toThrow(MicrosoftConnectionBroken::class);
    expect($user->fresh()->ms_connection_status)->toBe(ConnectionStatus::Broken);
});

it('leaves the status alone on a server error', function () {
    $logs = captureLogs();
    Http::fake([TOKEN_URL => Http::response(['error' => 'temporarily_unavailable'], 503)]);
    $user = expiringUser();

    expect(fn () => app(UserTokens::class)->accessToken($user))->toThrow(MicrosoftTokenUnavailable::class);

    $user = $user->fresh();
    expect($user->ms_connection_status)->toBe(ConnectionStatus::Connected)
        ->and($user->ms_refresh_token)->toBe('refresh-token-old');
    expect(logText($logs))->toContain('token refresh failed')->not->toContain('refresh-token-old');
});

it('leaves the status alone when the network fails', function () {
    Http::fake([TOKEN_URL => fn () => throw new ConnectionException('cURL error, refresh-token-old')]);
    $user = expiringUser();

    expect(fn () => app(UserTokens::class)->accessToken($user))
        ->toThrow(fn (MicrosoftTokenUnavailable $e) => expect($e->getMessage())->not->toContain('refresh-token-old'));
    expect($user->fresh()->ms_connection_status)->toBe(ConnectionStatus::Connected);
});

it('does not break every user when the app secret is rejected', function () {
    Http::fake([TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);
    $user = expiringUser();

    expect(fn () => app(UserTokens::class)->accessToken($user))->toThrow(MicrosoftTokenUnavailable::class);
    expect($user->fresh()->ms_connection_status)->toBe(ConnectionStatus::Connected);
});

it('refuses a user without a connection without calling Microsoft', function () {
    Http::fake();
    $user = User::factory()->create();

    expect(fn () => app(UserTokens::class)->accessToken($user))->toThrow(MicrosoftConnectionBroken::class);

    Http::assertNothingSent();
    expect($user->fresh()->ms_connection_status)->toBe(ConnectionStatus::Disconnected);
});

it('marks a connected user without refresh token as broken', function () {
    Http::fake();
    $user = expiringUser();
    $user->forceFill(['ms_refresh_token' => null])->save();

    expect(fn () => app(UserTokens::class)->accessToken($user))->toThrow(MicrosoftConnectionBroken::class);

    Http::assertNothingSent();
    expect($user->fresh()->ms_connection_status)->toBe(ConnectionStatus::Broken);
});

it('builds a Graph client that asks for immutable ids', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response(['value' => []])]);
    $user = User::factory()->connected()->create();

    app(UserTokens::class)->graphFor($user)->get('me/messages');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer access-token-valid')
        && $request->hasHeader('Prefer', 'IdType="ImmutableId"'));
});

it('gives a deactivated user no token, even with a valid one stored', function () {
    Http::fake();
    $user = User::factory()->connected()->inactive()->create();

    expect(fn () => app(UserTokens::class)->accessToken($user))
        ->toThrow(fn (MicrosoftConnectionBroken $e) => expect($e->reason)->toBe('inactive'));

    Http::assertNothingSent();
});

it('takes the token another job renewed while this one waited for the lock', function () {
    Http::fake();
    $stale = expiringUser();

    // Another job renewed the token in the meantime, $stale still holds the old values.
    User::query()->findOrFail($stale->id)->forceFill([
        'ms_access_token' => 'access-token-renewed',
        'ms_token_expires_at' => now()->addHour(),
        'ms_refresh_token' => 'refresh-token-renewed',
    ])->save();

    expect(app(UserTokens::class)->accessToken($stale))->toBe('access-token-renewed');

    Http::assertNothingSent();
    expect($stale->ms_refresh_token)->toBe('refresh-token-renewed');
});

it('forgets the cached access token so the next call renews it', function () {
    $user = User::factory()->connected()->create();

    app(UserTokens::class)->forget($user);

    $user = $user->fresh();
    expect($user->ms_access_token)->toBeNull()
        ->and($user->ms_token_expires_at)->toBeNull()
        ->and($user->ms_refresh_token)->toBe('refresh-token-old')
        ->and($user->ms_connection_status)->toBe(ConnectionStatus::Connected);
});
