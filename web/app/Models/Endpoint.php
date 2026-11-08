<?php

namespace App\Models;

use App\Jobs\SyncSwitchConfig;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Routing -> PBX/Devices (see the create_endpoints and
 * shared_pbx_and_receive_rules migrations):
 *
 *  - PBX: an off-switch (often multi-tenant) PBX, shared: no customer. DIDs of
 *    any customer can be sent to it; its addresses are trusted sources for
 *    devices' domain and caller-number receiving rules.
 *  - Device: belongs to a customer. "Sending calls" (IP/FQDN or a call flow)
 *    and "Receiving calls" (rules that identify its outgoing calls, and an
 *    optional SIP login).
 */
#[Hidden(['sip_password'])]
#[Fillable(['account_id', 'kind', 'name', 'enabled', 'hosts', 'sip_username', 'sip_password', 'transport', 'port', 'delivery', 'flow',
    'forward_number', 'ring_seconds', 'pbx_destination', 'codecs', 'caller_id_number', 'forward_caller_id', 'max_inbound', 'max_outbound',
    'max_total', 'notes'])]
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

    /** Flows that ring the device before forwarding. */
    public const RINGING_FLOWS = ['no_answer', 'busy', 'failed'];

    public const MAX_HOSTS = ['pbx' => 1, 'device' => 3];

    /** How a device's outgoing calls are recognised ("Receiving calls"). */
    public const RULE_TYPES = [
        'domain' => 'FROM domain',
        'ip' => 'IP/FQDN',
        'number' => 'Caller number',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'hosts' => 'array',
            'codecs' => 'array',
            'pbx_destination' => 'array',
            'sip_password' => 'encrypted',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(EndpointRule::class)->orderBy('type')->orderBy('value');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(EndpointAddress::class);
    }

    public function isPbx(): bool
    {
        return $this->kind === 'pbx';
    }

    /** Whether calls are sent out to it (directly, or before a forwarding rule). */
    public function ringsHosts(): bool
    {
        return $this->delivery === 'sip' || in_array($this->flow, self::RINGING_FLOWS, true);
    }

    /** "pbx.example.com:5060 (UDP)", "Forward always to 14165551234"... for lists. */
    public function summary(): string
    {
        $hosts = ($this->hosts ?? []) === []
            ? ($this->sip_username ? "SIP trunk {$this->sip_username}" : 'nowhere')
            : implode(', ', $this->hosts).':'.$this->port.' ('.self::TRANSPORTS[$this->transport].')';
        if ($this->delivery === 'sip') {
            return $hosts;
        }

        return match ($this->flow) {
            'always' => "Forward always to {$this->forward_number}",
            'pbx' => 'PBX on this softswitch',
            default => "{$hosts}; ".strtolower(self::FLOWS[$this->flow]).": forward to {$this->forward_number}",
        };
    }

    /** A strong random SIP password (typed into the PBX once, copied from the page). */
    public static function generatePassword(): string
    {
        return Str::password(20, symbols: false);
    }

    /** Whether a SIP username is free across devices and PBX extensions. */
    public static function usernameTaken(string $username, ?self $except = null): bool
    {
        return self::query()->where('sip_username', $username)->when($except, fn ($q) => $q->whereKeyNot($except->id))->exists()
            || PbxExtension::query()->where('sip_username', $username)->exists();
    }

    protected static function booted(): void
    {
        // Addresses feed caller identification and the firewall; SIP logins the FreeSWITCH directory.
        static::saved(fn () => SyncSwitchConfig::soon());
        static::deleted(fn () => SyncSwitchConfig::soon());
    }
}
