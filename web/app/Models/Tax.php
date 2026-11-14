<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Billing → Taxes: one tax for a region, e.g. CA-QC QST 9.975%. */
#[Fillable(['region', 'name', 'rate', 'active', 'position'])]
class Tax extends Model
{
    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'active' => 'boolean'];
    }
}
