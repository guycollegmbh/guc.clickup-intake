<?php

namespace App\Intake;

/**
 * Proposal for the ClickUp task name from the mail subject (step 4).
 */
final class TaskName
{
    public const EMPTY_SUBJECT = '(ohne Betreff)';

    /**
     * Reply and forward prefixes in English and German, repeated and mixed in any case:
     * Re, AW, WG, Fwd, FW. Only when followed by a colon, so "Rechnung: ..." stays as it is.
     */
    private const PREFIXES = '/^(?:\s*(?:re|aw|wg|fwd?)\s*:)+/iu';

    public static function fromSubject(?string $subject): string
    {
        $name = preg_replace(self::PREFIXES, '', (string) $subject) ?? (string) $subject;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return $name === '' ? self::EMPTY_SUBJECT : $name;
    }
}
