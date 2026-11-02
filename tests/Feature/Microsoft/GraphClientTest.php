<?php

use App\Microsoft\GraphClient;
use App\Microsoft\GraphException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(fn () => Sleep::fake());

it('retries 429 and honours Retry-After', function () {
    Http::fake(['graph.microsoft.com/*' => Http::sequence()
        ->push([], 429, ['Retry-After' => '7'])
        ->push(['ok' => true])]);

    expect((new GraphClient('t'))->get('me'))->toBe(['ok' => true]);

    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(7)->seconds()]);
});

it('caps a long Retry-After', function () {
    Http::fake(['graph.microsoft.com/*' => Http::sequence()
        ->push([], 429, ['Retry-After' => '3600'])
        ->push(['ok' => true])]);

    (new GraphClient('t'))->get('me');

    Sleep::assertSequence([Sleep::for(60)->seconds()]);
});

it('retries a read on 503 and gives up after four attempts', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response(['error' => ['code' => 'ServiceUnavailable']], 503)]);

    expect(fn () => (new GraphClient('t'))->get('me'))
        ->toThrow(fn (GraphException $e) => expect($e->status)->toBe(503)->and($e->graphCode)->toBe('ServiceUnavailable'));

    Http::assertSentCount(4);
});

it('does not retry a POST on 503, it may have been executed', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response([], 503)]);

    expect(fn () => (new GraphClient('t'))->post('applications', []))->toThrow(GraphException::class);

    Http::assertSentCount(1);
});

it('turns a connection failure into a GraphException without details', function () {
    Http::fake(['graph.microsoft.com/*' => fn () => throw new ConnectionException('cURL error with token xyz')]);

    expect(fn () => (new GraphClient('t'))->get('me'))
        ->toThrow(fn (GraphException $e) => expect($e->getMessage())->not->toContain('xyz')->and($e->graphCode)->toBe('connection_failed'));
});

it('never puts the response body into the exception', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response(['error' => ['code' => 'Forbidden', 'message' => 'mailbox of ceo@guycolle.ch']], 403)]);

    expect(fn () => (new GraphClient('t'))->get('me'))
        ->toThrow(fn (GraphException $e) => expect($e->getMessage())->not->toContain('ceo@'));
});

it('sends the bearer token', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response([])]);

    (new GraphClient('secret-token'))->get('me');

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer secret-token')
        && $r->url() === 'https://graph.microsoft.com/v1.0/me');
});
