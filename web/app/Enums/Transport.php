<?php

namespace App\Enums;

enum Transport: string
{
    case Udp = 'udp';
    case Tcp = 'tcp';
    case Tls = 'tls';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
