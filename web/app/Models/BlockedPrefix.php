<?php

namespace App\Models;

use Database\Factories\BlockedPrefixFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A destination prefix that may not be dialled: globally (account_id null)
 * or for one customer.
 */
#[Fillable(['account_id', 'prefix', 'reason'])]
class BlockedPrefix extends Model
{
    /** @use HasFactory<BlockedPrefixFactory> */
    use HasFactory;


    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
