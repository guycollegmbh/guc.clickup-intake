<?php

use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Microsoft\LoginRejected;
use App\Microsoft\MicrosoftApp;
use App\Microsoft\MicrosoftLogin;
use App\Models\Setting;
use App\Models\User;

beforeEach(fn () => configureMicrosoftApp());

function signIn(array $claims, string $upn = 'anna@guycolle.ch', ?string $refreshToken = 'refresh-1'): User
{
    return app(MicrosoftLogin::class)->handle($claims, 'Anna Muster', $upn, $upn, $refreshToken);
}

it('creates a member on first sign-in and stores the refresh token encrypted', function () {
    $user = signIn(['tid' => TEST_TENANT_ID, 'oid' => 'oid-anna']);

    expect($user->role)->toBe(Role::Member)
        ->and($user->active)->toBeTrue()
        ->and($user->mailbox_upn)->toBe('anna@guycolle.ch')
        ->and($user->ms_refresh_token)->toBe('refresh-1')
        ->and($user->ms_connection_status)->toBe(ConnectionStatus::Connected);

    $raw = DB::table('users')->where('id', $user->id)->value('ms_refresh_token');
    expect($raw)->not->toContain('refresh-1');
});

it('rejects a token from another tenant', function () {
    signIn(['tid' => 'other-tenant', 'oid' => 'oid-anna']);
})->throws(LoginRejected::class);

it('compares the tenant case-insensitively', function () {
    Setting::put(MicrosoftApp::SETTING_TENANT_ID, strtoupper(TEST_TENANT_ID));

    $user = signIn(['tid' => TEST_TENANT_ID, 'oid' => 'oid-anna']);

    expect($user->exists)->toBeTrue();
});

it('survives a parallel first sign-in of the same user', function () {
    // Simulate the other request inserting the row between our lookup and our insert.
    User::creating(function (User $user) {
        User::flushEventListeners();
        User::factory()->create(['entra_object_id' => $user->entra_object_id]);
    });

    $user = signIn(['tid' => TEST_TENANT_ID, 'oid' => 'oid-anna']);

    expect(User::count())->toBe(1)
        ->and($user->ms_refresh_token)->toBe('refresh-1');
});

it('rejects a token without object id', function () {
    signIn(['tid' => TEST_TENANT_ID]);
})->throws(LoginRejected::class);

it('rejects a deactivated user', function () {
    User::factory()->inactive()->create(['entra_object_id' => 'oid-anna']);

    signIn(['tid' => TEST_TENANT_ID, 'oid' => 'oid-anna']);
})->throws(LoginRejected::class);

it('matches users by object id, not by e-mail, and keeps their role', function () {
    $existing = User::factory()->admin()->create([
        'entra_object_id' => 'oid-anna',
        'email' => 'old@guycolle.ch',
    ]);

    $user = signIn(['tid' => TEST_TENANT_ID, 'oid' => 'oid-anna'], upn: 'anna.new@guycolle.ch');

    expect($user->id)->toBe($existing->id)
        ->and($user->role)->toBe(Role::Admin)
        ->and($user->mailbox_upn)->toBe('anna.new@guycolle.ch')
        ->and(User::count())->toBe(1);
});

it('does not take over an account with the same e-mail but another object id', function () {
    User::factory()->admin()->create(['entra_object_id' => 'oid-real', 'email' => 'anna@guycolle.ch']);

    $user = signIn(['tid' => TEST_TENANT_ID, 'oid' => 'oid-other']);

    expect($user->role)->toBe(Role::Member)
        ->and(User::count())->toBe(2);
});

it('keeps the existing connection when Microsoft returns no refresh token', function () {
    User::factory()->create([
        'entra_object_id' => 'oid-anna',
    ])->forceFill(['ms_refresh_token' => 'old', 'ms_connection_status' => ConnectionStatus::Connected])->save();

    $user = signIn(['tid' => TEST_TENANT_ID, 'oid' => 'oid-anna'], refreshToken: null);

    expect($user->ms_refresh_token)->toBe('old');
});
