<?php

use App\ClickUp\ClickUpApp;
use App\Microsoft\MicrosoftApp;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
 * ClickUp OAuth app and the workspace of tests/Support/FakeClickUp.
 */
function configureClickUpApp(bool $withWorkspace = true): void
{
    Setting::put(ClickUpApp::SETTING_CLIENT_ID, 'clickup-client-id');
    Setting::put(ClickUpApp::SETTING_CLIENT_SECRET, 'clickup-client-secret');

    if ($withWorkspace) {
        Setting::put(ClickUpApp::SETTING_TEAM_ID, '9001');
        Setting::put(ClickUpApp::SETTING_TEAM_NAME, 'GUYCOLLE Test');
    }
}

/**
 * @param  array<string, mixed>  $claims
 */
function fakeIdToken(array $claims): string
{
    $encode = fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

    return $encode(['alg' => 'none']).'.'.$encode($claims).'.signature';
}

/**
 * Collects every log entry of the test, to check what is (not) logged.
 *
 * @return ArrayObject<int, MessageLogged>
 */
function captureLogs(): ArrayObject
{
    $logs = new ArrayObject;
    Log::listen(fn (MessageLogged $event) => $logs->append($event));

    return $logs;
}

/**
 * @param  ArrayObject<int, MessageLogged>  $logs
 */
function logText(ArrayObject $logs): string
{
    return collect($logs->getArrayCopy())
        ->map(fn (MessageLogged $event): string => $event->level.' '.$event->message.' '.json_encode($event->context))
        ->implode("\n");
}
