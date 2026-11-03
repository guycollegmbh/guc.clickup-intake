<?php

use App\Jobs\SetupOutlookCategories;
use App\Microsoft\GraphException;
use App\Microsoft\OutlookCategories;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeGraph;

beforeEach(fn () => Sleep::fake());

it('creates only the missing master categories and leaves existing ones alone', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake([
        'GET me/outlook/masterCategories' => Http::response(['value' => [
            ['id' => '1', 'displayName' => 'clickup', 'color' => 'preset0'],
            ['id' => '2', 'displayName' => 'Privat', 'color' => 'preset3'],
        ]]),
        'POST me/outlook/masterCategories' => Http::response(['id' => 'new'], 201),
    ]);

    $created = app(OutlookCategories::class)->ensureMasterCategories($user);

    expect($created)->toBe([OutlookCategories::CLICKUP_DONE, OutlookCategories::SUPPORT_DONE]);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r['displayName'] === 'ClickUp ✓' && $r['color'] === 'preset4');
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r['displayName'] === 'Support ✓');
    Http::assertNotSent(fn (Request $r): bool => in_array($r->method(), ['PATCH', 'DELETE'], true));
});

it('accepts a category created in the meantime (409)', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake([
        'GET me/outlook/masterCategories' => Http::response(['value' => []]),
        'POST me/outlook/masterCategories' => Http::response(['error' => ['code' => 'ErrorDuplicateCategory']], 409),
    ]);

    expect(app(OutlookCategories::class)->ensureMasterCategories($user))->toBe([]);
});

it('replaces only the one category and keeps all others', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake([
        'GET me/messages/msg-1?*' => Http::response(['categories' => ['Rot', 'ClickUp', 'Projekt X']]),
        'PATCH me/messages/msg-1' => Http::response([]),
    ]);

    app(OutlookCategories::class)->replace($user, 'msg-1', OutlookCategories::CLICKUP, OutlookCategories::CLICKUP_DONE);

    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
        && $r['categories'] === ['Rot', 'Projekt X', 'ClickUp ✓']
        && $r->hasHeader('Authorization', 'Bearer access-token-valid'));
});

it('only removes the category when there is no replacement', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake([
        'GET me/messages/msg-1?*' => Http::response(['categories' => ['ClickUp', 'Rot']]),
        'PATCH me/messages/msg-1' => Http::response([]),
    ]);

    app(OutlookCategories::class)->replace($user, 'msg-1', OutlookCategories::CLICKUP, null);

    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && $r['categories'] === ['Rot']);
});

it('does not patch when the categories are already right', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(['GET me/messages/msg-1?*' => Http::response(['categories' => ['ClickUp ✓']])]);

    app(OutlookCategories::class)->replace($user, 'msg-1', OutlookCategories::CLICKUP, OutlookCategories::CLICKUP_DONE);

    Http::assertSentCount(1);
});

it('treats a mail deleted in Outlook as done and logs no mail content', function () {
    $logs = captureLogs();
    $user = User::factory()->connected()->create();
    FakeGraph::fake(['GET me/messages/msg-1?*' => Http::response(['error' => ['code' => 'ErrorItemNotFound', 'message' => 'erika@example.test']], 404)]);

    expect(app(OutlookCategories::class)->replace($user, 'msg-1', OutlookCategories::CLICKUP, null))->toBeTrue();

    expect(logText($logs))->toContain('"user_id":'.$user->id)->not->toContain('erika@');
});

it('treats categories without case', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake([
        'GET me/messages/msg-1?*' => Http::response(['categories' => ['Clickup', 'clickup ✓']]),
        'PATCH me/messages/msg-1' => fn (Request $r) => Http::response(['categories' => $r['categories']]),
    ]);

    expect(app(OutlookCategories::class)->replace($user, 'msg-1', OutlookCategories::CLICKUP, OutlookCategories::CLICKUP_DONE))->toBeTrue();

    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && $r['categories'] === ['clickup ✓']);
});

it('throws other Graph errors', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(['GET me/messages/msg-1?*' => Http::response([], 403)]);

    app(OutlookCategories::class)->replace($user, 'msg-1', OutlookCategories::CLICKUP, null);
})->throws(GraphException::class);

it('creates the categories in the job queued after sign-in', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake([
        'GET me/outlook/masterCategories' => Http::response(['value' => []]),
        'POST me/outlook/masterCategories' => Http::response([], 201),
    ]);

    SetupOutlookCategories::dispatchSync($user->id);

    Http::assertSentCount(4);
});

it('skips the category job for a user without connection', function () {
    Http::fake();
    $user = User::factory()->create();

    SetupOutlookCategories::dispatchSync($user->id);

    Http::assertNothingSent();
});
