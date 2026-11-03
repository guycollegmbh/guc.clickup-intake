<?php

use App\ClickUp\ClickUpClient;
use App\ClickUp\ClickUpException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Psr\Http\Message\StreamInterface;
use Tests\Support\FakeClickUp;

beforeEach(fn () => Sleep::fake(syncWithCarbon: true));

it('sends the token as bearer to the API', function () {
    Http::fake(['api.clickup.com/*' => Http::response(['user' => ['id' => 501, 'username' => 'Ada']])]);

    expect((new ClickUpClient('secret-token'))->user())->toBe(['id' => 501, 'name' => 'Ada']);

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer secret-token')
        && $r->url() === 'https://api.clickup.com/api/v2/user');
});

it('exchanges the OAuth code with the parameters in the query string', function () {
    Http::fake(['api.clickup.com/*' => Http::response(['access_token' => 'new-token'])]);

    expect(ClickUpClient::exchangeCode('cid', 'csecret', 'the-code'))->toBe('new-token');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.clickup.com/api/v2/oauth/token?client_id=cid&client_secret=csecret&code=the-code');
});

it('throws without token if the code exchange fails', function () {
    Http::fake(['api.clickup.com/*' => Http::response(['err' => 'Code already used by csecret', 'ECODE' => 'OAUTH_015'], 400)]);

    expect(fn () => ClickUpClient::exchangeCode('cid', 'csecret', 'the-code'))
        ->toThrow(fn (ClickUpException $e) => expect($e->status)->toBe(400)
            ->and($e->errorCode)->toBe('OAUTH_015')
            ->and($e->getMessage())->not->toContain('csecret')
            ->and($e->getMessage())->not->toContain('the-code'));
});

it('builds the hierarchy with labels and reads only unarchived entries', function () {
    FakeClickUp::fake(FakeClickUp::workspaceRoutes());

    expect((new ClickUpClient('t'))->hierarchy(FakeClickUp::TEAM_ID))->toBe([
        ['id' => '901', 'label' => 'Kunden / Projekte / Website'],
        ['id' => '902', 'label' => 'Kunden / Allgemein'],
    ]);

    Http::assertSentCount(4);
});

it('lists the members of one workspace with a readable name', function () {
    FakeClickUp::fake(['GET team' => Http::response(FakeClickUp::teams())]);

    expect((new ClickUpClient('t'))->members(FakeClickUp::TEAM_ID))->toBe([
        ['id' => 501, 'name' => 'Ada Admin'],
        ['id' => 502, 'name' => 'max@guycolle.test'],
    ])->and((new ClickUpClient('t'))->members('unknown'))->toBe([]);
});

it('creates a task and returns id and url', function () {
    FakeClickUp::fake(['POST list/901/task' => Http::response(FakeClickUp::task())]);

    expect((new ClickUpClient('t'))->createTask('901', ['name' => 'Test']))
        ->toBe(['id' => 'abc123', 'url' => 'https://app.clickup.com/t/abc123']);

    Http::assertSent(fn (Request $r) => $r['name'] === 'Test');
});

it('builds the task url itself if ClickUp returns none or no https url', function () {
    FakeClickUp::fake(['POST list/901/task' => Http::response(['id' => 'abc123', 'url' => 'javascript:alert(1)'])]);

    expect((new ClickUpClient('t'))->createTask('901', ['name' => 'Test'])['url'])->toBe('https://app.clickup.com/t/abc123');
});

it('waits for X-RateLimit-Reset on 429 and retries', function () {
    $this->travelTo(now()->startOfMinute());
    FakeClickUp::fake(['GET user' => Http::sequence()
        ->push([], 429, ['X-RateLimit-Reset' => (string) (now()->getTimestamp() + 12)])
        ->push(['user' => ['id' => 1]])]);

    (new ClickUpClient('t'))->user();

    Sleep::assertSequence([Sleep::for(12)->seconds()]);
});

