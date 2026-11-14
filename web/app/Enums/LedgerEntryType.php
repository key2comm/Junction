<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case Payment = 'payment';
    case Usage = 'usage';
    case Adjustment = 'adjustment';
    /** Billing profile items (monthly, prorated, one-time; credits are positive). */
    case Charge = 'charge';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
