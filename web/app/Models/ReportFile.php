<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A report a schedule made: a CSV on the private local disk. */
#[Fillable(['report_schedule_id', 'period_start', 'period_end', 'path', 'filename', 'size', 'rows', 'emailed_at', 'uploaded_at', 'error'])]
class ReportFile extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'size' => 'integer',
            'rows' => 'integer',
            'emailed_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }

    protected static function booted(): void
    {
        static::deleted(fn (ReportFile $f) => Storage::disk('local')->delete($f->path));
    }
}
