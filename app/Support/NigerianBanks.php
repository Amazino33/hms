<?php

namespace App\Support;

/**
 * The bank list staff choose from, keyed by the slug stored in
 * users.bank_name. Shared by the Users form and the public /join form so a
 * bank chosen on either means the same thing.
 */
class NigerianBanks
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'access' => 'Access Bank',
            'fidelity' => 'Fidelity Bank',
            'fcmb' => 'First City Monument Bank (FCMB)',
            'first_bank' => 'First Bank of Nigeria',
            'gtbank' => 'Guaranty Trust Bank (GTB)',
            'heritage' => 'Heritage Bank',
            'keystone' => 'Keystone Bank',
            'moniepoint' => 'Moniepoint MFB',
            'opay' => 'OPay (Digital Bank)',
            'palmpay' => 'PalmPay',
            'polaris' => 'Polaris Bank',
            'providus' => 'Providus Bank',
            'stanbic' => 'Stanbic IBTC Bank',
            'standard_chartered' => 'Standard Chartered',
            'sterling' => 'Sterling Bank',
            'suntrust' => 'SunTrust Bank',
            'union' => 'Union Bank',
            'uba' => 'United Bank for Africa (UBA)',
            'unity' => 'Unity Bank',
            'wema' => 'Wema Bank',
            'zenith' => 'Zenith Bank',
        ];
    }
}
