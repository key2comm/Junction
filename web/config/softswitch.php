<?php

return [
    // Public IPv4 of this switch (set by the installer). Used to sanity-check
    // DNS before requesting a Let's Encrypt certificate.
    'public_ip' => env('SOFTSWITCH_PUBLIC_IP'),

    'carrier_port' => (int) env('SOFTSWITCH_CARRIER_PORT', 5080),

    // Public FQDN of this switch (phones and the web phone connect to it).
    'hostname' => env('SOFTSWITCH_HOSTNAME'),

    'pbx_port' => (int) env('SOFTSWITCH_PBX_PORT', 5060),

    // Built-in self-test: fake vendor/customer on this FreeSWITCH profile port.
    'selftest_port' => (int) env('SOFTSWITCH_SELFTEST_PORT', 5090),

    // Postpaid invoices are due this many days after issue.
    'invoice_due_days' => (int) env('SOFTSWITCH_INVOICE_DUE_DAYS', 15),

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
        // PBX SIP users and IVR greetings written by App\Services\Freeswitch\PbxSync.
        'directory_dir' => env('SOFTSWITCH_FS_DIRECTORY_DIR', '/usr/local/freeswitch/etc/freeswitch/directory/pbx'),
        // The "carriers" ACL (carriers sending to the PBX port), written by App\Services\Freeswitch\CarrierAcl.
        'acl_dir' => env('SOFTSWITCH_FS_ACL_DIR', '/usr/local/freeswitch/etc/freeswitch/acl'),
        'greeting_dir' => env('SOFTSWITCH_FS_GREETING_DIR', '/usr/local/freeswitch/var/lib/freeswitch/greetings'),
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
