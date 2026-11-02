<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use SocialiteProviders\Azure\User as SocialiteUser;

function fakeSocialiteUser(array $claims): SocialiteUser
{
    $user = (new SocialiteUser)->setRaw([
        'id' => $claims['oid'] ?? 'x',
        'displayName' => 'Anna Muster',
        'userPrincipalName' => 'anna@guycolle.ch',
        'mail' => 'anna@guycolle.ch',
    ])->map([
        'id' => $claims['oid'] ?? 'x',
        'name' => 'Anna Muster',
        'email' => 'anna@guycolle.ch',
    ]);

    $user->setRefreshToken('refresh-1');
    $user->setAccessTokenResponseBody(['id_token' => fakeIdToken($claims)]);

    return $user;
}

function mockSocialiteCallback(SocialiteUser $user): void
{
    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with('azure')->andReturn($driver);
}

it('sends guests from the panel to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('explains the missing setup when the app is not connected to Microsoft 365', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('php artisan intake:install')
        ->assertDontSee('Mit Microsoft anmelden');

    $this->get(route('auth.microsoft.redirect'))->assertStatus(503);
});

it('shows the Microsoft button once configured', function () {
    configureMicrosoftApp();

    $this->get(route('login'))->assertOk()->assertSee('Mit Microsoft anmelden');
});

it('redirects to the tenant authorize endpoint with the delegated scopes', function () {
    configureMicrosoftApp();

    $location = $this->get(route('auth.microsoft.redirect'))->assertRedirect()->headers->get('Location');

    expect($location)
        ->toStartWith('https://login.microsoftonline.com/'.TEST_TENANT_ID.'/')
        ->toContain('offline_access')
        ->toContain('Mail.ReadWrite')
        ->toContain('Mail.Send');
});

it('signs the user in after a valid callback', function () {
    configureMicrosoftApp();
    mockSocialiteCallback(fakeSocialiteUser(['tid' => TEST_TENANT_ID, 'oid' => 'oid-anna']));

    $this->get(route('auth.microsoft.callback', ['code' => 'c', 'state' => 's']))->assertRedirect('/');

    $this->assertAuthenticatedAs(User::firstWhere('entra_object_id', 'oid-anna'));
    $this->get('/')->assertOk();
});

it('takes the redirect URI from APP_URL, not from the Host header', function () {
    configureMicrosoftApp();
    config(['app.url' => 'https://intake.guycolle.services']);

    $location = $this->withHeaders(['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example'])
        ->get(route('auth.microsoft.redirect'))
        ->headers->get('Location');

    expect(urldecode($location))->toContain('redirect_uri=https://intake.guycolle.services/auth/microsoft/callback')
        ->not->toContain('evil.example');
});

it('trusts the local reverse proxy for the scheme', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get(route('login'));

    expect(request()->isSecure())->toBeTrue();
});

it('does not set a remember-me cookie', function () {
    configureMicrosoftApp();
    mockSocialiteCallback(fakeSocialiteUser(['tid' => TEST_TENANT_ID, 'oid' => 'oid-anna']));

    $this->get(route('auth.microsoft.callback', ['code' => 'c', 'state' => 's']))
        ->assertCookieMissing(Auth::guard()->getRecallerName());
});

it('handles an expired login state', function () {
    configureMicrosoftApp();
    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('azure')->andReturn($driver);

    $this->get(route('auth.microsoft.callback', ['code' => 'c', 'state' => 's']))
        ->assertRedirect(route('login'))
        ->assertSessionHas('login_error');

    $this->assertGuest();
});

it('does not log details of a failed token exchange', function () {
    configureMicrosoftApp();
    Log::spy();
    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andThrow(new RuntimeException('response contained secret-token-123'));
    Socialite::shouldReceive('driver')->with('azure')->andReturn($driver);

    $this->get(route('auth.microsoft.callback', ['code' => 'c', 'state' => 's']))
        ->assertRedirect(route('login'));

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => ! str_contains(json_encode($context).$message, 'secret-token-123'),
    );
    $this->assertGuest();
});

it('refuses a callback from another tenant', function () {
    configureMicrosoftApp();
    mockSocialiteCallback(fakeSocialiteUser(['tid' => 'evil-tenant', 'oid' => 'oid-mallory']));

    $this->get(route('auth.microsoft.callback', ['code' => 'c', 'state' => 's']))
        ->assertRedirect(route('login'))
        ->assertSessionHas('login_error');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('shows a hint when Microsoft denies the sign-in (user not assigned)', function () {
    configureMicrosoftApp();

    $this->get(route('auth.microsoft.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHas('login_error');
});

it('denies the panel to a deactivated user', function () {
    $this->actingAs(User::factory()->inactive()->create())->get('/')->assertForbidden();
});
