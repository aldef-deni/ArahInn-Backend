<?php

namespace App\Support;

/**
 * Peta kode negara ISO 3166-1 alpha-2 → nama (Indonesia).
 * Dipakai voucher e-tiket untuk menampilkan kewarganegaraan & negara penerbit paspor
 * sesuai isian form penumpang (form FE menyimpan kode ISO2).
 */
class Countries
{
    public const MAP = [
        'ID' => 'Indonesia', 'AF' => 'Afghanistan', 'ZA' => 'Afrika Selatan', 'AL' => 'Albania',
        'DZ' => 'Aljazair', 'US' => 'Amerika Serikat', 'AD' => 'Andorra', 'AO' => 'Angola',
        'AI' => 'Anguilla', 'AG' => 'Antigua dan Barbuda', 'SA' => 'Arab Saudi', 'AR' => 'Argentina',
        'AM' => 'Armenia', 'AW' => 'Aruba', 'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan',
        'BS' => 'Bahama', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus',
        'BE' => 'Belgia', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BM' => 'Bermuda', 'BT' => 'Bhutan',
        'BO' => 'Bolivia', 'BA' => 'Bosnia dan Herzegovina', 'BW' => 'Botswana', 'BR' => 'Brasil',
        'BN' => 'Brunei Darussalam', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
        'CV' => 'Tanjung Verde', 'KH' => 'Kamboja', 'CM' => 'Kamerun', 'CA' => 'Kanada',
        'KY' => 'Kepulauan Cayman', 'CF' => 'Republik Afrika Tengah', 'TD' => 'Chad', 'CL' => 'Cile',
        'CN' => 'Tiongkok', 'CO' => 'Kolombia', 'KM' => 'Komoro', 'CG' => 'Kongo', 'CD' => 'Kongo (RDK)',
        'CR' => 'Kosta Rika', 'CI' => 'Pantai Gading', 'HR' => 'Kroasia', 'CU' => 'Kuba', 'CY' => 'Siprus',
        'CZ' => 'Republik Ceko', 'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominika',
        'DO' => 'Republik Dominika', 'EC' => 'Ekuador', 'EG' => 'Mesir', 'SV' => 'El Salvador',
        'GQ' => 'Guinea Khatulistiwa', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'SZ' => 'Eswatini',
        'ET' => 'Etiopia', 'FJ' => 'Fiji', 'FI' => 'Finlandia', 'FR' => 'Prancis', 'GA' => 'Gabon',
        'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Jerman', 'GH' => 'Ghana', 'GR' => 'Yunani',
        'GD' => 'Grenada', 'GT' => 'Guatemala', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana',
        'HT' => 'Haiti', 'HN' => 'Honduras', 'HK' => 'Hong Kong', 'HU' => 'Hungaria', 'IS' => 'Islandia',
        'IN' => 'India', 'IR' => 'Iran', 'IQ' => 'Irak', 'IE' => 'Irlandia', 'IL' => 'Israel', 'IT' => 'Italia',
        'JM' => 'Jamaika', 'JP' => 'Jepang', 'JO' => 'Yordania', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya',
        'KI' => 'Kiribati', 'KW' => 'Kuwait', 'KG' => 'Kirgizstan', 'LA' => 'Laos', 'LV' => 'Latvia',
        'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein',
        'LT' => 'Lituania', 'LU' => 'Luksemburg', 'MO' => 'Makau', 'MG' => 'Madagaskar', 'MW' => 'Malawi',
        'MY' => 'Malaysia', 'MV' => 'Maladewa', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Kepulauan Marshall',
        'MR' => 'Mauritania', 'MU' => 'Mauritius', 'MX' => 'Meksiko', 'FM' => 'Mikronesia', 'MD' => 'Moldova',
        'MC' => 'Monako', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MA' => 'Maroko', 'MZ' => 'Mozambik',
        'MM' => 'Myanmar', 'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Belanda',
        'NZ' => 'Selandia Baru', 'NI' => 'Nikaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'KP' => 'Korea Utara',
        'MK' => 'Makedonia Utara', 'NO' => 'Norwegia', 'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau',
        'PS' => 'Palestina', 'PA' => 'Panama', 'PG' => 'Papua Nugini', 'PY' => 'Paraguay', 'PE' => 'Peru',
        'PH' => 'Filipina', 'PL' => 'Polandia', 'PT' => 'Portugal', 'QA' => 'Qatar', 'RO' => 'Rumania',
        'RU' => 'Rusia', 'RW' => 'Rwanda', 'KN' => 'Saint Kitts dan Nevis', 'LC' => 'Saint Lucia',
        'VC' => 'Saint Vincent dan Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino',
        'ST' => 'Sao Tome dan Principe', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles',
        'SL' => 'Sierra Leone', 'SG' => 'Singapura', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
        'SB' => 'Kepulauan Solomon', 'SO' => 'Somalia', 'KR' => 'Korea Selatan', 'SS' => 'Sudan Selatan',
        'ES' => 'Spanyol', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SE' => 'Swedia',
        'CH' => 'Swiss', 'SY' => 'Suriah', 'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania',
        'TH' => 'Thailand', 'TL' => 'Timor Leste', 'TG' => 'Togo', 'TO' => 'Tonga',
        'TT' => 'Trinidad dan Tobago', 'TN' => 'Tunisia', 'TR' => 'Turki', 'TM' => 'Turkmenistan',
        'TV' => 'Tuvalu', 'UG' => 'Uganda', 'UA' => 'Ukraina', 'AE' => 'Uni Emirat Arab', 'GB' => 'Inggris',
        'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VA' => 'Vatikan', 'VE' => 'Venezuela',
        'VN' => 'Vietnam', 'YE' => 'Yaman', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    ];

    /** Nama negara dari kode ISO2. Bila kode tak dikenal, kembalikan apa adanya (sudah berupa nama). */
    public static function name(?string $code): string
    {
        $code = trim((string) $code);
        if ($code === '') return '';
        return self::MAP[strtoupper($code)] ?? $code;
    }

    /** True bila WNA (kode negara ada & bukan Indonesia). */
    public static function isForeign(?string $code): bool
    {
        $code = strtoupper(trim((string) $code));
        return $code !== '' && $code !== 'ID';
    }
}
