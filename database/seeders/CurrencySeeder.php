<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Currency;

class CurrencySeeder extends Seeder
{
    public function run()
    {
        $currencies = [
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'is_base' => false,
                'is_active' => true,
            ],
            [
                'code' => 'KES',
                'name' => 'Kenyan Shilling',
                'symbol' => 'KSh',
                'is_base' => true, // Set your base currency
                'is_active' => true,
            ],
            [
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'is_base' => false,
                'is_active' => true,
            ],
            [
                'code' => 'GBP',
                'name' => 'British Pound',
                'symbol' => '£',
                'is_base' => false,
                'is_active' => true,
            ],
            [
                'code' => 'TZS',
                'name' => 'Tanzanian Shilling',
                'symbol' => 'TSh',
                'is_base' => false,
                'is_active' => true,
            ],
            [
                'code' => 'UGX',
                'name' => 'Ugandan Shilling',
                'symbol' => 'USh',
                'is_base' => false,
                'is_active' => true,
            ],
        ];

        foreach ($currencies as $currency) {
            Currency::create($currency);
        }
    }
}
