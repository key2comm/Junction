<?php

namespace App\Support;

/** Active ISO 4217 currencies, for the account currency dropdown and its validation. */
final class Currencies
{
    /** New accounts default to this. */
    public const DEFAULT = 'CAD';

    /** Listed first in the dropdown (the user's choice), above all the others. */
    public const TOP = ['USD', 'CAD'];

    public const ALL = [
        'AED' => 'UAE Dirham', 'AFN' => 'Afghani', 'ALL' => 'Lek', 'AMD' => 'Armenian Dram', 'AOA' => 'Kwanza',
        'ARS' => 'Argentine Peso', 'AUD' => 'Australian Dollar', 'AWG' => 'Aruban Florin', 'AZN' => 'Azerbaijan Manat',
        'BAM' => 'Convertible Mark', 'BBD' => 'Barbados Dollar', 'BDT' => 'Taka', 'BGN' => 'Bulgarian Lev', 'BHD' => 'Bahraini Dinar',
        'BIF' => 'Burundi Franc', 'BMD' => 'Bermudian Dollar', 'BND' => 'Brunei Dollar', 'BOB' => 'Boliviano', 'BRL' => 'Brazilian Real',
        'BSD' => 'Bahamian Dollar', 'BTN' => 'Ngultrum', 'BWP' => 'Pula', 'BYN' => 'Belarusian Ruble', 'BZD' => 'Belize Dollar',
        'CAD' => 'Canadian Dollar', 'CDF' => 'Congolese Franc', 'CHF' => 'Swiss Franc', 'CLP' => 'Chilean Peso', 'CNY' => 'Yuan Renminbi',
        'COP' => 'Colombian Peso', 'CRC' => 'Costa Rican Colón', 'CUP' => 'Cuban Peso', 'CVE' => 'Cabo Verde Escudo', 'CZK' => 'Czech Koruna',
        'DJF' => 'Djibouti Franc', 'DKK' => 'Danish Krone', 'DOP' => 'Dominican Peso', 'DZD' => 'Algerian Dinar', 'EGP' => 'Egyptian Pound',
        'ERN' => 'Nakfa', 'ETB' => 'Ethiopian Birr', 'EUR' => 'Euro', 'FJD' => 'Fiji Dollar', 'FKP' => 'Falkland Islands Pound',
        'GBP' => 'Pound Sterling', 'GEL' => 'Lari', 'GHS' => 'Ghana Cedi', 'GIP' => 'Gibraltar Pound', 'GMD' => 'Dalasi',
        'GNF' => 'Guinean Franc', 'GTQ' => 'Quetzal', 'GYD' => 'Guyana Dollar', 'HKD' => 'Hong Kong Dollar', 'HNL' => 'Lempira',
        'HTG' => 'Gourde', 'HUF' => 'Forint', 'IDR' => 'Rupiah', 'ILS' => 'New Israeli Shekel', 'INR' => 'Indian Rupee',
        'IQD' => 'Iraqi Dinar', 'IRR' => 'Iranian Rial', 'ISK' => 'Iceland Króna', 'JMD' => 'Jamaican Dollar', 'JOD' => 'Jordanian Dinar',
        'JPY' => 'Yen', 'KES' => 'Kenyan Shilling', 'KGS' => 'Som', 'KHR' => 'Riel', 'KMF' => 'Comorian Franc',
        'KPW' => 'North Korean Won', 'KRW' => 'Won', 'KWD' => 'Kuwaiti Dinar', 'KYD' => 'Cayman Islands Dollar', 'KZT' => 'Tenge',
        'LAK' => 'Lao Kip', 'LBP' => 'Lebanese Pound', 'LKR' => 'Sri Lanka Rupee', 'LRD' => 'Liberian Dollar', 'LSL' => 'Loti',
        'LYD' => 'Libyan Dinar', 'MAD' => 'Moroccan Dirham', 'MDL' => 'Moldovan Leu', 'MGA' => 'Malagasy Ariary', 'MKD' => 'Denar',
        'MMK' => 'Kyat', 'MNT' => 'Tugrik', 'MOP' => 'Pataca', 'MRU' => 'Ouguiya', 'MUR' => 'Mauritius Rupee',
        'MVR' => 'Rufiyaa', 'MWK' => 'Malawi Kwacha', 'MXN' => 'Mexican Peso', 'MYR' => 'Malaysian Ringgit', 'MZN' => 'Mozambique Metical',
        'NAD' => 'Namibia Dollar', 'NGN' => 'Naira', 'NIO' => 'Córdoba Oro', 'NOK' => 'Norwegian Krone', 'NPR' => 'Nepalese Rupee',
        'NZD' => 'New Zealand Dollar', 'OMR' => 'Rial Omani', 'PAB' => 'Balboa', 'PEN' => 'Sol', 'PGK' => 'Kina',
        'PHP' => 'Philippine Peso', 'PKR' => 'Pakistan Rupee', 'PLN' => 'Zloty', 'PYG' => 'Guarani', 'QAR' => 'Qatari Rial',
        'RON' => 'Romanian Leu', 'RSD' => 'Serbian Dinar', 'RUB' => 'Russian Ruble', 'RWF' => 'Rwanda Franc', 'SAR' => 'Saudi Riyal',
        'SBD' => 'Solomon Islands Dollar', 'SCR' => 'Seychelles Rupee', 'SDG' => 'Sudanese Pound', 'SEK' => 'Swedish Krona', 'SGD' => 'Singapore Dollar',
        'SHP' => 'Saint Helena Pound', 'SLE' => 'Leone', 'SOS' => 'Somali Shilling', 'SRD' => 'Surinam Dollar', 'SSP' => 'South Sudanese Pound',
        'STN' => 'Dobra', 'SVC' => 'El Salvador Colón', 'SYP' => 'Syrian Pound', 'SZL' => 'Lilangeni', 'THB' => 'Baht',
        'TJS' => 'Somoni', 'TMT' => 'Turkmenistan Manat', 'TND' => 'Tunisian Dinar', 'TOP' => 'Paʻanga', 'TRY' => 'Turkish Lira',
        'TTD' => 'Trinidad and Tobago Dollar', 'TWD' => 'New Taiwan Dollar', 'TZS' => 'Tanzanian Shilling', 'UAH' => 'Hryvnia', 'UGX' => 'Uganda Shilling',
        'USD' => 'US Dollar', 'UYU' => 'Uruguayan Peso', 'UZS' => 'Uzbekistan Sum', 'VES' => 'Bolívar Soberano', 'VND' => 'Dong',
        'VUV' => 'Vatu', 'WST' => 'Tala', 'XAF' => 'CFA Franc BEAC', 'XCD' => 'East Caribbean Dollar', 'XCG' => 'Caribbean Guilder',
        'XOF' => 'CFA Franc BCEAO', 'XPF' => 'CFP Franc', 'YER' => 'Yemeni Rial', 'ZAR' => 'Rand', 'ZMW' => 'Zambian Kwacha',
        'ZWG' => 'Zimbabwe Gold',
    ];

    /** TOP first, then the rest A–Z. @return list<array{code: string, name: string, top: bool}> */
    public static function options(): array
    {
        $codes = [...self::TOP, ...array_diff(array_keys(self::ALL), self::TOP)];

        return array_map(fn ($code) => ['code' => $code, 'name' => self::ALL[$code], 'top' => in_array($code, self::TOP, true)], $codes);
    }
}
