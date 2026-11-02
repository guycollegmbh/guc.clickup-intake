<?php

namespace App\Enums;

enum RuleMatchType: string
{
    case FromDomain = 'from_domain';
    case FromEmail = 'from_email';
    case SubjectContains = 'subject_contains';

    public function label(): string
    {
        return match ($this) {
            self::FromDomain => 'Absender-Domain',
            self::FromEmail => 'Absender-Adresse',
            self::SubjectContains => 'Betreff enthält',
        };
    }
}
