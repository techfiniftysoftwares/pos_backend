<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ExchangeRateSource;
use App\Models\Business;
use App\Models\User;

class ExchangeRateSeeder extends Seeder
{
    public function run()
    {
        // Get currencies
        $kes = Currency::where('code', 'KES')->first();
        $usd = Currency::where('code', 'USD')->first();
        $cdf = Currency::where('code', 'CDF')->first();

        if (!$kes || !$usd || !$cdf) {
            $this->command->error('Currencies not found. Run CurrencySeeder first.');
            return;
        }

        // Get or create manual source
        $source = ExchangeRateSource::updateOrCreate(
            ['code' => 'manual'],
            [
                'name' => 'Manual Entry',
                'description' => 'Manually entered exchange rates',
                'is_active' => true,
            ]
        );

        // Get the first business and user
        $business = Business::first();
        $user = User::first();

        if (!$business) {
            $this->command->error('No business found. Create a business first.');
            return;
        }

        if (!$user) {
            $this->command->error('No user found. Create a user first.');
            return;
        }

        // Exchange rates (approximate as of Jan 2026)
        // KES is the base currency
        // 1 USD ≈ 129 KES
        // 1 USD ≈ 2,800 CDF
        // So: 1 KES ≈ 21.7 CDF

        $rates = [
            // USD to KES
            [
                'business_id' => $business->id,
                'from_currency_id' => $usd->id,
                'to_currency_id' => $kes->id,
                'rate' => 129.00,
                'source_id' => $source->id,
                'effective_date' => now(),
                'is_active' => true,
                'created_by' => $user->id,
            ],
            // KES to USD
            [
                'business_id' => $business->id,
                'from_currency_id' => $kes->id,
                'to_currency_id' => $usd->id,
                'rate' => 0.00775, // 1/129
                'source_id' => $source->id,
                'effective_date' => now(),
                'is_active' => true,
                'created_by' => $user->id,
            ],
            // CDF to KES
            [
                'business_id' => $business->id,
                'from_currency_id' => $cdf->id,
                'to_currency_id' => $kes->id,
                'rate' => 0.046, // 129 / 2800 ≈ 0.046
                'source_id' => $source->id,
                'effective_date' => now(),
                'is_active' => true,
                'created_by' => $user->id,
            ],
            // KES to CDF
            [
                'business_id' => $business->id,
                'from_currency_id' => $kes->id,
                'to_currency_id' => $cdf->id,
                'rate' => 21.70, // 2800 / 129 ≈ 21.7
                'source_id' => $source->id,
                'effective_date' => now(),
                'is_active' => true,
                'created_by' => $user->id,
            ],
            // USD to CDF
            [
                'business_id' => $business->id,
                'from_currency_id' => $usd->id,
                'to_currency_id' => $cdf->id,
                'rate' => 2800.00,
                'source_id' => $source->id,
                'effective_date' => now(),
                'is_active' => true,
                'created_by' => $user->id,
            ],
            // CDF to USD
            [
                'business_id' => $business->id,
                'from_currency_id' => $cdf->id,
                'to_currency_id' => $usd->id,
                'rate' => 0.000357, // 1/2800
                'source_id' => $source->id,
                'effective_date' => now(),
                'is_active' => true,
                'created_by' => $user->id,
            ],
        ];

        foreach ($rates as $rate) {
            ExchangeRate::updateOrCreate(
                [
                    'business_id' => $rate['business_id'],
                    'from_currency_id' => $rate['from_currency_id'],
                    'to_currency_id' => $rate['to_currency_id'],
                ],
                $rate
            );
        }

        $this->command->info('Exchange rates seeded successfully!');
        $this->command->info('  USD → KES: 129.00');
        $this->command->info('  USD → CDF: 2,800.00');
        $this->command->info('  KES → CDF: 21.70');
    }
}
