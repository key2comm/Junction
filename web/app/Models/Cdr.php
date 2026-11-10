<?php

namespace App\Models;

use Database\Factories\CdrFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer call. Vendor attempts (failover) are in cdr_attempts; the
 * buy-side columns describe the attempt that carried the call.
 */
#[Fillable(['uuid', 'sip_call_id', 'pcap_path', 'direction', 'did', 'customer_account_id', 'endpoint_id', 'source_ip', 'caller', 'callee', 'destination', 'started_at', 'answered_at', 'ended_at', 'billsec', 'pdd_ms', 'sip_code', 'hangup_cause', 'attempts', 'sell_prefix', 'sell_destination', 'sell_rate', 'sell_cost', 'vendor_account_id', 'trunk_id', 'gateway_id', 'buy_prefix', 'buy_rate', 'buy_cost', 'rated_at', 'posted_at', 'vendor_posted_at'])]
class Cdr extends Model
{
    /** carrier traffic, or a PBX call: to an outside number, from a DID, or between extensions. */
    public const DIRECTIONS = ['carrier', 'pbx_out', 'pbx_in', 'pbx_local'];

    /** Incoming (a DID call, also when forwarded out again), outgoing or internal. */
    public function flow(): string
    {
        return match (true) {
            $this->did !== null => $this->direction === 'pbx_in' ? 'incoming' : 'incoming_forwarded',
            $this->direction === 'pbx_local' => 'internal',
            default => 'outgoing',
        };
    }

    /** @use HasFactory<CdrFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'rated_at' => 'datetime',
            'posted_at' => 'datetime',
            'vendor_posted_at' => 'datetime',
            'billsec' => 'integer',
            'pdd_ms' => 'integer',
            'sip_code' => 'integer',
            'attempts' => 'integer',
            'sell_rate' => 'decimal:6',
            'sell_cost' => 'decimal:6',
            'buy_rate' => 'decimal:6',
            'buy_cost' => 'decimal:6',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'customer_account_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'vendor_account_id');
    }

    public function trunk(): BelongsTo
    {
        return $this->belongsTo(Trunk::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function attemptLog(): HasMany
    {
        return $this->hasMany(CdrAttempt::class);
    }

    /** Gross margin of the call (sell - buy), once rated. */
    public function margin(): ?string
    {
        if ($this->sell_cost === null || $this->buy_cost === null) {
            return null;
        }

        return bcsub((string) $this->sell_cost, (string) $this->buy_cost, 6);
    }
}
