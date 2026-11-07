<?php

namespace App\Models;

use App\Enums\RoutingMode;
use Database\Factories\RoutingPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Which trunks may carry which destination prefixes, and how to order them
 * (cheapest first, fixed priority, or weighted load balancing).
 */
#[Fillable(['name', 'mode', 'max_attempts', 'description', 'quality_routing', 'quality_min_asr', 'quality_max_pdd_ms', 'quality_min_attempts'])]
class RoutingPlan extends Model
{
    /** @use HasFactory<RoutingPlanFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'mode' => RoutingMode::class,
            'max_attempts' => 'integer',
            'quality_routing' => 'boolean',
            'quality_min_asr' => 'decimal:2',
            'quality_max_pdd_ms' => 'integer',
            'quality_min_attempts' => 'integer',
        ];
    }

    public function routes(): HasMany
    {
        return $this->hasMany(RoutingPlanRoute::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
