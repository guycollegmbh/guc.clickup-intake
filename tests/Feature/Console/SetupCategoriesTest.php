<?php

use App\Enums\ConnectionStatus;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGraph;

function fakeMasterCategories(): void
{
    FakeGraph::fake([
        'GET me/outlook/masterCategories' => Http::response(['value' => [['displayName' => 'ClickUp']]]),
        'POST me/outlook/masterCategories' => Http::response([], 201),
    ]);
}

it('sets up the categories for all connected users', function () {
    fakeMasterCategories();
    User::factory()->connected()->count(2)->create();
    User::factory()->create();

    $this->artisan('intake:setup-categories')->assertSuccessful();

    Http::assertSentCount(2 * 3);
});

it('sets up the categories for one mailbox', function () {
    fakeMasterCategories();
    $anna = User::factory()->connected()->create(['mailbox_upn' => 'anna@guycolle.test', 'ms_access_token' => 'token-anna']);
    User::factory()->connected()->create();

    $this->artisan('intake:setup-categories', ['upn' => 'Anna@GUYCOLLE.test'])
        ->expectsOutputToContain('anna@guycolle.test: created ClickUp ✓, Support ✓')
        ->assertSuccessful();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer token-anna'));
});

it('fails for an unknown mailbox', function () {
    Http::fake();

    $this->artisan('intake:setup-categories', ['upn' => 'nobody@guycolle.test'])->assertFailed();
});

it('reports a user whose connection is broken', function () {
    Http::fake();
    $user = User::factory()->create(['mailbox_upn' => 'max@guycolle.test']);
    $user->forceFill(['ms_connection_status' => ConnectionStatus::Broken])->save();

    $this->artisan('intake:setup-categories', ['upn' => 'max@guycolle.test'])
        ->expectsOutputToContain('must sign in')
        ->assertFailed();

    Http::assertNothingSent();
});
