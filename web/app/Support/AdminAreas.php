<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Which area of the admin UI each admin route belongs to, for AdminRole.
 * GET requests need read access to the area, anything else needs write access.
 * A route that isn't listed counts as a server setting, so a new page is
 * admin-only until it's added here.
 */
final class AdminAreas
{
    public const ACCOUNTS = 'accounts';

    public const ROUTING = 'routing';

    public const MONITORING = 'monitoring';

    public const BILLING = 'billing';

    public const SETTINGS = 'settings';

    public const USERS = 'users';

    /** Settings → Audit log: owners and admins only. */
    public const AUDIT = 'audit';

    /** Every admin, whatever their level. */
    public const ANY = 'any';

    /** Checked in order: the first matching pattern wins. */
    private const ROUTES = [
        self::ANY => ['dashboard', 'dashboard.*'],
        self::USERS => ['admin-users.*', 'admin-invitations.*'],
        self::AUDIT => ['audit.*'],
        self::MONITORING => ['alerts.*', 'quality.*', 'live.*', 'cdrs.*', 'system-health.*'],
        self::BILLING => [
            'invoices.*', 'accounts.invoices.*', 'accounts.ledger.*', 'billing-items.*', 'billing-profiles.*', 'taxes.*',
            'accounts.billing-profile.*', 'accounts.charges.*', 'accounts.vendor-ledger.*', 'accounts.vendor-invoices.*',
            'vendor-invoices.*', 'billing.edit', 'billing.update',
        ],
        self::ROUTING => [
            'carriers.*', 'trunks.*', 'rate-decks.*', 'tariffs.*', 'carrier-rates.*', 'endpoints.*', 'dids.*', 'did-import.*',
            'accounts.sip-trunks.*', 'routing-plans.*', 'pbx.*', 'accounts.pbx.*', 'accounts.route-test', 'accounts.ips.*',
            'accounts.blocked-numbers.*',
        ],
        self::ACCOUNTS => ['accounts.*'],
        self::SETTINGS => [
            'certificate.*', 'api-tokens.*', 'email.*', 'reports.*', 'captures.*', 'branding.*', 'firewall.*', 'client-defaults.*',
            'ha.*', 'backups.*',
        ],
    ];

    public static function forRoute(?string $name): string
    {
        foreach (self::ROUTES as $area => $patterns) {
            if ($name !== null && Str::is($patterns, $name)) {
                return $area;
            }
        }

        return self::SETTINGS;
    }

    /** Is the route listed above (rather than falling back to server settings)? */
    public static function listed(?string $name): bool
    {
        return $name !== null && collect(self::ROUTES)->contains(fn ($patterns) => Str::is($patterns, $name));
    }

    /** Human name for messages ("can't change routing"). */
    public static function label(string $area): string
    {
        return match ($area) {
            self::ACCOUNTS => 'customer and carrier accounts',
            self::MONITORING => 'calls and alerts',
            self::SETTINGS => 'server settings',
            self::USERS => 'admin users',
            self::AUDIT => 'the audit log',
            default => $area,
        };
    }
}
