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

    /**
     * Done with triage: created, forwarded or discarded. Only these are deleted by retention.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Created, self::Forwarded, self::Discarded], true);
    }

    /**
     * Open for triage actions (create task, forward, discard).
     */
    public function isProcessable(): bool
    {
        return in_array($this, [self::Pending, self::Failed], true);
    }

    /**
     * @return list<self>
     */
    public static function finalCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isFinal()));
    }

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
