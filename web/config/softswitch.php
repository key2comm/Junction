<?php

return [
    // Public IPv4 of this switch (set by the installer). Used to sanity-check
    // DNS before requesting a Let's Encrypt certificate.
    'public_ip' => env('SOFTSWITCH_PUBLIC_IP'),

    'certificate' => [
        // Root-owned helper installed by installer/steps/80-web.sh, allowed via sudoers.
        'helper' => env('SOFTSWITCH_CERT_HELPER', '/usr/local/sbin/softswitch-cert'),
    ],
];