it('waits for Retry-After on 429 and caps a long wait', function () {
    FakeClickUp::fake(['GET user' => Http::sequence()
        ->push([], 429, ['Retry-After' => '3600'])
        ->push(['user' => ['id' => 1]])]);

    (new ClickUpClient('t'))->user();

    Sleep::assertSequence([Sleep::for(60)->seconds()]);
});

it('retries a POST on 429, it was not executed', function () {
    FakeClickUp::fake(['POST list/901/task' => Http::sequence()
        ->push([], 429, ['Retry-After' => '2'])
        ->push(FakeClickUp::task())]);

    (new ClickUpClient('t'))->createTask('901', ['name' => 'Test']);

    Http::assertSentCount(2);
});

it('retries a GET on 503 and 504', function () {
    FakeClickUp::fake(['GET user' => Http::sequence()
        ->push([], 503)
        ->push([], 504)
        ->push(['user' => ['id' => 1]])]);

    (new ClickUpClient('t'))->user();

    Http::assertSentCount(3);
});

it('never retries a POST on 5xx, the task may exist', function (int $status) {
    FakeClickUp::fake(['POST list/901/task' => Http::response([], $status)]);

    expect(fn () => (new ClickUpClient('t'))->createTask('901', ['name' => 'Test']))
        ->toThrow(fn (ClickUpException $e) => expect($e->status)->toBe($status)->and($e->isUncertain())->toBe($status !== 500));

    Http::assertSentCount(1);
})->with([500, 503, 504]);

it('turns a lost connection into an uncertain ClickUpException without details', function () {
    Http::fake(['api.clickup.com/*' => fn () => throw new ConnectionException('cURL error with token xyz')]);

    expect(fn () => (new ClickUpClient('t'))->comment('abc123', 'Hallo'))
        ->toThrow(fn (ClickUpException $e) => expect($e->status)->toBe(0)
            ->and($e->isUncertain())->toBeTrue()
            ->and($e->getMessage())->not->toContain('xyz'));
});

it('never puts the response text or the token into the exception', function () {
    FakeClickUp::fake(['GET user' => Http::response(['err' => 'Token secret-token of ceo@guycolle.ch invalid', 'ECODE' => 'OAUTH_025'], 400)]);

    expect(fn () => (new ClickUpClient('secret-token'))->user())
        ->toThrow(fn (ClickUpException $e) => expect($e->getMessage())->not->toContain('ceo@')
            ->and($e->getMessage())->not->toContain('secret-token')
            ->and($e->errorCode)->toBe('OAUTH_025'));
});

it('calls the 401 handler once and does not retry', function () {
    FakeClickUp::fake(['GET user' => Http::response(['ECODE' => 'OAUTH_027'], 401)]);
    $calls = new ArrayObject;
    $client = new ClickUpClient('t', fn () => $calls->append(true));

    expect(fn () => $client->user())->toThrow(ClickUpException::class)
        ->and($calls)->toHaveCount(1);
    Http::assertSentCount(1);
});

it('keeps to 100 requests per minute and token across clients', function () {
    $this->travelTo(now()->startOfMinute());
    FakeClickUp::fake(['GET user' => Http::response(['user' => ['id' => 1]])]);

    foreach (range(1, ClickUpClient::RATE_LIMIT) as $i) {
        (new ClickUpClient('busy-token'))->user();
    }

    Sleep::assertNeverSlept();

    (new ClickUpClient('busy-token'))->user();
    Sleep::assertSleptTimes(1);

    // Another token has its own limit.
    (new ClickUpClient('other-token'))->user();
    Sleep::assertSleptTimes(1);
});

it('keeps the token out of the rate limiter key', function () {
    FakeClickUp::fake(['GET user' => Http::response(['user' => ['id' => 1]])]);

    (new ClickUpClient('visible-token'))->user();

    expect(RateLimiter::attempts('clickup-rate:'.hash('sha256', 'visible-token')))->toBe(1)
        ->and(RateLimiter::attempts('clickup-rate:visible-token'))->toBe(0);
});

