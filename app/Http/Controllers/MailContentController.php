<?php

namespace App\Http\Controllers;

use App\Intake\AttachmentStore;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mail content outside the panel: original HTML for the sandboxed iframe, inline images and
 * attachment downloads (R12, R13). Everything here is hostile input of the sender.
 */
class MailContentController extends Controller
{
    public const INLINE_URL_MINUTES = 10;

    /**
     * Images of the mail itself (signed URLs) and data: only. External images and tracking
     * pixels are blocked, no script, no form, no plugin. The sandbox directive also applies
     * when the URL is opened directly instead of in the iframe.
     *
     * Safari does not match 'self' in a sandboxed document (opaque origin), so the origin
     * of APP_URL is listed as well (verified in WebKit and Chrome, step 4).
     */
    public static function originalCsp(): string
    {
        $origin = self::appOrigin();

        return "default-src 'none'; img-src 'self'".($origin === null ? '' : ' '.$origin).' data:; '
            ."style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'; sandbox";
    }

    public function __construct(private AttachmentStore $store) {}

    /**
     * The mail HTML for <iframe sandbox="">. cid: references become short-lived signed URLs
     * of the inline image route: requests from an iframe without allow-same-origin carry no
     * session cookie in Chrome, so the image route cannot rely on the session (decisions,
     * round 1, point 8, verified in Chrome and WebKit).
     */
    public function original(IntakeItem $item): Response
    {
        Gate::authorize('view', $item);

        return response($this->withInlineImages($item), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => self::originalCsp(),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Inline image of an item, reachable by signed URL only (no session, see original()).
     * Serves images by their stored bytes only, never SVG (it can carry script).
     */
    public function inline(IntakeItem $item, IntakeAttachment $attachment): StreamedResponse
    {
        abort_if($attachment->storage_path === null, 404);

        $type = $this->store->detectMimeType($attachment);
        abort_unless(is_string($type) && str_starts_with($type, 'image/') && ! str_contains($type, 'svg'), 404);

        return Storage::disk(AttachmentStore::DISK)->response($attachment->storage_path, 'image', [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, max-age='.(self::INLINE_URL_MINUTES * 60),
        ]);
    }

    /**
     * Always a download, never shown in the browser, whatever the content claims to be.
     */
    public function download(IntakeAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);
        abort_if($attachment->storage_path === null, 404);

        return Storage::disk(AttachmentStore::DISK)->download($attachment->storage_path, self::downloadName($attachment->name), [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * File name for Content-Disposition: no control or bidi characters, no path separators,
     * no quotes. The import cleans names already, this does not rely on it.
     */
    public static function downloadName(string $name): string
    {
        $clean = preg_replace('/[\p{Cc}\p{Cf}\/\\\\"]+/u', '', $name) ?? '';
        $clean = trim(Str::limit($clean, 200, ''), " .\t");

        return $clean === '' ? 'anhang' : $clean;
    }

    /**
     * scheme://host[:port] of APP_URL, null if it does not look like one.
     */
    private static function appOrigin(): ?string
    {
        $url = (string) config('app.url');

        return preg_match('#^(https?://[a-z0-9.-]+(?::\d+)?)(?:/|$)#i', $url, $match) === 1 ? Str::lower($match[1]) : null;
    }

    private function withInlineImages(IntakeItem $item): string
    {
        $urls = [];

        foreach ($item->attachments()->whereNotNull('content_id')->whereNotNull('storage_path')->get() as $attachment) {
            $urls[self::contentIdKey($attachment->content_id)] = URL::temporarySignedRoute(
                'intake.inline',
                now()->addMinutes(self::INLINE_URL_MINUTES),
                ['item' => $item->id, 'attachment' => $attachment->id],
                absolute: false,
            );
        }

        return preg_replace_callback(
            '/cid:([^\s"\'<>()\\\\]+)/i',
            fn (array $match): string => isset($urls[self::contentIdKey($match[1])])
                ? e($urls[self::contentIdKey($match[1])])
                : $match[0],
            (string) $item->body_html,
        ) ?? '';
    }

    private static function contentIdKey(string $contentId): string
    {
        return Str::lower(trim(rawurldecode(html_entity_decode($contentId)), '<> '));
    }
}
