<?php

namespace App\Services\Freeswitch;

use App\Models\Endpoint;
use App\Models\PbxExtension;
use App\Models\PbxIvr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Pushes the PBX to FreeSWITCH:
 *
 *  - one directory file per enabled extension, and per device SIP trunk login
 *    (Routing -> PBX/Devices), in directory/pbx/<user>.xml, then
 *    reloadxml. Registrations of removed users, or users whose password changed, are flushed so phones
 *    re-register (and fail if their password changed).
 *  - IVR greetings, copied from the app's storage (which php-fpm writes under
 *    SELinux) into a directory FreeSWITCH reads. Unused greetings are removed.
 *
 * Everything else the PBX needs (extensions' numbers, IVR menus, DIDs) is read
 * live by the call path, so editing it needs no sync.
 */
class PbxSync
{
    public const PROFILE = 'internal';

    public function __construct(private EventSocket $esl, private string $directory, private string $greetings) {}

    /** @return array{written: list<string>, removed: list<string>, unchanged: int, reloaded: bool, greetings: int} */
    public function sync(): array
    {
        return Cache::lock('softswitch:pbx-sync', 120)->block(60, fn () => $this->syncNow());
    }

    private function syncNow(): array
    {
        foreach ([$this->directory, $this->greetings] as $dir) {
            if (! is_dir($dir) || ! is_writable($dir)) {
                throw new RuntimeException("PBX directory {$dir} is missing or not writable.");
            }
        }

        $wanted = [];
        $extensions = PbxExtension::query()->where('enabled', true)
            ->whereHas('account', fn ($q) => $q->where('is_customer', true)->where('status', 'active'))
            ->orderBy('sip_username')->get();
        foreach ($extensions as $extension) {
            $wanted["{$extension->sip_username}.xml"] = $this->render($extension);
        }
        $trunks = Endpoint::query()->where('kind', 'device')->where('enabled', true)->whereNotNull('sip_username')
            ->whereHas('account', fn ($q) => $q->where('is_customer', true)->where('status', 'active'))
            ->orderBy('sip_username')->get();
        foreach ($trunks as $device) {
            $wanted["{$device->sip_username}.xml"] ??= $this->renderTrunk($device);
        }

        $written = $removed = $flush = [];
        $unchanged = 0;
        foreach ($wanted as $file => $xml) {
            $path = "{$this->directory}/{$file}";
            $old = is_file($path) ? (string) file_get_contents($path) : null;
            if ($old !== null && hash_equals(hash('sha256', $old), hash('sha256', $xml))) {
                $unchanged++;

                continue;
            }
            // A new password must take effect now, not at the phone's next re-registration.
            if ($old !== null && self::password($old) !== self::password($xml)) {
                $flush[] = basename($file, '.xml');
            }
            $tmp = "{$path}.tmp";
            File::put($tmp, $xml);
            chmod($tmp, 0640);
            rename($tmp, $path);
            $written[] = basename($file, '.xml');
        }
        foreach (glob("{$this->directory}/*.xml") ?: [] as $path) {
            if (! isset($wanted[basename($path)])) {
                unlink($path);
                $removed[] = $flush[] = basename($path, '.xml');
            }
        }

        $greetings = $this->syncGreetings();

        $reloaded = false;
        if ($written !== [] || $removed !== []) {
            try {
                $this->esl->api('reloadxml');
                $domain = trim($this->esl->api('global_getvar domain'));
                foreach ($flush as $user) {
                    $this->esl->api('sofia profile '.self::PROFILE." flush_inbound_reg {$user}@{$domain}");
                }
                $reloaded = true;
            } catch (Throwable $e) {
                Log::warning('PBX users written but FreeSWITCH could not be reloaded', ['error' => $e->getMessage()]);
            }
        }

        return compact('written', 'removed', 'unchanged', 'reloaded', 'greetings');
    }

    /** Copy referenced greetings into FreeSWITCH's greeting directory; drop the rest. */
    private function syncGreetings(): int
    {
        $disk = Storage::disk('local');
        $wanted = PbxIvr::query()->whereNotNull('greeting')->pluck('greeting')->filter(fn ($f) => self::validName($f))->unique();
        foreach ($wanted as $file) {
            $target = "{$this->greetings}/{$file}";
            $source = PbxIvr::GREETING_PATH."/{$file}";
            if (! is_file($target) && $disk->exists($source)) {
                $tmp = "{$target}.tmp";
                File::put($tmp, $disk->get($source));
                chmod($tmp, 0640);
                rename($tmp, $target);
            }
        }
        foreach (glob("{$this->greetings}/*.wav") ?: [] as $path) {
            if (! $wanted->contains(basename($path))) {
                unlink($path);
            }
        }

        return $wanted->count();
    }

    private static function password(string $xml): ?string
    {
        return preg_match('/<param name="password" value="([^"]*)"/', $xml, $m) ? $m[1] : null;
    }

    /** Greeting file names are generated by the app; never trust anything else. */
    public static function validName(?string $file): bool
    {
        return $file !== null && preg_match('/^ivr-\d+-[a-z0-9]{8,40}\.wav$/', $file) === 1;
    }

    /** A device's SIP trunk login: the outside PBX registers with it and sends calls through it. */
    public function renderTrunk(Endpoint $device): string
    {
        $e = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES);
        $params = ['password' => (string) $device->sip_password, 'vm-enabled' => 'false'];
        $variables = [
            // Calls it sends run dialplan/device.xml (device.lua: out through the customer's LCR).
            'user_context' => 'device',
            // Set only on authenticated channels: the call path trusts it to say which device is calling.
            'softswitch_device_id' => (string) $device->id,
        ];
        $line = fn (string $tag, string $k, string $v) => "        <{$tag} name=\"{$e($k)}\" value=\"{$e($v)}\"/>";

        return "<include>\n  <!-- Generated from device #{$device->id} (account #{$device->account_id}), SIP trunk login. Do not edit. -->\n"
            ."  <user id=\"{$e($device->sip_username)}\">\n"
            ."    <params>\n".implode("\n", array_map(fn ($k, $v) => $line('param', $k, $v), array_keys($params), $params))."\n    </params>\n"
            ."    <variables>\n".implode("\n", array_map(fn ($k, $v) => $line('variable', $k, $v), array_keys($variables), $variables))."\n    </variables>\n"
            ."  </user>\n</include>\n";
    }

    public function render(PbxExtension $extension): string
    {
        $e = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES);
        $params = ['password' => $extension->sip_password];
        $params['vm-enabled'] = $extension->voicemail_enabled ? 'true' : 'false';
        if ($extension->voicemail_enabled && $extension->voicemail_pin) {
            $params['vm-password'] = $extension->voicemail_pin;
        }
        $variables = [
            // Calls from this phone run the PBX dialplan (dialplan/pbx.xml).
            'user_context' => 'pbx',
            // Set only on authenticated channels: the call path trusts it to say who is calling.
            'softswitch_pbx_user' => $extension->sip_username,
            'effective_caller_id_number' => $extension->extension,
            'effective_caller_id_name' => $extension->name ?: $extension->extension,
        ];

        $line = fn (string $tag, string $k, string $v) => "        <{$tag} name=\"{$e($k)}\" value=\"{$e($v)}\"/>";

        return "<include>\n  <!-- Generated from PBX extension #{$extension->id} (account #{$extension->account_id}). Do not edit. -->\n"
            ."  <user id=\"{$e($extension->sip_username)}\">\n"
            ."    <params>\n".implode("\n", array_map(fn ($k, $v) => $line('param', $k, $v), array_keys($params), $params))."\n    </params>\n"
            ."    <variables>\n".implode("\n", array_map(fn ($k, $v) => $line('variable', $k, $v), array_keys($variables), $variables))."\n    </variables>\n"
            ."  </user>\n</include>\n";
    }
}
