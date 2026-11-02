<?php

use App\Enums\ConnectionStatus;
use App\Enums\IntakeStatus;
use App\Intake\AttachmentStore;
use App\Intake\MailboxPoller;
use App\Jobs\PollMailbox;
use App\Microsoft\GraphClient;
use App\Microsoft\GraphException;
use App\Models\IntakeItem;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\FakeGraph;

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
});

const FILTER_PATH = "me/messages?\$filter=categories/any(c:c eq 'ClickUp')*";

it('imports all marked mails end to end, following the next page', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake([
        'GET me/messages?$skiptoken=page2' => Http::response(['value' => [FakeGraph::summary('msg-3')]]),
        'GET '.FILTER_PATH => Http::response([
            'value' => [FakeGraph::summary('msg-1'), FakeGraph::summary('msg-2')],
            '@odata.nextLink' => GraphClient::BASE_URL.'/me/messages?$skiptoken=page2',
        ]),
        ...FakeGraph::messageRoutes(FakeGraph::message('msg-1'), [FakeGraph::fileAttachment('att-1')], ['att-1' => 'eins']),
        ...FakeGraph::messageRoutes(FakeGraph::message('msg-2')),
        ...FakeGraph::messageRoutes(FakeGraph::message('msg-3')),
    ]);

    $this->artisan('intake:poll')->assertSuccessful();

    expect(IntakeItem::query()->orderBy('graph_message_id')->pluck('graph_message_id')->all())->toBe(['msg-1', 'msg-2', 'msg-3'])
        ->and(IntakeItem::query()->whereNull('imported_at')->count())->toBe(0)
        ->and(IntakeItem::query()->where('status', '!=', IntakeStatus::Pending->value)->count())->toBe(0);

    Http::assertSent(fn (Request $r): bool => str_contains(FakeGraph::path($r), '$top=25')
        && str_contains(FakeGraph::path($r), '$select=id,internetMessageId,subject,from,receivedDateTime,categories'));

    // Second run: everything known, nothing imported again.
    $this->artisan('intake:poll')->assertSuccessful();
    expect(IntakeItem::count())->toBe(3);
});

it('stops at the page limit', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake([
        'GET me/messages?$skiptoken=*' => Http::response(['value' => [], '@odata.nextLink' => GraphClient::BASE_URL.'/me/messages?$skiptoken=next']),
        'GET '.FILTER_PATH => Http::response(['value' => [], '@odata.nextLink' => GraphClient::BASE_URL.'/me/messages?$skiptoken=next']),
    ]);

    app(MailboxPoller::class)->poll($user);

    Http::assertSentCount(MailboxPoller::MAX_PAGES);
});

it('falls back to the inbox of the last days when Graph rejects the filter', function () {
    $logs = captureLogs();
    $user = User::factory()->connected()->create();
    FakeGraph::fake([
        'GET '.FILTER_PATH => Http::response(['error' => ['code' => 'InefficientFilter']], 400),
        'GET me/mailFolders/inbox/messages*' => Http::response(['value' => [
            FakeGraph::summary('msg-1', ['categories' => ['Clickup']]),
            FakeGraph::summary('msg-2', ['categories' => ['Rot']]),
            FakeGraph::summary('msg-3', ['categories' => ['ClickUp ✓']]),
        ]]),
        ...FakeGraph::messageRoutes(FakeGraph::message('msg-1')),
    ]);

    app(MailboxPoller::class)->poll($user);

    expect(IntakeItem::sole()->graph_message_id)->toBe('msg-1');
    Http::assertSent(fn (Request $r): bool => str_starts_with(FakeGraph::path($r), 'me/mailFolders/inbox/messages?')
        && str_contains(FakeGraph::path($r), '$filter=receivedDateTime ge '.now()->utc()->subDays(7)->format('Y-m-d')));
    expect(logText($logs))->toContain('Graph rejected the category filter');
});

it('does not fall back on other errors', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(['GET '.FILTER_PATH => Http::response(['error' => ['code' => 'ErrorAccessDenied']], 403)]);

    expect(fn () => app(MailboxPoller::class)->poll($user))->toThrow(GraphException::class);
    Http::assertSentCount(1);
});

