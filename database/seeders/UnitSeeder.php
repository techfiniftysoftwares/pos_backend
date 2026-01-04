<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Unit;
use App\Models\Business;

class UnitSeeder extends Seeder
{
    public function run()
    {
        $business = Business::first();

        if (!$business) {
            $this->command->error('No business found. Create a business first.');
            return;
        }

        // Base units (no conversion needed)
        $baseUnits = [
            // Count/Quantity
            ['name' => 'Piece', 'symbol' => 'pc', 'is_base' => true],
            ['name' => 'Unit', 'symbol' => 'unit', 'is_base' => true],

            // Weight
            ['name' => 'Kilogram', 'symbol' => 'kg', 'is_base' => true],
            ['name' => 'Gram', 'symbol' => 'g', 'is_base' => true],

            // Volume
            ['name' => 'Liter', 'symbol' => 'L', 'is_base' => true],
            ['name' => 'Milliliter', 'symbol' => 'mL', 'is_base' => true],

            // Length
            ['name' => 'Meter', 'symbol' => 'm', 'is_base' => true],
            ['name' => 'Centimeter', 'symbol' => 'cm', 'is_base' => true],

            // Packaging
            ['name' => 'Box', 'symbol' => 'box', 'is_base' => true],
            ['name' => 'Pack', 'symbol' => 'pack', 'is_base' => true],
            ['name' => 'Carton', 'symbol' => 'ctn', 'is_base' => true],
            ['name' => 'Dozen', 'symbol' => 'dz', 'is_base' => true],
            ['name' => 'Pair', 'symbol' => 'pair', 'is_base' => true],
            ['name' => 'Set', 'symbol' => 'set', 'is_base' => true],
            ['name' => 'Bundle', 'symbol' => 'bdl', 'is_base' => true],
            ['name' => 'Roll', 'symbol' => 'roll', 'is_base' => true],
            ['name' => 'Bag', 'symbol' => 'bag', 'is_base' => true],
            ['name' => 'Bottle', 'symbol' => 'btl', 'is_base' => true],
            ['name' => 'Can', 'symbol' => 'can', 'is_base' => true],
            ['name' => 'Sachet', 'symbol' => 'scht', 'is_base' => true],
        ];

        foreach ($baseUnits as $unit) {
            Unit::updateOrCreate(
                [
                    'business_id' => $business->id,
                    'name' => $unit['name'],
                ],
                [
                    'business_id' => $business->id,
                    'name' => $unit['name'],
                    'symbol' => $unit['symbol'],
                    'base_unit_id' => null,
                    'conversion_factor' => 1,
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('Units seeded successfully!');
        $this->command->info('Created ' . count($baseUnits) . ' units.');
    }
}
