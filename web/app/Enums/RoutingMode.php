<?php

namespace App\Enums;

enum RoutingMode: string
{
    case Lcr = 'lcr';
    case Priority = 'priority';
    case Weight = 'weight';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