it('keeps importing the other mails when one fails', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake([
        'GET '.FILTER_PATH => Http::response(['value' => [FakeGraph::summary('msg-1'), FakeGraph::summary('msg-2')]]),
        ...FakeGraph::messageRoutes(FakeGraph::message('msg-1')),
        ...FakeGraph::messageRoutes(FakeGraph::message('msg-2')),
        // Same key as in messageRoutes: replaces the attachment list of msg-1.
        'GET me/messages/msg-1/attachments*' => Http::response([], 500),
    ]);

    app(MailboxPoller::class)->poll($user);

    expect(IntakeItem::firstWhere('graph_message_id', 'msg-1')->status)->toBe(IntakeStatus::Failed)
        ->and(IntakeItem::firstWhere('graph_message_id', 'msg-2')->status)->toBe(IntakeStatus::Pending);
});

it('ends without touching items when the connection is broken', function () {
    configureMicrosoftApp();
    $user = User::factory()->connected()->create(['ms_token_expires_at' => now()->subMinute()]);
    $item = IntakeItem::factory()->for($user)->failed()->create();
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    PollMailbox::dispatchSync($user->id);

    expect($user->fresh()->ms_connection_status)->toBe(ConnectionStatus::Broken)
        ->and($item->fresh()->status)->toBe(IntakeStatus::Failed);
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'graph.microsoft.com'));
});

it('ends quietly when no token is available for now', function () {
    configureMicrosoftApp();
    $user = User::factory()->connected()->create(['ms_token_expires_at' => now()->subMinute()]);
    Http::fake(['login.microsoftonline.com/*' => Http::response([], 503)]);

    PollMailbox::dispatchSync($user->id);

    expect($user->fresh()->ms_connection_status)->toBe(ConnectionStatus::Connected);
});

it('queues a poll only for active users with a Microsoft connection', function () {
    Queue::fake();
    $connected = User::factory()->connected()->create();
    User::factory()->connected()->inactive()->create();
    User::factory()->create();
    $broken = User::factory()->connected()->create();
    $broken->forceFill(['ms_connection_status' => ConnectionStatus::Broken])->save();

    $this->artisan('intake:poll')->assertSuccessful();

    Queue::assertPushed(PollMailbox::class, 1);
    Queue::assertPushed(PollMailbox::class, fn (PollMailbox $job): bool => $job->userId === $connected->id);
});

it('queues at most one poll per user', function () {
    Queue::fake();
    $anna = User::factory()->connected()->create();
    $max = User::factory()->connected()->create();

    PollMailbox::dispatch($anna->id);
    PollMailbox::dispatch($anna->id);
    PollMailbox::dispatch($max->id);

    Queue::assertPushed(PollMailbox::class, 2);
});

it('times out before the queue hands the job to another worker', function () {
    $job = new PollMailbox(1);

    expect($job->uniqueId())->toBe('1')
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'))
        ->and($job->timeout)->toBeLessThan(config('queue.connections.database.retry_after'));
});

it('runs the poll every two minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'intake:poll'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/2 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('records when the mailbox was polled last', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(['GET '.FILTER_PATH => Http::response(['value' => []])]);

    app(MailboxPoller::class)->poll($user);

    expect($user->fresh()->ms_last_polled_at)->not->toBeNull();
});

it('imports nothing when the disk is almost full', function () {
    $logs = captureLogs();
    config(['intake.min_free_disk_mb' => 1_000_000_000]);
    Http::fake();
    $user = User::factory()->connected()->create();

    app(MailboxPoller::class)->poll($user);

    Http::assertNothingSent();
    expect(logText($logs))->toContain('not enough free disk space')
        ->and($user->fresh()->ms_last_polled_at)->toBeNull();
});

it('turns an aborted import into a failed import and removes its partial attachments', function () {
    $user = User::factory()->connected()->create();
    $item = IntakeItem::factory()->for($user)->importing()->create();
    app(AttachmentStore::class)->store($item, ['graph_attachment_id' => 'att-1', 'name' => 'a.pdf', 'size' => 1], (function () {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'a');
        rewind($stream);

        return $stream;
    })());
    $item->attachments()->create(['graph_attachment_id' => 'att-2', 'name' => 'halb.pdf', 'size' => 1]);
    $old = now()->subMinutes(AttachmentStore::STALE_AFTER_MINUTES + 5);
    IntakeItem::query()->whereKey($item->id)->update(['updated_at' => $old]);
    DB::table('intake_attachments')->update(['updated_at' => $old]);
    $recent = IntakeItem::factory()->for($user)->importing()->create();
    FakeGraph::fake(['GET '.FILTER_PATH => Http::response(['value' => []])]);

    app(MailboxPoller::class)->poll($user);

    $item->refresh();
    expect($item->status)->toBe(IntakeStatus::Failed)
        ->and($item->error)->toBe('Der Import wurde abgebrochen.')
        ->and($item->imported_at)->toBeNull()
        ->and($item->next_import_at)->not->toBeNull()
        ->and($item->attachments()->count())->toBe(0)
        ->and($recent->fresh()->status)->toBe(IntakeStatus::Pending)
        ->and(app(AttachmentStore::class)->orphans())->toBe([]);
    $this->artisan('intake:orphans')->assertSuccessful();
});

