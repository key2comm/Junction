<?php

namespace App\Support;

/**
 * Settings → Call captures: every call's SIP signalling is saved as a PCAP
 * (Calls → Call records → PCAP). softswitch-capture.service (tcpdump) writes
 * the raw capture in 5-minute files to the capture directory; PcapExtractor
 * cuts each call out of it about a minute after the call ends. Per-call files
 * are kept retention_days (default 90); the raw files only RAW_HOURS.
 */
final class Captures
{
    public const ENABLED = 'captures.enabled';

    public const RETENTION = 'captures.retention_days';

    public const DEFAULT_RETENTION = 90;

    /** Raw 5-minute capture files are only needed until each call is cut out. */
    public const RAW_HOURS = 24;

    public static function enabled(): bool
    {
        return (bool) Settings::get(self::ENABLED, true);
    }

    public static function retentionDays(): int
    {
        return max(1, (int) Settings::get(self::RETENTION, self::DEFAULT_RETENTION));
    }

    /** Where tcpdump writes (sip-YYYYmmddHHMMSS.pcap, UTC). */
    public static function rawDirectory(): string
    {
        return (string) config('softswitch.capture_dir', '/var/lib/softswitch/capture');
    }
}
