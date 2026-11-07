<?php

namespace App\Models;

use App\Support\Decimal;
use Database\Factories\RateFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'rate_deck_id', 'prefix', 'destination', 'rate', 'connect_fee',
    'initial_increment', 'increment', 'effective_from', 'effective_to',
])]
class Rate extends Model
{
    /** @use HasFactory<RateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'connect_fee' => 'decimal:6',
            'initial_increment' => 'integer',
            'increment' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function rateDeck(): BelongsTo
    {
        return $this->belongsTo(RateDeck::class);
    }

    public function scopeEffectiveAt(Builder $query, DateTimeInterface $at): void
    {
        $query->where('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }

    /**
     * Seconds billed for a call of $seconds: nothing if unanswered, otherwise
     * the initial increment, then rounded up to whole increments.
     */
    public function billableSeconds(int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }
        if ($seconds <= $this->initial_increment) {
            return $this->initial_increment;
        }
        $rest = $seconds - $this->initial_increment;

        return $this->initial_increment + (int) ceil($rest / $this->increment) * $this->increment;
    }

    /** Cost of a call of $seconds (connect fee only applies to answered calls). */
    public function costFor(int $seconds): string
    {
        $billable = $this->billableSeconds($seconds);
        if ($billable === 0) {
            return '0.000000';
        }
        $usage = bcdiv(bcmul((string) $this->rate, (string) $billable, 12), '60', 12);

        return Decimal::round(bcadd($usage, (string) $this->connect_fee, 12), 6);
    }
}
