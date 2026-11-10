<?php

namespace App\Services;

use App\Models\Cdr;
use App\Support\Captures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Cuts one call's SIP messages out of the raw capture into its own PCAP
 * (Settings → Call captures). A packet belongs to the call when it carries
 * the caller leg's Call-ID or a Call-ID the switch gave one of the call's own
 * legs ("<call uuid>-..."). IPv4 fragments of matching packets come along, so
 * large INVITEs stay complete in Wireshark.
 *
 * Plain PHP over the classic pcap format: no capture tools are needed beyond
 * tcpdump writing the raw files.
 */
class PcapExtractor
{
    /** Raw files are rotated every 5 minutes (softswitch-capture.service -G 300). */
    public const ROTATE_SECONDS = 300;

    public function __construct(private ?string $rawDirectory = null) {}

    /** @return string|null the per-call file's path on the local disk, or null when nothing was captured */
    public function extract(Cdr $cdr): ?string
    {
        if ($cdr->sip_call_id === null) {
            return null;
        }
        $needles = [$cdr->sip_call_id, $cdr->uuid.'-'];
        $from = CarbonImmutable::parse($cdr->started_at)->subSeconds(2)->getTimestamp();
        // BYE and its retransmissions (up to 32 s) after the call ends.
        $to = CarbonImmutable::parse($cdr->ended_at ?? $cdr->started_at)->addSeconds(40)->getTimestamp();

        $header = null;
        $matched = [];    // [ts, record bytes]
        $fragKeys = [];   // IPv4 fragments of matched packets
        $fragments = [];  // fragment key => list of [ts, record bytes]
        foreach ($this->files($from, $to) as $file) {
            $this->scan($file, $from, $to, $needles, $header, $matched, $fragKeys, $fragments);
        }
        foreach (array_keys($fragKeys) as $key) {
            foreach ($fragments[$key] ?? [] as $record) {
                $matched[] = $record;
            }
        }
        if ($matched === [] || $header === null) {
            return null;
        }
        // In time order, each packet once (a matching fragment can be in both lists).
        $unique = [];
        foreach ($matched as [$ts, $bytes]) {
            $unique[md5($bytes)] = [$ts, $bytes];
        }
        uasort($unique, fn ($a, $b) => $a[0] <=> $b[0]);

        $path = 'pcaps/'.CarbonImmutable::parse($cdr->started_at)->utc()->format('Ymd')."/{$cdr->uuid}.pcap";
        Storage::disk('local')->put($path, $header.implode('', array_column($unique, 1)));
        DB::table('cdrs')->where('id', $cdr->id)->update(['pcap_path' => $path]);

        return $path;
    }

    /** Raw files that can hold packets between $from and $to. */
    private function files(int $from, int $to): array
    {
        $dir = $this->rawDirectory ?? Captures::rawDirectory();
        $files = [];
        foreach (glob("{$dir}/sip-*.pcap") ?: [] as $file) {
            if (! preg_match('/sip-(\d{14})\.pcap$/', $file, $m)) {
                continue;
            }
            $start = CarbonImmutable::createFromFormat('YmdHis', $m[1], 'UTC')->getTimestamp();
            // A file runs from its start to the next file's start (about 5 minutes).
            if ($start <= $to && $start + self::ROTATE_SECONDS * 2 >= $from) {
                $files[$start] = $file;
            }
        }
        ksort($files);

        return array_values($files);
    }

    private function scan(string $file, int $from, int $to, array $needles, ?string &$header, array &$matched, array &$fragKeys, array &$fragments): void
    {
        $h = @fopen($file, 'rb');
        if ($h === false) {
            return;
        }
        try {
            $global = fread($h, 24);
            if (strlen($global) < 24) {
                return;
            }
            $magic = bin2hex(substr($global, 0, 4));
            $le = in_array($magic, ['d4c3b2a1', '4d3cb2a1'], true);
            if (! $le && ! in_array($magic, ['a1b2c3d4', 'a1b23c4d'], true)) {
                return; // not classic pcap
            }
            $u32 = $le ? 'V' : 'N';
            $link = unpack($u32, substr($global, 20, 4))[1] & 0x0FFFFFFF;
            $ipOffset = match ($link) {
                276 => 20,  // LINUX_SLL2 (tcpdump -i any)
                113 => 16,  // LINUX_SLL
                1 => 14,    // Ethernet
                0 => 4,     // BSD loopback
                101, 228 => 0, // raw IPv4
                default => null,
            };
            $header ??= $global;

            while (($rh = fread($h, 16)) !== false && strlen($rh) === 16) {
                ['ts' => $ts, 'incl' => $incl] = unpack("{$u32}ts/{$u32}us/{$u32}incl/{$u32}orig", $rh);
                if ($incl > 262144) {
                    return; // corrupt
                }
                $data = $incl > 0 ? fread($h, $incl) : '';
                if (strlen($data) !== $incl) {
                    return; // the file is still being written: stop at its end
                }
                if ($ts < $from || $ts > $to) {
                    continue;
                }
                $key = $ipOffset !== null ? $this->fragmentKey($data, $ipOffset) : null;
                $hit = false;
                foreach ($needles as $needle) {
                    if (str_contains($data, $needle)) {
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    $matched[] = [$ts + 0, $rh.$data];
                    if ($key !== null) {
                        $fragKeys[$key] = true;
                    }
                } elseif ($key !== null) {
                    $fragments[$key][] = [$ts + 0, $rh.$data];
                }
            }
        } finally {
            fclose($h);
        }
    }

    /** "src|dst|id|proto" for an IPv4 fragment, else null. */
    private function fragmentKey(string $data, int $offset): ?string
    {
        if (strlen($data) < $offset + 20 || (ord($data[$offset]) >> 4) !== 4) {
            return null;
        }
        $flags = unpack('n', substr($data, $offset + 6, 2))[1];
        if (($flags & 0x2000) === 0 && ($flags & 0x1FFF) === 0) {
            return null; // not a fragment
        }

        return bin2hex(substr($data, $offset + 12, 8)).'|'.bin2hex(substr($data, $offset + 4, 2)).'|'.ord($data[$offset + 9]);
    }

    /** Delete per-call files past the retention, and raw files no longer needed. */
    public function prune(): array
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDays(Captures::retentionDays())->utc()->format('Ymd');
        $days = 0;
        foreach ($disk->directories('pcaps') as $dir) {
            if (preg_match('/(\d{8})$/', $dir, $m) && $m[1] < $cutoff) {
                $disk->deleteDirectory($dir);
                $days++;
            }
        }
        $cleared = DB::table('cdrs')->whereNotNull('pcap_path')->where('started_at', '<', CarbonImmutable::createFromFormat('Ymd', $cutoff, 'UTC')->startOfDay())
            ->update(['pcap_path' => null]);

        $raw = 0;
        $before = now()->subHours(Captures::RAW_HOURS)->getTimestamp();
        foreach (glob(($this->rawDirectory ?? Captures::rawDirectory()).'/sip-*.pcap') ?: [] as $file) {
            if (@filemtime($file) < $before && @unlink($file)) {
                $raw++;
            }
        }

        return ['days' => $days, 'calls' => $cleared, 'raw' => $raw];
    }
}
