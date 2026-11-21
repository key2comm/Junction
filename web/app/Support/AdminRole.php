<?php

namespace App\Support;

/**
 * Admin access levels (Settings → Admin users). Each level sees or changes
 * whole areas of the admin UI; see AdminAreas for which pages are in which area.
 */
enum AdminRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operations = 'operations';
    case Billing = 'billing';
    case ReadOnly = 'readonly';

    public const WRITE = 'write';

    public const READ = 'read';

    /** Area => [owner, admin, operations, billing, readonly]; null = no access. */
    private const MATRIX = [
        AdminAreas::ACCOUNTS => [self::WRITE, self::WRITE, self::WRITE, self::WRITE, self::READ],
        AdminAreas::ROUTING => [self::WRITE, self::WRITE, self::WRITE, self::READ, self::READ],
        AdminAreas::MONITORING => [self::WRITE, self::WRITE, self::WRITE, self::READ, self::READ],
        AdminAreas::BILLING => [self::WRITE, self::WRITE, null, self::WRITE, self::READ],
        AdminAreas::SETTINGS => [self::WRITE, self::WRITE, null, null, null],
        AdminAreas::USERS => [self::WRITE, null, null, null, null],
        // Audit log: owners also set how long it's kept; nobody can edit entries.
        AdminAreas::AUDIT => [self::WRITE, self::READ, null, null, null],
    ];

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Operations => 'Operations (NOC)',
            self::Billing => 'Billing',
            self::ReadOnly => 'Read-only',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Everything, including inviting and removing admins.',
            self::Admin => 'Everything except managing admins.',
            self::Operations => 'Customers, carriers, rates, LCR groups, PBX, DIDs, live calls, call records and alerts. No billing or server settings.',
            self::Billing => 'Customers, invoices, payments, billing items and profiles, taxes and billing settings. Sees routing and calls but can\'t change them.',
            self::ReadOnly => 'Sees customers, routing, calls and billing, but can\'t change anything. No server settings.',
        };
    }

    /** "write", "read" or null (no access) for one area. */
    public function access(string $area): ?string
    {
        $row = self::MATRIX[$area] ?? null;

        return $row === null ? null : $row[array_search($this, self::cases(), true)];
    }

    /** @return array<string, string|null> every area's access, for the frontend */
    public function accessMap(): array
    {
        return array_combine(array_keys(self::MATRIX), array_map(fn ($a) => $this->access($a), array_keys(self::MATRIX)));
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r) => ['value' => $r->value, 'label' => $r->label(), 'description' => $r->description()], self::cases());
    }
}
