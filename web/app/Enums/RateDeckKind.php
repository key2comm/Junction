<?php

namespace App\Enums;

/** Tariff: what a customer pays (on customer accounts). Carrier: what a carrier charges us (on carrier trunks). */
enum RateDeckKind: string
{
    case Tariff = 'tariff';
    case Carrier = 'carrier';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
