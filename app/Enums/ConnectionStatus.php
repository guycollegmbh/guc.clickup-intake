<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Broken = 'broken';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Verbunden',
            self::Disconnected => 'Nicht verbunden',
            self::Broken => 'Verbindung unterbrochen',
        };
    }
}
