<?php

use App\Http\Controllers\MailContentController;
use App\Intake\AttachmentStore;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => Storage::fake('local'));

/** A 1x1 PNG. */
const PNG_BYTES = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

/**
 * @param  array<string, mixed>  $attributes
 */
function storedAttachment(IntakeItem $item, string $content, array $attributes = []): IntakeAttachment
{
    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, $content);
    rewind($stream);

    return app(AttachmentStore::class)->store($item, [
        'graph_attachment_id' => 'att-'.uniqid(),
        'name' => 'datei.bin',
        'size' => strlen($content),
        ...$attributes,
    ], $stream);
}

function inlineUrl(IntakeItem $item, IntakeAttachment $attachment, int $minutes = 10): string
{
    return URL::temporarySignedRoute('intake.inline', now()->addMinutes($minutes), [
        'item' => $item->id,
        'attachment' => $attachment->id,
    ], absolute: false);
}

it('downloads an attachment as attachment with a clean name and safe headers', function () {
    $item = IntakeItem::factory()->create();
    $attachment = storedAttachment($item, '<html><script>alert(1)</script></html>', ['name' => "Offerte \u{202E}fdp.exe\"\n.html"]);

    $response = $this->actingAs($item->user)->get(route('intake.attachment', ['attachment' => $attachment->id]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', 'sandbox');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->not->toContain("\u{202E}")
        ->not->toContain("\n")
        ->toContain('Offerte fdp.exe.html')
        ->and($response->streamedContent())->toBe('<html><script>alert(1)</script></html>');
});

it('denies the download of a foreign attachment and to guests', function () {
    $item = IntakeItem::factory()->create();
    $attachment = storedAttachment($item, 'x');

    $this->get(route('intake.attachment', ['attachment' => $attachment->id]))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('intake.attachment', ['attachment' => $attachment->id]))->assertForbidden();
    $this->actingAs(User::factory()->inactive()->create())->get(route('intake.attachment', ['attachment' => $attachment->id]))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('intake.attachment', ['attachment' => $attachment->id]))->assertOk();
});

it('answers 404 for an attachment without file', function () {
    $item = IntakeItem::factory()->create();
    $attachment = IntakeAttachment::factory()->for($item, 'item')->create(['type' => 'reference']);

    $this->actingAs($item->user)->get(route('intake.attachment', ['attachment' => $attachment->id]))->assertNotFound();
});

it('cleans download names', function (string $name, string $expected) {
    expect(MailContentController::downloadName($name))->toBe($expected);
})->with([
    ['offerte.pdf', 'offerte.pdf'],
    ['../../etc/passwd', 'etcpasswd'],
    ["a\u{202E}b\u{0000}c\"d\\e.txt", 'abcde.txt'],
    ['   ', 'anhang'],
    ['', 'anhang'],
]);

it('serves the original with a strict CSP and no script', function () {
    config(['app.url' => 'https://intake.example.test']);
    $item = IntakeItem::factory()->create([
        'body_html' => '<html><body><p>Hallo</p><script>alert(1)</script><img src="https://tracker.test/p.gif"></body></html>',
    ]);

    $response = $this->actingAs($item->user)->get(route('intake.original', ['item' => $item->id]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'none'")
        // Safari does not match 'self' in a sandboxed document, hence the app origin.
        ->toContain("img-src 'self' https://intake.example.test data:;")
        ->toContain("style-src 'unsafe-inline'")
        ->toContain("frame-ancestors 'self'")
        ->toContain('sandbox')
        ->not->toContain('script-src')
        ->not->toContain('https:;')
        ->not->toContain('*')
        // External images stay in the HTML, the CSP blocks them (tracking pixels).
        ->and($response->getContent())->toContain('https://tracker.test/p.gif');
});

it('replaces cid references with short-lived signed URLs of the item', function () {
    $item = IntakeItem::factory()->create(['body_html' => '<p><img src="cid:image001.png@01DA"><img src="CID:unknown@x"></p>']);
    $image = storedAttachment($item, PNG_BYTES, ['name' => 'image001.png', 'content_id' => '<Image001.png@01DA>', 'is_inline' => true]);

    $html = $this->actingAs($item->user)->get(route('intake.original', ['item' => $item->id]))->getContent();

    expect($html)->not->toContain('cid:image001.png')
        ->toContain('/mail/'.$item->id.'/inline/'.$image->id.'?expires=')
        ->toContain('&amp;signature=')
        ->toContain('CID:unknown@x');

    preg_match('#src="([^"]+/inline/[^"]+)"#', $html, $match);
    auth()->logout();
    $this->get(html_entity_decode($match[1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('lists no origin in the CSP when APP_URL is not a plain origin', function () {
    config(['app.url' => 'https://x.test; script-src *']);

    expect(MailContentController::originalCsp())->toContain("img-src 'self' data:;")
        ->not->toContain('script-src');
});

it('denies the original of a foreign item', function () {
    $item = IntakeItem::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('intake.original', ['item' => $item->id]))->assertForbidden();
});

it('serves inline images only with a valid signature', function () {
    $item = IntakeItem::factory()->create();
    $image = storedAttachment($item, PNG_BYTES, ['content_id' => 'img@x', 'is_inline' => true]);

    $this->get(inlineUrl($item, $image))->assertOk();
    $this->get("/mail/{$item->id}/inline/{$image->id}")->assertForbidden();
    $this->get(inlineUrl($item, $image).'x')->assertForbidden();

    $expired = inlineUrl($item, $image);
    $this->travel(11)->minutes();
    $this->get($expired)->assertForbidden();
});

it('serves no inline content that is not an image of this item', function () {
    $item = IntakeItem::factory()->create();
    $html = storedAttachment($item, '<html><script>alert(1)</script></html>', ['content_type' => 'image/png', 'is_inline' => true]);
    $svg = storedAttachment($item, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', ['is_inline' => true]);
    $other = IntakeItem::factory()->create();
    $foreign = storedAttachment($other, PNG_BYTES, ['is_inline' => true]);

    $this->get(inlineUrl($item, $html))->assertNotFound();
    $this->get(inlineUrl($item, $svg))->assertNotFound();
    $this->get(inlineUrl($item, $foreign))->assertNotFound();
});
