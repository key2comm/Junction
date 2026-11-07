<?php

return [
    // Public IPv4 of this switch (set by the installer). Used to sanity-check
    // DNS before requesting a Let's Encrypt certificate.
    'public_ip' => env('SOFTSWITCH_PUBLIC_IP'),

    'carrier_port' => (int) env('SOFTSWITCH_CARRIER_PORT', 5080),

    // Built-in self-test: fake vendor/customer on this FreeSWITCH profile port.
    'selftest_port' => (int) env('SOFTSWITCH_SELFTEST_PORT', 5090),

    // Longest call allowed, in seconds (the funds reservation covers this much).
    'max_call_duration' => (int) env('SOFTSWITCH_MAX_CALL_DURATION', 14400),

    // Credentials FreeSWITCH uses for loopback callbacks (/internal/*).
    'internal' => [
        'user' => env('SOFTSWITCH_INTERNAL_USER', 'freeswitch'),
        'password' => env('SOFTSWITCH_INTERNAL_PASSWORD'),
    ],

    'esl' => [
        'host' => env('SOFTSWITCH_ESL_HOST', '127.0.0.1'),
        'port' => (int) env('SOFTSWITCH_ESL_PORT', 8021),
        'password' => env('SOFTSWITCH_ESL_PASSWORD'),
    ],

    'freeswitch' => [
        // Carrier-profile gateway files written by App\Services\Freeswitch\GatewaySync.
        'gateway_dir' => env('SOFTSWITCH_FS_GATEWAY_DIR', '/usr/local/freeswitch/etc/freeswitch/sip_profiles/carrier'),
    ],

    // Extra carrier IPs from the installer config, always allowed through the firewall.
    'static_carrier_ips' => env('SOFTSWITCH_STATIC_CARRIER_IPS', ''),

    'firewall' => [
        'helper' => env('SOFTSWITCH_FIREWALL_HELPER', '/usr/local/sbin/softswitch-firewall'),
    ],

    'certificate' => [
        // Root-owned helper installed by installer/steps/80-web.sh, allowed via sudoers.
        'helper' => env('SOFTSWITCH_CERT_HELPER', '/usr/local/sbin/softswitch-cert'),
    ],
];
