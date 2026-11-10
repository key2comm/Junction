<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Countries for the customer dialing rules (Accounts → Dialing rules), with
 * their E.164 calling prefixes (no "+"). A dialed number belongs to the country
 * with the longest matching prefix, so 1876… is Jamaica, not Canada/USA, and
 * 77… is Kazakhstan, not Russia.
 *
 * NANP is Canada and the USA: everything on +1 that isn't listed under another
 * country, which includes the US territories (Puerto Rico, US Virgin Islands,
 * Guam, Northern Mariana Islands, American Samoa). It is always allowed.
 *
 * Kept in the dial_countries / dial_country_prefixes tables (sync()), which
 * softswitch_dial_country() reads while routing. After changing this list,
 * add a migration that calls sync().
 */
class DialCountries
{
    public const NANP = 'NANP';

    /** code => [name, region, prefixes] */
    public const ALL = [
        'NANP' => ['Canada / USA', 'North America', ['1']],
        'GL' => ['Greenland', 'North America', ['299']],
        'MX' => ['Mexico', 'North America', ['52']],
        'PM' => ['Saint Pierre and Miquelon', 'North America', ['508']],

        'BZ' => ['Belize', 'Central America', ['501']],
        'CR' => ['Costa Rica', 'Central America', ['506']],
        'SV' => ['El Salvador', 'Central America', ['503']],
        'GT' => ['Guatemala', 'Central America', ['502']],
        'HN' => ['Honduras', 'Central America', ['504']],
        'NI' => ['Nicaragua', 'Central America', ['505']],
        'PA' => ['Panama', 'Central America', ['507']],

        'AI' => ['Anguilla', 'Caribbean', ['1264']],
        'AG' => ['Antigua and Barbuda', 'Caribbean', ['1268']],
        'AW' => ['Aruba', 'Caribbean', ['297']],
        'BS' => ['Bahamas', 'Caribbean', ['1242']],
        'BB' => ['Barbados', 'Caribbean', ['1246']],
        'BM' => ['Bermuda', 'Caribbean', ['1441']],
        'BQ' => ['Bonaire, Sint Eustatius and Saba', 'Caribbean', ['5993', '5994', '5997']],
        'VG' => ['British Virgin Islands', 'Caribbean', ['1284']],
        'KY' => ['Cayman Islands', 'Caribbean', ['1345']],
        'CU' => ['Cuba', 'Caribbean', ['53']],
        'CW' => ['Curaçao', 'Caribbean', ['5999']],
        'DM' => ['Dominica', 'Caribbean', ['1767']],
        'DO' => ['Dominican Republic', 'Caribbean', ['1809', '1829', '1849']],
        'GD' => ['Grenada', 'Caribbean', ['1473']],
        'GP' => ['Guadeloupe, Saint Barthélemy and Saint Martin', 'Caribbean', ['590']],
        'HT' => ['Haiti', 'Caribbean', ['509']],
        'JM' => ['Jamaica', 'Caribbean', ['1876', '1658']],
        'MQ' => ['Martinique', 'Caribbean', ['596']],
        'MS' => ['Montserrat', 'Caribbean', ['1664']],
        'KN' => ['Saint Kitts and Nevis', 'Caribbean', ['1869']],
        'LC' => ['Saint Lucia', 'Caribbean', ['1758']],
        'VC' => ['Saint Vincent and the Grenadines', 'Caribbean', ['1784']],
        'SX' => ['Sint Maarten', 'Caribbean', ['1721']],
        'TT' => ['Trinidad and Tobago', 'Caribbean', ['1868']],
        'TC' => ['Turks and Caicos Islands', 'Caribbean', ['1649']],

        'AR' => ['Argentina', 'South America', ['54']],
        'BO' => ['Bolivia', 'South America', ['591']],
        'BR' => ['Brazil', 'South America', ['55']],
        'CL' => ['Chile', 'South America', ['56']],
        'CO' => ['Colombia', 'South America', ['57']],
        'EC' => ['Ecuador', 'South America', ['593']],
        'FK' => ['Falkland Islands', 'South America', ['500']],
        'GF' => ['French Guiana', 'South America', ['594']],
        'GY' => ['Guyana', 'South America', ['592']],
        'PY' => ['Paraguay', 'South America', ['595']],
        'PE' => ['Peru', 'South America', ['51']],
        'SR' => ['Suriname', 'South America', ['597']],
        'UY' => ['Uruguay', 'South America', ['598']],
        'VE' => ['Venezuela', 'South America', ['58']],

        'AX' => ['Åland Islands', 'Europe', ['35818']],
        'AL' => ['Albania', 'Europe', ['355']],
        'AD' => ['Andorra', 'Europe', ['376']],
        'AT' => ['Austria', 'Europe', ['43']],
        'BY' => ['Belarus', 'Europe', ['375']],
        'BE' => ['Belgium', 'Europe', ['32']],
        'BA' => ['Bosnia and Herzegovina', 'Europe', ['387']],
        'BG' => ['Bulgaria', 'Europe', ['359']],
        'HR' => ['Croatia', 'Europe', ['385']],
        'CY' => ['Cyprus', 'Europe', ['357']],
        'CZ' => ['Czechia', 'Europe', ['420']],
        'DK' => ['Denmark', 'Europe', ['45']],
        'EE' => ['Estonia', 'Europe', ['372']],
        'FO' => ['Faroe Islands', 'Europe', ['298']],
        'FI' => ['Finland', 'Europe', ['358']],
        'FR' => ['France', 'Europe', ['33']],
        'DE' => ['Germany', 'Europe', ['49']],
        'GI' => ['Gibraltar', 'Europe', ['350']],
        'GR' => ['Greece', 'Europe', ['30']],
        'GG' => ['Guernsey', 'Europe', ['441481']],
        'HU' => ['Hungary', 'Europe', ['36']],
        'IS' => ['Iceland', 'Europe', ['354']],
        'IE' => ['Ireland', 'Europe', ['353']],
        'IM' => ['Isle of Man', 'Europe', ['441624']],
        'IT' => ['Italy', 'Europe', ['39']],
        'JE' => ['Jersey', 'Europe', ['441534']],
        'XK' => ['Kosovo', 'Europe', ['383']],
        'LV' => ['Latvia', 'Europe', ['371']],
        'LI' => ['Liechtenstein', 'Europe', ['423']],
        'LT' => ['Lithuania', 'Europe', ['370']],
        'LU' => ['Luxembourg', 'Europe', ['352']],
        'MT' => ['Malta', 'Europe', ['356']],
        'MD' => ['Moldova', 'Europe', ['373']],
        'MC' => ['Monaco', 'Europe', ['377']],
        'ME' => ['Montenegro', 'Europe', ['382']],
        'NL' => ['Netherlands', 'Europe', ['31']],
        'MK' => ['North Macedonia', 'Europe', ['389']],
        'NO' => ['Norway', 'Europe', ['47']],
        'PL' => ['Poland', 'Europe', ['48']],
        'PT' => ['Portugal', 'Europe', ['351']],
        'RO' => ['Romania', 'Europe', ['40']],
        'RU' => ['Russia', 'Europe', ['7']],
        'SM' => ['San Marino', 'Europe', ['378']],
        'RS' => ['Serbia', 'Europe', ['381']],
        'SK' => ['Slovakia', 'Europe', ['421']],
        'SI' => ['Slovenia', 'Europe', ['386']],
        'ES' => ['Spain', 'Europe', ['34']],
        'SJ' => ['Svalbard and Jan Mayen', 'Europe', ['4779']],
        'SE' => ['Sweden', 'Europe', ['46']],
        'CH' => ['Switzerland', 'Europe', ['41']],
        'UA' => ['Ukraine', 'Europe', ['380']],
        'GB' => ['United Kingdom', 'Europe', ['44']],
        'VA' => ['Vatican City', 'Europe', ['379', '3906698']],

        'BH' => ['Bahrain', 'Middle East', ['973']],
        'IR' => ['Iran', 'Middle East', ['98']],
        'IQ' => ['Iraq', 'Middle East', ['964']],
        'IL' => ['Israel', 'Middle East', ['972']],
        'JO' => ['Jordan', 'Middle East', ['962']],
        'KW' => ['Kuwait', 'Middle East', ['965']],
        'LB' => ['Lebanon', 'Middle East', ['961']],
        'OM' => ['Oman', 'Middle East', ['968']],
        'PS' => ['Palestine', 'Middle East', ['970']],
        'QA' => ['Qatar', 'Middle East', ['974']],
        'SA' => ['Saudi Arabia', 'Middle East', ['966']],
        'SY' => ['Syria', 'Middle East', ['963']],
        'TR' => ['Türkiye', 'Middle East', ['90']],
        'AE' => ['United Arab Emirates', 'Middle East', ['971']],
        'YE' => ['Yemen', 'Middle East', ['967']],

        'AF' => ['Afghanistan', 'Asia', ['93']],
        'AM' => ['Armenia', 'Asia', ['374']],
        'AZ' => ['Azerbaijan', 'Asia', ['994']],
        'BD' => ['Bangladesh', 'Asia', ['880']],
        'BT' => ['Bhutan', 'Asia', ['975']],
        'IO' => ['British Indian Ocean Territory', 'Asia', ['246']],
        'BN' => ['Brunei', 'Asia', ['673']],
        'KH' => ['Cambodia', 'Asia', ['855']],
        'CN' => ['China', 'Asia', ['86']],
        'GE' => ['Georgia', 'Asia', ['995']],
        'HK' => ['Hong Kong', 'Asia', ['852']],
        'IN' => ['India', 'Asia', ['91']],
        'ID' => ['Indonesia', 'Asia', ['62']],
        'JP' => ['Japan', 'Asia', ['81']],
        'KZ' => ['Kazakhstan', 'Asia', ['76', '77']],
        'KG' => ['Kyrgyzstan', 'Asia', ['996']],
        'LA' => ['Laos', 'Asia', ['856']],
        'MO' => ['Macau', 'Asia', ['853']],
        'MY' => ['Malaysia', 'Asia', ['60']],
        'MV' => ['Maldives', 'Asia', ['960']],
        'MN' => ['Mongolia', 'Asia', ['976']],
        'MM' => ['Myanmar', 'Asia', ['95']],
        'NP' => ['Nepal', 'Asia', ['977']],
        'KP' => ['North Korea', 'Asia', ['850']],
        'PK' => ['Pakistan', 'Asia', ['92']],
        'PH' => ['Philippines', 'Asia', ['63']],
        'SG' => ['Singapore', 'Asia', ['65']],
        'KR' => ['South Korea', 'Asia', ['82']],
        'LK' => ['Sri Lanka', 'Asia', ['94']],
        'TW' => ['Taiwan', 'Asia', ['886']],
        'TJ' => ['Tajikistan', 'Asia', ['992']],
        'TH' => ['Thailand', 'Asia', ['66']],
        'TL' => ['Timor-Leste', 'Asia', ['670']],
        'TM' => ['Turkmenistan', 'Asia', ['993']],
        'UZ' => ['Uzbekistan', 'Asia', ['998']],
        'VN' => ['Vietnam', 'Asia', ['84']],

        'AU' => ['Australia', 'Oceania', ['61']],
        'CX' => ['Christmas Island', 'Oceania', ['6189164']],
        'CC' => ['Cocos (Keeling) Islands', 'Oceania', ['6189162']],
        'CK' => ['Cook Islands', 'Oceania', ['682']],
        'FJ' => ['Fiji', 'Oceania', ['679']],
        'PF' => ['French Polynesia', 'Oceania', ['689']],
        'KI' => ['Kiribati', 'Oceania', ['686']],
        'MH' => ['Marshall Islands', 'Oceania', ['692']],
        'FM' => ['Micronesia', 'Oceania', ['691']],
        'NR' => ['Nauru', 'Oceania', ['674']],
        'NC' => ['New Caledonia', 'Oceania', ['687']],
        'NZ' => ['New Zealand', 'Oceania', ['64']],
        'NU' => ['Niue', 'Oceania', ['683']],
        'NF' => ['Norfolk Island', 'Oceania', ['6723']],
        'PW' => ['Palau', 'Oceania', ['680']],
        'PG' => ['Papua New Guinea', 'Oceania', ['675']],
        'WS' => ['Samoa', 'Oceania', ['685']],
        'SB' => ['Solomon Islands', 'Oceania', ['677']],
        'TK' => ['Tokelau', 'Oceania', ['690']],
        'TO' => ['Tonga', 'Oceania', ['676']],
        'TV' => ['Tuvalu', 'Oceania', ['688']],
        'VU' => ['Vanuatu', 'Oceania', ['678']],
        'WF' => ['Wallis and Futuna', 'Oceania', ['681']],

        'DZ' => ['Algeria', 'Africa', ['213']],
        'AO' => ['Angola', 'Africa', ['244']],
        'BJ' => ['Benin', 'Africa', ['229']],
        'BW' => ['Botswana', 'Africa', ['267']],
        'BF' => ['Burkina Faso', 'Africa', ['226']],
        'BI' => ['Burundi', 'Africa', ['257']],
        'CV' => ['Cabo Verde', 'Africa', ['238']],
        'CM' => ['Cameroon', 'Africa', ['237']],
        'CF' => ['Central African Republic', 'Africa', ['236']],
        'TD' => ['Chad', 'Africa', ['235']],
        'KM' => ['Comoros', 'Africa', ['269']],
        'CG' => ['Congo', 'Africa', ['242']],
        'CD' => ['Congo (DRC)', 'Africa', ['243']],
        'CI' => ["Côte d'Ivoire", 'Africa', ['225']],
        'DJ' => ['Djibouti', 'Africa', ['253']],
        'EG' => ['Egypt', 'Africa', ['20']],
        'GQ' => ['Equatorial Guinea', 'Africa', ['240']],
        'ER' => ['Eritrea', 'Africa', ['291']],
        'SZ' => ['Eswatini', 'Africa', ['268']],
        'ET' => ['Ethiopia', 'Africa', ['251']],
        'GA' => ['Gabon', 'Africa', ['241']],
        'GM' => ['Gambia', 'Africa', ['220']],
        'GH' => ['Ghana', 'Africa', ['233']],
        'GN' => ['Guinea', 'Africa', ['224']],
        'GW' => ['Guinea-Bissau', 'Africa', ['245']],
        'KE' => ['Kenya', 'Africa', ['254']],
        'LS' => ['Lesotho', 'Africa', ['266']],
        'LR' => ['Liberia', 'Africa', ['231']],
        'LY' => ['Libya', 'Africa', ['218']],
        'MG' => ['Madagascar', 'Africa', ['261']],
        'MW' => ['Malawi', 'Africa', ['265']],
        'ML' => ['Mali', 'Africa', ['223']],
        'MR' => ['Mauritania', 'Africa', ['222']],
        'MU' => ['Mauritius', 'Africa', ['230']],
        'YT' => ['Mayotte', 'Africa', ['262269', '262639']],
        'MA' => ['Morocco', 'Africa', ['212']],
        'MZ' => ['Mozambique', 'Africa', ['258']],
        'NA' => ['Namibia', 'Africa', ['264']],
        'NE' => ['Niger', 'Africa', ['227']],
        'NG' => ['Nigeria', 'Africa', ['234']],
        'RE' => ['Réunion', 'Africa', ['262']],
        'RW' => ['Rwanda', 'Africa', ['250']],
        'SH' => ['Saint Helena and Ascension', 'Africa', ['290', '247']],
        'ST' => ['São Tomé and Príncipe', 'Africa', ['239']],
        'SN' => ['Senegal', 'Africa', ['221']],
        'SC' => ['Seychelles', 'Africa', ['248']],
        'SL' => ['Sierra Leone', 'Africa', ['232']],
        'SO' => ['Somalia', 'Africa', ['252']],
        'ZA' => ['South Africa', 'Africa', ['27']],
        'SS' => ['South Sudan', 'Africa', ['211']],
        'SD' => ['Sudan', 'Africa', ['249']],
        'TZ' => ['Tanzania', 'Africa', ['255']],
        'TG' => ['Togo', 'Africa', ['228']],
        'TN' => ['Tunisia', 'Africa', ['216']],
        'UG' => ['Uganda', 'Africa', ['256']],
        'EH' => ['Western Sahara', 'Africa', ['2125288', '2125289']],
        'ZM' => ['Zambia', 'Africa', ['260']],
        'ZW' => ['Zimbabwe', 'Africa', ['263']],

        // Not countries: satellite phones and global services, often used for fraud.
        'XS' => ['Satellite and global services', 'Satellite and global', ['800', '808', '870', '878', '881', '882', '883', '888', '979']],
    ];

    /** The countries an account can choose (everything except Canada/USA, which is always allowed). */
    public static function selectable(): array
    {
        $out = [];
        foreach (self::ALL as $code => [$name, $region, $prefixes]) {
            if ($code !== self::NANP) {
                $out[] = ['code' => $code, 'name' => $name, 'region' => $region, 'prefixes' => $prefixes];
            }
        }

        return $out;
    }

    /** Write the list into the routing tables (replacing what is there). */
    public static function sync(): void
    {
        DB::transaction(function () {
            DB::table('dial_country_prefixes')->delete();
            DB::table('dial_countries')->whereNotIn('code', array_keys(self::ALL))->delete();
            foreach (self::ALL as $code => [$name, $region, $prefixes]) {
                DB::table('dial_countries')->updateOrInsert(['code' => $code], ['name' => $name, 'region' => $region]);
                foreach ($prefixes as $prefix) {
                    DB::table('dial_country_prefixes')->insert(['prefix' => $prefix, 'country_code' => $code]);
                }
            }
        });
    }
}
