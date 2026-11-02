<?php

use App\Microsoft\MicrosoftApp;
use App\Models\Setting;

it('stores values encrypted', function () {
    Setting::put('microsoft.client_secret', 'top-secret');

    expect(Setting::get('microsoft.client_secret'))->toBe('top-secret')
        ->and(DB::table('settings')->value('value'))->not->toContain('top-secret');
});

it('prefers stored settings over the env fallback', function () {
    config(['intake.microsoft.client_id' => 'from-env']);

    expect(app(MicrosoftApp::class)->clientId())->toBe('from-env');

    Setting::put(MicrosoftApp::SETTING_CLIENT_ID, 'from-setup');

    expect(app(MicrosoftApp::class)->clientId())->toBe('from-setup');
});
