<?php

namespace App\Models;

use App\Jobs\SyncSwitchConfig;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An auto-attendant: plays a greeting, then sends one key press to a
 * destination (see the pbx migration for the destination format). Greetings
 * are uploaded to the app's storage and copied for FreeSWITCH by PbxSync.
 */
#[Fillable(['account_id', 'extension', 'name', 'greeting', 'timeout_seconds', 'options', 'default_destination'])]
class PbxIvr extends Model
{
    /** Where uploaded greetings live on the app's local disk. */
    public const GREETING_PATH = 'pbx/greetings';

    protected $attributes = ['options' => '{}'];

    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
            'options' => 'array',
            'default_destination' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    protected static function booted(): void
    {
        // Greeting files are copied to FreeSWITCH by the switch sync.
        static::saved(function (PbxIvr $ivr) {
            if ($ivr->wasChanged('greeting') || $ivr->wasRecentlyCreated) {
                SyncSwitchConfig::soon();
            }
        });
        static::deleted(fn () => SyncSwitchConfig::soon());
    }
}
