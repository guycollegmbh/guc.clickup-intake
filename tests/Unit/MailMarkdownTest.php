<?php

use App\Intake\MailMarkdown;
use App\Intake\SafeLinkConverter;

function markdown(string $html, ?string $fallback = null): string
{
    return (new MailMarkdown)->convert($html, $fallback);
}

it('drops script, style and head with their content', function () {
    $result = markdown('<html><head><title>Titel</title><style>p{color:red}</style></head><body><p>Guten Tag</p><script>alert(1)</script></body></html>');

    expect($result)->toBe('Guten Tag')
        ->not->toContain('alert')
        ->not->toContain('color:red')
        ->not->toContain('Titel');
});

it('leaves no raw HTML in the result', function () {
    $result = markdown('<div><font color="red">Rot</font> <o:p>x</o:p> <span style="x">y</span> <custom-tag>z</custom-tag> &lt;script&gt;alert(1)&lt;/script&gt; <a>ohne Ziel</a></div><pre>&lt;b&gt;code&lt;/b&gt;</pre><svg><script>alert(2)</script></svg><iframe src="https://x.test"></iframe>');

    expect($result)->not->toMatch('/<[a-z\/!?]/i')
        ->toContain('Rot')
        ->toContain('ohne Ziel')
        ->not->toContain('alert(2)');
});

it('removes links with javascript and data targets but keeps their text', function () {
    $result = markdown('<p><a href="javascript:alert(1)">eins</a> <a href=" JaVa&#x09;Script:alert(1)">zwei</a> <a href="data:text/html,x">drei</a> <a href="vbscript:x">vier</a> <a href="#anker">fünf</a></p>');

    expect($result)->toBe('eins zwei drei vier fünf')
        ->not->toContain('javascript')
        ->not->toContain('data:');
});

it('keeps http, https and mailto links', function () {
    $result = markdown('<p><a href="https://example.test/a?b=1&amp;c=2">Seite</a> <a href="mailto:erika@example.test">erika@example.test</a> <a href="http://example.test">http://example.test</a></p>');

    expect($result)->toBe('[Seite](https://example.test/a?b=1&c=2) [erika@example.test](mailto:erika@example.test) [http://example.test](http://example.test)');
});

it('drops images, including tracking pixels', function () {
    expect(markdown('<p>Text<img src="https://tracker.test/p.gif"><img src="cid:image001.png"></p>'))->toBe('Text');
});

it('keeps signatures, quotes, lists and emphasis', function () {
    $result = markdown('<p><b>Fett</b> und <i>kursiv</i></p><ul><li>eins</li><li>zwei</li></ul><blockquote>Zitat</blockquote><p>Freundliche Grüsse<br>Erika Beispiel</p>');

    expect($result)->toBe("**Fett** und *kursiv*\n\n- eins\n- zwei\n\n> Zitat\n\nFreundliche Grüsse\nErika Beispiel");
});

it('escapes Markdown in the text so it is not read as an image or link', function () {
    expect(markdown('<p>![x](https://tracker.test/p.gif) *fett*</p>'))->toBe('!\[x\](https://tracker.test/p.gif) \*fett\*');
});

it('separates the cells of layout tables', function () {
    expect(markdown('<table><tr><td>Erika Beispiel</td><td>Beispiel AG</td></tr><tr><td>Tel. 000</td></tr></table>'))
        ->toBe("Erika Beispiel Beispiel AG\nTel. 000");
});

it('falls back to the plain text without HTML', function () {
    expect(markdown('', "Zeile <b>eins</b>\nZeile zwei"))->toBe("Zeile &lt;b>eins&lt;/b>\nZeile zwei");
});

it('recognises safe link targets only', function (string $href, ?string $expected) {
    expect(SafeLinkConverter::safeHref($href))->toBe($expected);
})->with([
    ['https://example.test', 'https://example.test'],
    ['HTTP://example.test', 'HTTP://example.test'],
    ['mailto:erika@example.test', 'mailto:erika@example.test'],
    ['javascript:alert(1)', null],
    ["java\tscript:alert(1)", null],
    ['data:text/html,x', null],
    ['//example.test', null],
    ['/relative', null],
    ['https:///x', null],
]);
