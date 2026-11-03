<?php

namespace App\Enums;

/**
 * Graph attachment kinds: fileAttachment (stored as is), itemAttachment (an attached mail,
 * stored as .eml) and referenceAttachment (a cloud link, never downloaded).
 */
enum AttachmentType: string
{
    case File = 'file';
    case Item = 'item';
    case Reference = 'reference';

    public function label(): string
    {
        return match ($this) {
            self::File => 'Datei',
            self::Item => 'Mail',
            self::Reference => 'Link',
        };
    }
}
