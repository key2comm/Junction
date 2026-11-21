<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Settings → Backups: one database backup, kept on the private disk (backups/). */
class Backup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'encrypted' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'emailed_at' => 'datetime',
            'sftp_at' => 'datetime',
            's3_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The file goes with the row.
        static::deleting(function (Backup $b) {
            if ($b->path) {
                Storage::disk('local')->delete($b->path);
            }
        });
    }

    public function fileExists(): bool
    {
        return $this->path !== null && Storage::disk('local')->exists($this->path);
    }
}
