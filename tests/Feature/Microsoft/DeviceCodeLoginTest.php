<?php

use App\Microsoft\Setup\DeviceCodeLogin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const DC = 'https://login.microsoftonline.com/organizations/oauth2/v2.0';

beforeEach(fn () => Sleep::fake(syncWithCarbon: true));

function deviceCode(int $expiresIn = 900): array
{
    return [DC.'/devicecode' => Http::response(['device_code' => 'dc', 'interval' => 5, 'expires_in' => $expiresIn, 'message' => 'enter ABCD'])];
}

function validToken(): array
{
    return ['access_token' => 'at', 'id_token' => fakeIdToken(['tid' => TEST_TENANT_ID, 'oid' => 'oid-1', 'preferred_username' => 'a@guycolle.ch'])];
}

it('slows down when asked to', function () {
    Http::fake([...deviceCode(), DC.'/token' => Http::sequence()
        ->push(['error' => 'slow_down'], 400)
        ->push(validToken())]);

    app(DeviceCodeLogin::class)->signIn(fn () => null);

    Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(10)->seconds()]);
});

it('keeps polling through transient server errors', function () {
    Http::fake([...deviceCode(), DC.'/token' => Http::sequence()
        ->push('', 503)
        ->push(['error' => 'temporarily_unavailable'], 400)
        ->push(['error' => 'authorization_pending'], 400)
        ->push(validToken())]);

    $token = app(DeviceCodeLogin::class)->signIn(fn () => null);

    expect($token->objectId)->toBe('oid-1')->and($token->tenantId)->toBe(TEST_TENANT_ID);
});

it('gives up when the code expires', function () {
    Http::fake([...deviceCode(expiresIn: 30), DC.'/token' => Http::response(['error' => 'authorization_pending'], 400)]);

    expect(fn () => app(DeviceCodeLogin::class)->signIn(fn () => null))->toThrow(RuntimeException::class, 'abgelaufen');
});

it('rejects a token response without identity', function () {
    Http::fake([...deviceCode(), DC.'/token' => Http::response(['access_token' => 'at'])]);

    expect(fn () => app(DeviceCodeLogin::class)->signIn(fn () => null))->toThrow(RuntimeException::class, 'unvollständig');
});

it('requests only the setup scopes, without offline access', function () {
    Http::fake([...deviceCode(), DC.'/token' => Http::response(validToken())]);

    app(DeviceCodeLogin::class)->signIn(fn () => null);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/devicecode')
        && ! str_contains($r['scope'], 'offline_access')
        && str_contains($r['scope'], 'Application.ReadWrite.All'));
});
