<?php

namespace App\Intake;

use Illuminate\Support\Facades\Log;
use League\HTMLToMarkdown\HtmlConverter;
use Throwable;

/**
 * Mail HTML to Markdown for the task description (step 4).
 *
 * The HTML is hostile input. The result contains no HTML: script, style, head, images and
 * embedded content are dropped with their content, all other tags without a Markdown form
 * are stripped (their text stays). Links survive only with http, https or mailto
 * (SafeLinkConverter). Images are dropped, inline ones are attachments and external ones
 * would be loaded wherever the Markdown is shown (tracking). HTML entities left by the converter
 * (e.g. "&lt;name@x.ch&gt;" in forwarded headers) are decoded for readability, then as a last line
 * every "<" that could start a tag is escaped as "\<" (CommonMark backslash escape), also inside
 * code blocks. Entities that encode Markdown syntax (e.g. "&#91;") stay encoded, they cannot form
 * structure in CommonMark.
 *
 * Signatures and quoted replies stay (specification).
 */
class MailMarkdown
{
    private const REMOVED_ELEMENTS = [
        'head', 'title', 'meta', 'link', 'style', 'script', 'noscript', 'template',
        'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'svg', 'math',
        'img', 'picture', 'video', 'audio', 'canvas', 'map',
    ];

    /** Longest description, also the limit of the editor. */
    public const MAX_LENGTH = 100_000;

    public const TRUNCATED = '(gekürzt)';

    private HtmlConverter $converter;

    public function __construct()
    {
        $this->converter = new HtmlConverter([
            'strip_tags' => true,
            'strip_placeholder_links' => true,
            'remove_nodes' => implode(' ', self::REMOVED_ELEMENTS),
            'hard_break' => true,
            'header_style' => 'atx',
            'use_autolinks' => false,
            'preserve_comments' => false,
        ]);
        $this->converter->getEnvironment()->addConverter(new SafeLinkConverter);
    }

    /**
     * @param  string|null  $fallbackText  used if the HTML is empty or cannot be converted. It is
     *                                     sender input too and takes the same HTML path
     *                                     (escaped), so it cannot carry links or images.
     */
    public function convert(?string $html, ?string $fallbackText = null): string
    {
        $markdown = $this->fromHtml((string) $html);

        if ($markdown === null && trim((string) $fallbackText) !== '') {
            $markdown = $this->fromHtml(nl2br(e((string) $fallbackText)))
                ?? addcslashes(e((string) $fallbackText), '\\`*_[]()!#');
        }

        return self::limit(self::tidy((string) $markdown));
    }

    private function fromHtml(string $html): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        try {
            return $this->converter->convert(self::separateTableCells($html));
        } catch (Throwable $e) {
            Log::warning('Mail HTML could not be converted to Markdown.', ['exception' => $e::class]);

            return null;
        }
    }

    /**
     * Very long mails are cut for the editor and ClickUp, with a visible note.
     */
    private static function limit(string $markdown): string
    {
        if (mb_strlen($markdown) <= self::MAX_LENGTH) {
            return $markdown;
        }

        return rtrim(mb_substr($markdown, 0, self::MAX_LENGTH - mb_strlen(self::TRUNCATED) - 2))."\n\n".self::TRUNCATED;
    }

    /**
     * Layout tables (signatures) would otherwise glue the text of neighbouring cells together.
     */
    private static function separateTableCells(string $html): string
    {
        $html = preg_replace('#</t[dh]\s*>#i', ' $0', $html) ?? $html;

        return preg_replace('#</tr\s*>#i', '<br>$0', $html) ?? $html;
    }

    private static function tidy(string $markdown): string
    {
        $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $markdown = preg_replace('/<(?=[a-z\/!?])/i', '\\\\<', $markdown) ?? $markdown;
        $markdown = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $markdown);
        $markdown = preg_replace('/[ \t]+$/m', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }
}
