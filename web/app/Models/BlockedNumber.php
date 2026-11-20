<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A number a customer may not call (outbound), or receive calls from on its
 * DIDs (inbound), or both. Checked live by the call path
 * (softswitch_number_blocked), so no switch sync is needed.
 */
#[Fillable(['account_id', 'number', 'direction', 'reason'])]
class BlockedNumber extends Model
{
    protected $table = 'account_blocked_numbers';

    public const DIRECTIONS = [
        'both' => 'Calls to and from it',
        'outbound' => 'Calls to it',
        'inbound' => 'Calls from it',
    ];

    /** Digits only; a 10-digit Canada/USA number gets its 1, as the call path compares it. */
    public static function normalize(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);

        return preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits) ? '1'.$digits : $digits;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
