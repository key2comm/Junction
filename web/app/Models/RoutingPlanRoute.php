<?php

namespace App\Models;

use Database\Factories\RoutingPlanRouteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['routing_plan_id', 'prefix', 'trunk_id', 'priority', 'weight', 'enabled'])]
class RoutingPlanRoute extends Model
{
    /** @use HasFactory<RoutingPlanRouteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'weight' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function routingPlan(): BelongsTo
    {
        return $this->belongsTo(RoutingPlan::class);
    }

    public function trunk(): BelongsTo
    {
        return $this->belongsTo(Trunk::class);
    }
}
