<?php

namespace App\Enums;

enum IntakeStatus: string
{
    case Pending = 'pending';
    case Creating = 'creating';
    case Created = 'created';
    case Forwarded = 'forwarded';
    case Discarded = 'discarded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Offen',
            self::Creating => 'Wird erstellt',
            self::Created => 'Erstellt',
            self::Forwarded => 'Weitergeleitet',
            self::Discarded => 'Verworfen',
            self::Failed => 'Fehlgeschlagen',
        };
    }
}
