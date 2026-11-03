<?php

use App\Microsoft\MicrosoftApp;
use App\Models\Setting;

it('stores values encrypted', function () {
    Setting::put('microsoft.client_secret', 'top-secret');

    expect(Setting::get('microsoft.client_secret'))->toBe('top-secret')
        ->and(DB::table('settings')->value('value'))->not->toContain('top-secret');
});

it('is only configured with all three values and a tenant GUID', function () {
    $app = app(MicrosoftApp::class);
    expect($app->isConfigured())->toBeFalse();

    configureMicrosoftApp();
    expect($app->isConfigured())->toBeTrue();

    Setting::put(MicrosoftApp::SETTING_TENANT_ID, 'guycolle.ch');
    expect($app->isConfigured())->toBeFalse();
});

it('treats undecryptable settings as missing instead of failing', function () {
    configureMicrosoftApp();
    DB::table('settings')->where('key', MicrosoftApp::SETTING_CLIENT_SECRET)->update(['value' => 'garbage']);

    expect(app(MicrosoftApp::class)->isConfigured())->toBeFalse();
    $this->get(route('login'))->assertOk()->assertSee('php artisan intake:install');
});