it('starts no further mail after its time limit', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake([
        'GET '.FILTER_PATH => Http::response(['value' => [FakeGraph::summary('msg-1'), FakeGraph::summary('msg-2')]]),
        ...FakeGraph::messageRoutes(FakeGraph::message('msg-1')),
        'GET me/messages/msg-1?*' => function () {
            Carbon::setTestNow(now()->addSeconds(MailboxPoller::DEADLINE_SECONDS + 1));

            return Http::response(FakeGraph::message('msg-1'));
        },
    ]);

    app(MailboxPoller::class)->poll($user);

    expect(IntakeItem::sole()->graph_message_id)->toBe('msg-1');
    Http::assertNotSent(fn (Request $r): bool => str_contains(FakeGraph::path($r), 'msg-2'));
});

it('keeps polling when the token cannot be renewed for the second mail', function () {
    configureMicrosoftApp();
    $user = User::factory()->connected()->create();
    $finished = IntakeItem::factory()->for($user)->create([
        'graph_message_id' => 'msg-2',
        'internet_message_id' => '<msg-2@mail.example.test>',
        'from_email' => 'erika@example.test',
        'received_at' => '2026-11-01T08:00:00Z',
        'status' => IntakeStatus::Created,
        'processed_at' => now(),
    ]);
    FakeGraph::fake([
        'GET '.FILTER_PATH => Http::response(['value' => [FakeGraph::summary('msg-1'), FakeGraph::summary('msg-2'), FakeGraph::summary('msg-3')]]),
        ...FakeGraph::messageRoutes(FakeGraph::message('msg-1')),
        ...FakeGraph::messageRoutes(FakeGraph::message('msg-3')),
        'GET me/messages/msg-1?*' => function () use ($user) {
            // The stored access token expires while the poll runs.
            User::query()->whereKey($user->id)->update(['ms_token_expires_at' => now()->subMinute()]);

            return Http::response(FakeGraph::message('msg-1'));
        },
    ]);
    Http::fake(['login.microsoftonline.com/*' => Http::response([], 503)]);
    $logs = captureLogs();

    app(MailboxPoller::class)->poll($user);

    expect(logText($logs))->toContain('MicrosoftTokenUnavailable');
    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://login.microsoftonline.com/'));

    expect(IntakeItem::query()->whereIn('graph_message_id', ['msg-1', 'msg-3'])->whereNotNull('imported_at')->count())->toBe(2)
        ->and($finished->fresh()->outlook_category_synced_at)->toBeNull()
        ->and($user->fresh()->ms_connection_status)->toBe(ConnectionStatus::Connected);
});

it('ends the job without failure when the mail list cannot be read', function () {
    $logs = captureLogs();
    $user = User::factory()->connected()->create();
    FakeGraph::fake(['GET '.FILTER_PATH => Http::response(['error' => ['code' => 'InternalServerError', 'message' => 'erika@example.test']], 500)]);

    PollMailbox::dispatchSync($user->id);

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(logText($logs))->toContain('could not read the mail list')->not->toContain('erika@')
        ->and($user->fresh()->ms_access_token)->toBe('access-token-valid');
});

it('drops the cached access token when Graph rejects it', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(['GET '.FILTER_PATH => Http::response(['error' => ['code' => 'InvalidAuthenticationToken']], 401)]);

    PollMailbox::dispatchSync($user->id);

    $user = $user->fresh();
    expect($user->ms_access_token)->toBeNull()
        ->and($user->ms_token_expires_at)->toBeNull()
        ->and($user->ms_connection_status)->toBe(ConnectionStatus::Connected);
});
