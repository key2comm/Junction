<?php

namespace App\Services\Freeswitch;

use App\Enums\Transport;
use App\Models\Gateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Writes one FreeSWITCH gateway file per enabled vendor gateway into the
 * gateway directory of the profile it sends from: the carrier profile's
 * directory, or its pbx-port/ subdirectory for trunks that send from the PBX
 * port (gateways.source_port = pbx; the internal profile includes those), then makes FreeSWITCH pick up the
 * changes: changed or removed gateways are unloaded (killgw) and the profile
 * is rescanned. Unchanged gateways are left alone, so live calls on them are
 * not disturbed.
 */
class GatewaySync
{
    public const PROFILE = 'carrier';

    /** gateways.source_port => [FreeSWITCH profile, subdirectory of the gateway directory]. */
    public const PROFILES = ['carrier' => ['carrier', ''], 'pbx' => ['internal', 'pbx-port']];

    public function __construct(private EventSocket $esl, private string $directory) {}

    /**
     * Serialised with a lock: two concurrent syncs (queue + scheduler + CLI)
     * would otherwise reload FreeSWITCH gateways under each other.
     *
     * @return array{written: list<string>, removed: list<string>, unchanged: int, reloaded: bool}
     */
    public function sync(): array
    {
        return Cache::lock('softswitch:gateway-sync', 120)->block(60, fn () => $this->syncNow());
    }

    /** @return array{written: list<string>, removed: list<string>, unchanged: int, reloaded: bool} */
    private function syncNow(): array
    {
        if (! is_dir($this->directory) || ! is_writable($this->directory)) {
            throw new RuntimeException("Gateway directory {$this->directory} is missing or not writable.");
        }

        $wanted = []; // path => xml
        $gateways = Gateway::query()->where('enabled', true)
            ->whereHas('trunk', fn ($q) => $q->where('enabled', true))
            ->orderBy('name')->get();
        foreach ($gateways as $gateway) {
            $wanted["{$this->dir($gateway->source_port ?? 'carrier')}/{$gateway->name}.xml"] = $this->render($gateway);
        }

        $written = $removed = [];
        $touched = []; // profile => gateway names to unload
        $unchanged = 0;
        foreach ($wanted as $path => $xml) {
            if (is_file($path) && hash_equals(hash_file('sha256', $path), hash('sha256', $xml))) {
                $unchanged++;

                continue;
            }
            if (! is_dir(dirname($path))) {
                throw new RuntimeException('Gateway directory '.dirname($path).' is missing (re-run the installer).');
            }
            // Atomic replace so FreeSWITCH never reads a half-written file.
            $tmp = "{$path}.tmp";
            File::put($tmp, $xml);
            chmod($tmp, 0640);
            rename($tmp, $path);
            $written[] = basename($path, '.xml');
            $touched[$this->profileOf($path)][] = basename($path, '.xml');
        }
        foreach (self::PROFILES as $port => [$profile]) {
            foreach (glob($this->dir($port).'/*.xml') ?: [] as $path) {
                if (! isset($wanted[$path])) {
                    unlink($path);
                    $removed[] = basename($path, '.xml');
                    $touched[$profile][] = basename($path, '.xml');
                }
            }
        }

        $reloaded = false;
        if ($touched !== []) {
            try {
                foreach ($touched as $profile => $names) {
                    foreach ($names as $name) {
                        $this->esl->api("sofia profile {$profile} killgw {$name}");
                    }
                }
                // Includes are only re-read by reloadxml; rescan then loads new gateways.
                $this->esl->api('reloadxml');
                foreach (array_keys($touched) as $profile) {
                    $this->esl->api("sofia profile {$profile} rescan");
                }
                $reloaded = true;
            } catch (Throwable $e) {
                // Files are in place; FreeSWITCH loads them on its next start.
                Log::warning('Gateways written but FreeSWITCH could not be reloaded', ['error' => $e->getMessage()]);
            }
        }

        return compact('written', 'removed', 'unchanged', 'reloaded');
    }

    private function dir(string $port): string
    {
        $sub = self::PROFILES[$port][1] ?? '';

        return $sub === '' ? $this->directory : "{$this->directory}/{$sub}";
    }

    private function profileOf(string $path): string
    {
        foreach (self::PROFILES as $port => [$profile]) {
            if (dirname($path) === $this->dir($port)) {
                return $profile;
            }
        }

        return self::PROFILE;
    }

    public function render(Gateway $gateway): string
    {
        $e = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES);
        $endpoint = "{$gateway->host}:{$gateway->port}";
        $transport = $gateway->transport ?? Transport::Udp;
        $proxy = $transport === Transport::Udp ? $endpoint : "{$endpoint};transport={$transport->value}";

        $params = [
            'realm' => $endpoint,
            'proxy' => $proxy,
            'register' => $gateway->register ? 'true' : 'false',
            'register-transport' => $transport->value,
            'username' => $gateway->username ?: 'softswitch',
            'password' => $gateway->password ?: 'unused',
            // Send the customer's caller ID in From (wholesale carriers expect it).
            'caller-id-in-from' => 'true',
            'retry-seconds' => '30',
        ];
        if ($gateway->register) {
            $params['expire-seconds'] = '600';
        }
        // Advanced: a fixed From user (the caller ID then travels in RPID/PAI), From domain, outbound proxy.
        if ($gateway->from_user) {
            $params['from-user'] = $gateway->from_user;
            $params['caller-id-in-from'] = 'false';
        }
        if ($gateway->from_domain) {
            $params['from-domain'] = $gateway->from_domain;
        }
        if ($gateway->outbound_proxy) {
            $params['outbound-proxy'] = $gateway->outbound_proxy;
        }

        $lines = array_map(fn ($k, $v) => "    <param name=\"{$e($k)}\" value=\"{$e($v)}\"/>", array_keys($params), $params);

        return "<include>\n  <!-- Generated from gateway #{$gateway->id} (trunk #{$gateway->trunk_id}). Do not edit. -->\n"
            ."  <gateway name=\"{$e($gateway->name)}\">\n".implode("\n", $lines)."\n  </gateway>\n</include>\n";
    }
}
