<?php

namespace Tests\Support;

use App\Microsoft\GraphClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Graph fixtures without real mails or personal data, and a router for Http::fake().
 *
 * Routes are "METHOD glob" against the decoded path and query below /v1.0/, e.g.
 * "GET me/messages/msg-1/attachments*". The first match wins. Values are responses,
 * Http::sequence() or closures receiving the request.
 *
 * Behaves like the tenant where verified (E8): an attachment list with an unqualified
 * "contentId" in $select is answered with 400.
 */
class FakeGraph
{
    /**
     * @param  array<string, mixed>  $routes
     */
    public static function fake(array $routes): void
    {
        Http::fake(function (Request $request) use ($routes) {
            // Other hosts (token endpoint) are left to further Http::fake() stubs.
            if (! str_starts_with($request->url(), GraphClient::BASE_URL.'/')) {
                return null;
            }

            $path = self::path($request);

            if (self::selectsBareContentId($path)) {
                return Http::response(['error' => ['code' => 'BadRequest']], 400);
            }

            foreach ($routes as $pattern => $response) {
                [$method, $glob] = explode(' ', $pattern, 2);

                if ($request->method() === $method && Str::is($glob, $path)) {
                    return is_callable($response) ? $response($request) : $response;
                }
            }

            throw new RuntimeException("Unexpected request: {$request->method()} {$request->url()}");
        });
    }

    private static function selectsBareContentId(string $path): bool
    {
        if (! preg_match('#/attachments\?.*\$select=([^&]*)#', $path, $match)) {
            return false;
        }

        return in_array('contentId', explode(',', $match[1]), true);
    }

    public static function path(Request $request): string
    {
        return urldecode(Str::after($request->url(), GraphClient::BASE_URL.'/'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function summary(string $id, array $overrides = []): array
    {
        return [
            'id' => $id,
            'internetMessageId' => "<{$id}@mail.example.test>",
            'subject' => "Anfrage {$id}",
            'from' => ['emailAddress' => ['name' => 'Erika Beispiel', 'address' => 'erika@example.test']],
            'receivedDateTime' => '2026-11-01T08:00:00Z',
            'categories' => ['ClickUp'],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function message(string $id, array $overrides = []): array
    {
        return [
            ...self::summary($id),
            'toRecipients' => [['emailAddress' => ['name' => 'Team', 'address' => 'team@guycolle.test']]],
            'ccRecipients' => [['emailAddress' => ['name' => 'Max Muster', 'address' => 'max@example.test']]],
            'webLink' => "https://outlook.office365.com/owa/?ItemID={$id}",
            'body' => [
                'contentType' => 'html',
                'content' => '<html><head><style>p{color:red}</style></head><body><p>Guten Tag</p><p>Bitte pr&uuml;fen.<br>Danke</p></body></html>',
            ],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function fileAttachment(string $id, string $name = 'offerte.pdf', int $size = 12, array $overrides = []): array
    {
        return [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'id' => $id,
            'name' => $name,
            'contentType' => 'application/pdf',
            'size' => $size,
            'isInline' => false,
            'contentId' => null,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function itemAttachment(string $id, string $name = 'Weitergeleitete Mail'): array
    {
        return [
            '@odata.type' => '#microsoft.graph.itemAttachment',
            'id' => $id,
            'name' => $name,
            'contentType' => null,
            'size' => 40,
            'isInline' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function referenceAttachment(string $id, string $name = 'Projektplan.xlsx'): array
    {
        return [
            '@odata.type' => '#microsoft.graph.referenceAttachment',
            'id' => $id,
            'name' => $name,
            'contentType' => null,
            'size' => 0,
            'isInline' => false,
        ];
    }

    /**
     * Routes for one message: full message, attachment list and attachment contents.
     *
     * @param  array<string, mixed>  $message
     * @param  list<array<string, mixed>>  $attachments
     * @param  array<string, mixed>  $contents  attachment id => body string or response
     * @return array<string, mixed>
     */
    public static function messageRoutes(array $message, array $attachments = [], array $contents = []): array
    {
        $id = $message['id'];
        $routes = [];

        foreach ($contents as $attachmentId => $content) {
            $routes["GET me/messages/{$id}/attachments/{$attachmentId}/\$value"] = is_string($content)
                ? Http::response($content)
                : $content;
        }

        $routes["GET me/messages/{$id}/attachments*"] = Http::response(['value' => $attachments]);
        $routes["GET me/messages/{$id}?*"] = Http::response($message);

        return $routes;
    }
}
