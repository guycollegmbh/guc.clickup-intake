<?php

use App\Microsoft\MicrosoftApp;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

const TEST_TENANT_ID = '11111111-1111-1111-1111-111111111111';

function configureMicrosoftApp(): void
{
    Setting::put(MicrosoftApp::SETTING_TENANT_ID, TEST_TENANT_ID);
    Setting::put(MicrosoftApp::SETTING_CLIENT_ID, 'client-id');
    Setting::put(MicrosoftApp::SETTING_CLIENT_SECRET, 'client-secret');
}

/**
 * @param  array<string, mixed>  $claims
 */
function fakeIdToken(array $claims): string
{
    $encode = fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

    return $encode(['alg' => 'none']).'.'.$encode($claims).'.signature';
}
