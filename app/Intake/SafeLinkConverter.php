<?php

namespace App\Intake;

use League\HTMLToMarkdown\Converter\LinkConverter;
use League\HTMLToMarkdown\ElementInterface;
use League\HTMLToMarkdown\LinkSyntax;

/**
 * Links of a mail in the task description: only http, https and mailto stay links. Any other
 * target (javascript:, data:, vbscript:, relative, anchors, cid:) is reduced to its text.
 * Always written as [text](target), never as <autolink>, so the output has no angle brackets.
 */
class SafeLinkConverter extends LinkConverter
{
    public function convert(ElementInterface $element): string
    {
        $text = trim($element->getValue(), "\t\n\r\0\x0B ");
        $href = self::safeHref($element->getAttribute('href'));

        if ($href === null) {
            return $text;
        }

        return '['.($text === '' ? LinkSyntax::escapeText($href) : $text).']('.LinkSyntax::escapeDestination($href).')';
    }

    /**
     * The target as a browser would read it (whitespace and control characters dropped),
     * null if it is not an absolute http, https or mailto URL.
     */
    public static function safeHref(string $href): ?string
    {
        $normalized = preg_replace('/[\x00-\x20\x7F]+/', '', $href) ?? '';

        return preg_match('#^(?:https?://[^/]|mailto:[^/])#i', $normalized) === 1 ? $normalized : null;
    }
}
