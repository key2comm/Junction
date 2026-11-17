<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A DID (Routing -> DIDs): a number a provider (carrier) delivers to us, sent
 * to a destination of the customer that owns it (a PBX/Device, or something
 * on the built-in PBX). Read live by the call path (softswitch_did), so no
 * switch sync is needed.
 */
#[Fillable(['account_id', 'provider_account_id', 'number', 'destination', 'language', 'status', 'status_reason', 'description'])]
class PbxDid extends Model
{
    /** "unassigned": in stock, not given to a customer yet (no customer, no destination; calls are refused). */
    public const STATUSES = ['active' => 'Active', 'unassigned' => 'Not assigned', 'deactivated' => 'Deactivated', 'banned' => 'Banned'];

    /** Status as written in a CSV ("Not assigned", "not_assigned"...) → its key, or null. */
    public static function statusFrom(string $value): ?string
    {
        $v = str_replace([' ', '-', '_'], '', strtolower(trim($value)));
        $key = ['notassigned' => 'unassigned', 'unassigned' => 'unassigned'][$v] ?? $v;

        return isset(self::STATUSES[$key]) ? $key : null;
    }

    protected function casts(): array
    {
        return [
            'destination' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'provider_account_id');
    }
}
