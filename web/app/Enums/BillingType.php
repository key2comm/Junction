<?php

namespace App\Enums;

enum BillingType: string
{
    case Prepaid = 'prepaid';
    case Postpaid = 'postpaid';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