it('streams an upload from disk as multipart field attachment', function () {
    $path = tempnam(sys_get_temp_dir(), 'cu');
    file_put_contents($path, str_repeat('x', 1024));
    $part = null;
    FakeClickUp::fake(['POST task/abc123/attachment' => function (Request $r) use (&$part) {
        $part = $r->data()[0] ?? null;

        return Http::response(['id' => 'att-1']);
    }]);

    (new ClickUpClient('t'))->attach('abc123', $path, 'offerte.pdf');

    expect($part['name'])->toBe('attachment')
        ->and($part['filename'])->toBe('offerte.pdf')
        ->and($part['contents'])->toBeInstanceOf(StreamInterface::class);
    Http::assertSent(fn (Request $r) => $r->isMultipart() && $r->hasHeader('Authorization', 'Bearer t'));

    unlink($path);
});

it('reopens the file when an upload is retried after 429', function () {
    $path = tempnam(sys_get_temp_dir(), 'cu');
    file_put_contents($path, 'inhalt');
    $bodies = [];
    FakeClickUp::fake(['POST task/abc123/attachment' => function (Request $r) use (&$bodies) {
        $bodies[] = $r->body();

        return count($bodies) === 1 ? Http::response([], 429, ['Retry-After' => '1']) : Http::response([]);
    }]);

    (new ClickUpClient('t'))->attach('abc123', $path, 'notiz.txt');

    expect($bodies)->toHaveCount(2)
        ->and($bodies[1])->toContain('inhalt');

    unlink($path);
});

it('stops waiting for a free slot after the given time and sends nothing', function () {
    $this->travelTo(now()->startOfMinute());
    RateLimiter::increment('clickup-rate:'.hash('sha256', 'busy-token'), 60, ClickUpClient::RATE_LIMIT);
    Http::fake();

    expect(fn () => (new ClickUpClient('busy-token', null, ClickUpClient::INTERACTIVE_WAIT_SECONDS))->user())
        ->toThrow(fn (ClickUpException $e) => expect($e->status)->toBe(429)
            ->and($e->errorCode)->toBe(ClickUpClient::LOCAL_RATE_LIMIT)
            ->and($e->isUncertain())->toBeFalse());

    Http::assertNothingSent();
    Sleep::assertSequence([Sleep::for(5)->seconds()]);
});

it('reports whether a slot is free within the wait time without taking it', function () {
    $this->travelTo(now()->startOfMinute());
    $key = 'clickup-rate:'.hash('sha256', 'busy-token');

    expect((new ClickUpClient('busy-token', null, 5))->waitForCapacity())->toBeTrue()
        ->and(RateLimiter::attempts($key))->toBe(0);

    RateLimiter::increment($key, 60, ClickUpClient::RATE_LIMIT);

    expect((new ClickUpClient('busy-token', null, 5))->waitForCapacity())->toBeFalse()
        // In a job the window ends while waiting.
        ->and((new ClickUpClient('busy-token', null, 60))->waitForCapacity())->toBeTrue();
});

it('reads the attachments and comments of a task for the check of unclear outcomes', function () {
    FakeClickUp::fake([
        'GET task/abc123' => Http::response(['id' => 'abc123', 'attachments' => [
            ['id' => 'a1', 'title' => 'offerte.pdf', 'size' => 1234],
            ['id' => 'a2', 'title' => 'plan.dwg', 'size' => '99'],
        ]]),
        'GET task/abc123/comment' => Http::response(['comments' => [['comment_text' => 'Hallo'], ['comment_text' => 'Welt']]]),
    ]);
    $client = new ClickUpClient('t');

    expect($client->taskAttachments('abc123'))->toBe([['name' => 'offerte.pdf', 'size' => 1234], ['name' => 'plan.dwg', 'size' => 99]])
        ->and($client->commentTexts('abc123'))->toBe(['Hallo', 'Welt']);
});
