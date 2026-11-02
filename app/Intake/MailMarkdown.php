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
 * would be loaded wherever the Markdown is shown (tracking). As a last line, every "<" that
 * could start a tag is written as "&lt;", also inside code blocks.
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
     * @param  string|null  $fallbackText  used if the HTML is empty or cannot be converted
     */
    public function convert(?string $html, ?string $fallbackText = null): string
    {
        $markdown = null;

        if (trim((string) $html) !== '') {
            try {
                $markdown = $this->converter->convert(self::separateTableCells((string) $html));
            } catch (Throwable $e) {
                Log::warning('Mail HTML could not be converted to Markdown.', ['exception' => $e::class]);
            }
        }

        return self::tidy($markdown ?? (string) $fallbackText);
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
        $markdown = preg_replace('/<(?=[a-z\/!?])/i', '&lt;', $markdown) ?? $markdown;
        $markdown = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $markdown);
        $markdown = preg_replace('/[ \t]+$/m', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }
}
