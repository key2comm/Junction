<?php

namespace App\Services\Freeswitch;

use App\Enums\Transport;
use App\Models\Gateway;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Writes one FreeSWITCH gateway file per enabled vendor gateway into the
 * carrier profile's gateway directory, then makes FreeSWITCH pick up the
 * changes: changed or removed gateways are unloaded (killgw) and the profile
 * is rescanned. Unchanged gateways are left alone, so live calls on them are
 * not disturbed.
 */
class GatewaySync
{
    public const PROFILE = 'carrier';

    public function __construct(private EventSocket $esl, private string $directory) {}

    /** @return array{written: list<string>, removed: list<string>, unchanged: int, reloaded: bool} */
    public function sync(): array
    {
        if (! is_dir($this->directory) || ! is_writable($this->directory)) {
            throw new RuntimeException("Gateway directory {$this->directory} is missing or not writable.");
        }

        $wanted = [];
        $gateways = Gateway::query()->where('enabled', true)
            ->whereHas('trunk', fn ($q) => $q->where('enabled', true))
            ->orderBy('name')->get();
        foreach ($gateways as $gateway) {
            $wanted["{$gateway->name}.xml"] = $this->render($gateway);
        }

        $written = $removed = [];
        $unchanged = 0;
        foreach ($wanted as $file => $xml) {
            $path = "{$this->directory}/{$file}";
            if (is_file($path) && hash_equals(hash_file('sha256', $path), hash('sha256', $xml))) {
                $unchanged++;

                continue;
            }
            // Atomic replace so FreeSWITCH never reads a half-written file.
            $tmp = "{$path}.tmp";
            File::put($tmp, $xml);
            chmod($tmp, 0640);
            rename($tmp, $path);
            $written[] = basename($file, '.xml');
        }
        foreach (glob("{$this->directory}/*.xml") ?: [] as $path) {
            if (! isset($wanted[basename($path)])) {
                unlink($path);
                $removed[] = basename($path, '.xml');
            }
        }

        $reloaded = false;
        if ($written !== [] || $removed !== []) {
            try {
                foreach ([...$written, ...$removed] as $name) {
                    $this->esl->api('sofia profile '.self::PROFILE." killgw {$name}");
                }
                $this->esl->api('sofia profile '.self::PROFILE.' rescan');
                $reloaded = true;
            } catch (Throwable $e) {
                // Files are in place; FreeSWITCH loads them on its next start.
                Log::warning('Gateways written but FreeSWITCH could not be reloaded', ['error' => $e->getMessage()]);
            }
        }

        return compact('written', 'removed', 'unchanged', 'reloaded');
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

        $lines = array_map(fn ($k, $v) => "    <param name=\"{$e($k)}\" value=\"{$e($v)}\"/>", array_keys($params), $params);

        return "<include>\n  <!-- Generated from gateway #{$gateway->id} (trunk #{$gateway->trunk_id}). Do not edit. -->\n"
            ."  <gateway name=\"{$e($gateway->name)}\">\n".implode("\n", $lines)."\n  </gateway>\n</include>\n";
    }
}
