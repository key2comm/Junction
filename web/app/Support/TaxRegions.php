<?php

namespace App\Support;

/**
 * Where a customer is taxed (Billing → Taxes): a Canadian province or
 * territory, or a US state (or DC), as "CA-ON" / "US-NY".
 */
final class TaxRegions
{
    public const CANADA = [
        'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador', 'NS' => 'Nova Scotia', 'NT' => 'Northwest Territories', 'NU' => 'Nunavut',
        'ON' => 'Ontario', 'PE' => 'Prince Edward Island', 'QC' => 'Quebec', 'SK' => 'Saskatchewan', 'YT' => 'Yukon',
    ];

    public const USA = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
        'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia',
        'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts',
        'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana',
        'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
        'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
        'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    /**
     * Canadian sales taxes on telecom services when this was written
     * (2026; Nova Scotia's HST is 14% since April 2025). Pre-filled once;
     * kept up to date by hand in Billing → Taxes.
     */
    public const CANADA_TAXES = [
        'AB' => [['GST', '5']],
        'BC' => [['GST', '5'], ['PST', '7']],
        'MB' => [['GST', '5'], ['RST', '7']],
        'NB' => [['HST', '15']],
        'NL' => [['HST', '15']],
        'NS' => [['HST', '14']],
        'NT' => [['GST', '5']],
        'NU' => [['GST', '5']],
        'ON' => [['HST', '13']],
        'PE' => [['HST', '15']],
        'QC' => [['GST', '5'], ['QST', '9.975']],
        'SK' => [['GST', '5'], ['PST', '6']],
        'YT' => [['GST', '5']],
    ];

    /** @return array<string, string> "CA-ON" => "Ontario" */
    public static function all(): array
    {
        $out = [];
        foreach (self::CANADA as $code => $name) {
            $out["CA-{$code}"] = $name;
        }
        foreach (self::USA as $code => $name) {
            $out["US-{$code}"] = $name;
        }

        return $out;
    }

    /** @return list<array{country: string, regions: list<array{code: string, name: string}>}> for grouped selects */
    public static function options(): array
    {
        $group = fn (string $prefix, array $list) => array_map(fn ($c, $n) => ['code' => "{$prefix}-{$c}", 'name' => $n], array_keys($list), $list);

        return [
            ['country' => 'Canada', 'regions' => $group('CA', self::CANADA)],
            ['country' => 'United States', 'regions' => $group('US', self::USA)],
        ];
    }

    public static function name(?string $code): ?string
    {
        return $code ? (self::all()[$code] ?? $code) : null;
    }
}
