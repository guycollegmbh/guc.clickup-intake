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

it('sends the client and per-call Prefer values in one header', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response([])]);

    (new GraphClient('t', ['IdType="ImmutableId"']))->get('me/messages/x', [], ['outlook.body-content-type="html"']);

    Http::assertSent(fn ($r) => $r->header('Prefer') === ['IdType="ImmutableId", outlook.body-content-type="html"']);
});

it('sends no Prefer header by default', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response([])]);

    (new GraphClient('t'))->get('me');

    Http::assertSent(fn ($r) => ! $r->hasHeader('Prefer'));
});

it('streams content as a resource and retries 429 first', function () {
    Http::fake(['graph.microsoft.com/*' => Http::sequence()
        ->push('', 429, ['Retry-After' => '1'])
        ->push('binary-content')]);

    $stream = (new GraphClient('t'))->stream('me/messages/m/attachments/a/$value');

    expect(is_resource($stream))->toBeTrue()
        ->and(stream_get_contents($stream))->toBe('binary-content');
    fclose($stream);
    Http::assertSentCount(2);
});

it('throws a GraphException when a stream cannot be opened', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404)]);

    expect(fn () => (new GraphClient('t'))->stream('me/messages/m/attachments/a/$value'))
        ->toThrow(fn (GraphException $e) => expect($e->isNotFound())->toBeTrue());
});

it('follows an absolute nextLink below the Graph base URL', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response(['value' => []])]);

    (new GraphClient('t'))->get(GraphClient::BASE_URL.'/me/messages?$skiptoken=abc');

    Http::assertSent(fn ($r) => urldecode($r->url()) === GraphClient::BASE_URL.'/me/messages?$skiptoken=abc');
});

it('never sends the token to another host', function () {
    Http::fake();

    expect(fn () => (new GraphClient('t'))->get('https://evil.example/v1.0/me'))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('keeps the query string of a nextLink out of the exception', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response([], 400)]);

    expect(fn () => (new GraphClient('t'))->get(GraphClient::BASE_URL.'/me/messages?$skiptoken=secret-skip'))
        ->toThrow(fn (GraphException $e) => expect($e->getMessage())->not->toContain('secret-skip'));
});
