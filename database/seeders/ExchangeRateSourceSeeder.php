<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ExchangeRateSource;

class ExchangeRateSourceSeeder extends Seeder
{
    public function run()
    {
        $sources = [
            [
                'name' => 'Manual Entry',
                'code' => 'manual',
                'description' => 'Manually entered exchange rates',
                'is_active' => true,
            ],
            [
                'name' => 'API Provider',
                'code' => 'api_provider',
                'description' => 'Rates from external API service',
                'is_active' => true,
            ],
            [
                'name' => 'Commercial Bank',
                'code' => 'commercial_bank',
                'description' => 'Rates from commercial banks',
                'is_active' => true,
            ],
        ];

        foreach ($sources as $source) {
            ExchangeRateSource::create($source);
        }
    }
}
