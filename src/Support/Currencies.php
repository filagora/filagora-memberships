<?php

declare(strict_types=1);

namespace Filagora\Memberships\Support;

/**
 * The currencies a price can be set in.
 */
final class Currencies
{
    /**
     * @return array<string, string> code => label
     */
    public static function options(): array
    {
        return [
            'USD' => 'USD - US Dollar',
            'EUR' => 'EUR - Euro',
            'GBP' => 'GBP - British Pound',
            'AUD' => 'AUD - Australian Dollar',
            'CAD' => 'CAD - Canadian Dollar',
            'NZD' => 'NZD - New Zealand Dollar',
            'CHF' => 'CHF - Swiss Franc',
            'JPY' => 'JPY - Japanese Yen',
            'CNY' => 'CNY - Chinese Yuan',
            'HKD' => 'HKD - Hong Kong Dollar',
            'SGD' => 'SGD - Singapore Dollar',
            'SEK' => 'SEK - Swedish Krona',
            'NOK' => 'NOK - Norwegian Krone',
            'DKK' => 'DKK - Danish Krone',
            'PLN' => 'PLN - Polish Zloty',
            'CZK' => 'CZK - Czech Koruna',
            'HUF' => 'HUF - Hungarian Forint',
            'ILS' => 'ILS - Israeli Shekel',
            'MXN' => 'MXN - Mexican Peso',
            'BRL' => 'BRL - Brazilian Real',
            'MYR' => 'MYR - Malaysian Ringgit',
            'PHP' => 'PHP - Philippine Peso',
            'TWD' => 'TWD - New Taiwan Dollar',
            'THB' => 'THB - Thai Baht',
        ];
    }
}
