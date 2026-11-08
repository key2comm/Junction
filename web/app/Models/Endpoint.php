<?php

namespace App\Models;

use App\Jobs\SyncSwitchConfig;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Routing -> PBX/Devices: where a customer's calls are delivered (see the
 * create_endpoints migration for the fields and call flows).
 */
#[Fillable(['account_id', 'kind', 'name', 'enabled', 'hosts', 'transport', 'port', 'delivery', 'flow', 'forward_number', 'ring_seconds',
    'pbx_destination', 'codecs', 'caller_id_number', 'forward_caller_id', 'outbound_enabled', 'max_inbound', 'max_outbound', 'max_total', 'notes'])]
class Endpoint extends Model
{
    public const KINDS = ['pbx' => 'PBX', 'device' => 'Device'];

    public const TRANSPORTS = ['udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS'];

    /** Codecs offered to it, by FreeSWITCH name (all can be transcoded by this switch). */
    public const CODECS = [
        'PCMU' => 'G.711 µ-law',
        'PCMA' => 'G.711 A-law',
        'G726-32' => 'G.726 (32k)',
        'G729' => 'G.729',
        'G722' => 'G.722 (HD)',
        'OPUS' => 'Opus (HD)',
    ];

    public const FLOWS = [
        'always' => 'Forward always',
        'no_answer' => 'No answer',
        'busy' => 'Busy',
        'failed' => 'Call failed to connect',
        'pbx' => 'PBX on this softswitch',
    ];

    /** Flows that ring the device's hosts before forwarding. */
    public const RINGING_FLOWS = ['no_answer', 'busy', 'failed'];

    public const MAX_HOSTS = ['pbx' => 1, 'device' => 3];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'outbound_enabled' => 'boolean',
            'hosts' => 'array',
            'codecs' => 'array',
            'pbx_destination' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(EndpointAddress::class);
    }

    /** Whether calls are sent to its hosts (directly, or before a forwarding rule). */
    public function ringsHosts(): bool
    {
        return $this->delivery === 'sip' || in_array($this->flow, self::RINGING_FLOWS, true);
    }

    /** "pbx.example.com:5060 (UDP)", "Forward always to 14165551234"... for lists. */
    public function summary(): string
    {
        $hosts = implode(', ', $this->hosts ?? []).':'.$this->port.' ('.self::TRANSPORTS[$this->transport].')';
        if ($this->delivery === 'sip') {
            return $hosts;
        }

        return match ($this->flow) {
            'always' => "Forward always to {$this->forward_number}",
            'pbx' => 'PBX on this softswitch',
            default => "{$hosts}; ".strtolower(self::FLOWS[$this->flow]).": forward to {$this->forward_number}",
        };
    }

    protected static function booted(): void
    {
        // Their addresses feed caller identification and the firewall allowlist.
        static::saved(fn () => SyncSwitchConfig::soon());
        static::deleted(fn () => SyncSwitchConfig::soon());
    }
}
