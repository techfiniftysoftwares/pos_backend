<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\RevenueStream;
use App\Models\RevenueEntry;
use App\Models\Business;
use App\Models\Branch;
use App\Models\User;
use Carbon\Carbon;

class RevenueSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get first business and its branches
        $business = Business::first();

        if (!$business) {
            $this->command->error('No business found. Please create a business first.');
            return;
        }

        $branches = Branch::where('business_id', $business->id)->get();

        if ($branches->isEmpty()) {
            $this->command->error('No branches found for the business.');
            return;
        }

        $users = User::where('business_id', $business->id)->get();

        if ($users->isEmpty()) {
            $this->command->error('No users found for the business.');
            return;
        }

        // Create Revenue Streams
        $this->command->info('Creating revenue streams...');

        $streams = [
            [
                'business_id' => $business->id,
                'name' => 'Restaurant Sales',
                'code' => 'restaurant',
                'description' => 'Revenue from restaurant food and beverage sales',
                'default_currency' => 'KES',
                'requires_approval' => false,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Toilet Service',
                'code' => 'toilet',
                'description' => 'Revenue from public toilet services',
                'default_currency' => 'KES',
                'requires_approval' => false,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Water Sales',
                'code' => 'water',
                'description' => 'Revenue from bottled water sales',
                'default_currency' => 'KES',
                'requires_approval' => false,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Utilities Service',
                'code' => 'utilities',
                'description' => 'Revenue from utility services provided',
                'default_currency' => 'KES',
                'requires_approval' => true,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Parking Fees',
                'code' => 'parking',
                'description' => 'Revenue from parking services',
                'default_currency' => 'KES',
                'requires_approval' => false,
                'is_active' => true,
            ],
        ];

        $createdStreams = [];
        foreach ($streams as $streamData) {
            $stream = RevenueStream::create($streamData);
            $createdStreams[] = $stream;
            $this->command->info("✓ Created revenue stream: {$stream->name}");
        }

        // Create Revenue Entries
        $this->command->info('Creating revenue entries...');

        $currencies = ['KES', 'USD', 'EUR'];
        $exchangeRates = [
            'KES' => 1.0,
            'USD' => 150.0,
            'EUR' => 165.0,
        ];

        $statuses = ['pending', 'approved', 'rejected'];

        for ($i = 1; $i <= 15; $i++) {
            $stream = $createdStreams[array_rand($createdStreams)];
            $branch = $branches->random();
            $recordedBy = $users->random();
            $currency = $currencies[array_rand($currencies)];
            $amount = rand(1000, 50000);
            $exchangeRate = $exchangeRates[$currency];
            $entryDate = Carbon::now()->subDays(rand(0, 30));

            // Determine status
            if ($stream->requires_approval) {
                $status = $statuses[array_rand($statuses)];
            } else {
                $status = 'approved';
            }

            $entryData = [
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'revenue_stream_id' => $stream->id,
                'amount' => $amount,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'amount_in_base_currency' => $amount * $exchangeRate,
                'entry_date' => $entryDate,
                'receipt_number' => 'RCP-' . strtoupper(uniqid()),
                'notes' => "Sample revenue entry for {$stream->name} at {$branch->name}",
                'status' => $status,
                'recorded_by' => $recordedBy->id,
            ];

            // Add approval details if approved or rejected
            if (in_array($status, ['approved', 'rejected'])) {
                $entryData['approved_by'] = $users->random()->id;
                $entryData['approved_at'] = $entryDate->addHours(rand(1, 24));
            }

            $entry = RevenueEntry::create($entryData);

            $this->command->info("✓ Created revenue entry #{$i}: {$stream->name} - {$currency} {$amount} ({$status})");
        }

        $this->command->info('');
        $this->command->info('Revenue data seeded successfully!');
        $this->command->info("Total Streams: " . count($createdStreams));
        $this->command->info("Total Entries: 15");
    }
}
