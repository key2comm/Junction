<?php

namespace App\Models;

use App\Enums\RateDeckKind;
use App\Support\Prefixes;
use Database\Factories\RateDeckFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-prefix price list of one kind: a tariff (what customers pay, attached
 * to customer accounts) or carrier rates (what a carrier charges us, attached
 * to carrier trunks).
 */
#[Fillable(['name', 'kind', 'currency', 'description'])]
class RateDeck extends Model
{
    /** @use HasFactory<RateDeckFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['kind' => RateDeckKind::class];
    }

    public function inUse(): bool
    {
        return Account::query()->where('sell_rate_deck_id', $this->id)->exists()
            || Trunk::query()->where('rate_deck_id', $this->id)->exists();
    }

    public function rates(): HasMany
    {
        return $this->hasMany(Rate::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(RateImport::class);
    }

    /**
     * The rate that applies to a number at a moment in time: the longest
     * matching prefix among rates effective at $at.
     */
    public function rateFor(string $number, ?DateTimeInterface $at = null): ?Rate
    {
        $prefixes = Prefixes::of($number);
        if ($prefixes === []) {
            return null;
        }
        $at ??= now();

        return $this->rates()
            ->whereIn('prefix', $prefixes)
            ->effectiveAt($at)
            ->orderByRaw('length(prefix) desc')
            ->orderByDesc('effective_from')
            ->first();
    }
}
