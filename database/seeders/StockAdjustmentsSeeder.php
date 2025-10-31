<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Business;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StockAdjustmentsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('========================================');
        $this->command->info('  Seeding Stock Adjustments...');
        $this->command->info('========================================');
        $this->command->newLine();

        $business = Business::first();

        if (!$business) {
            $this->command->error('No business found. Run InitialSetupSeeder first.');
            return;
        }

        $branches = Branch::where('business_id', $business->id)->get();
        $user = User::where('business_id', $business->id)->first();

        if ($branches->isEmpty() || !$user) {
            $this->command->error('Missing branches or users. Run previous seeders first.');
            return;
        }

        // Get all stocks
        $stocks = Stock::where('business_id', $business->id)
            ->with('product')
            ->get();

        if ($stocks->isEmpty()) {
            $this->command->error('No stocks found. Run ProductStockSeeder first.');
            return;
        }

        $adjustmentCount = 0;
        $numberOfAdjustments = 15;

        // Define adjustment reasons with their typical types
        $adjustmentReasons = [
            ['reason' => 'damaged', 'type' => 'decrease', 'weight' => 3],
            ['reason' => 'expired', 'type' => 'decrease', 'weight' => 2],
            ['reason' => 'theft', 'type' => 'decrease', 'weight' => 1],
            ['reason' => 'count_error', 'type' => 'both', 'weight' => 3],
            ['reason' => 'lost', 'type' => 'decrease', 'weight' => 2],
            ['reason' => 'found', 'type' => 'increase', 'weight' => 2],
            ['reason' => 'other', 'type' => 'both', 'weight' => 1],
        ];

        for ($i = 1; $i <= $numberOfAdjustments; $i++) {
            try {
                DB::beginTransaction();

                // Pick a random stock
                $stock = $stocks->random();
                $product = $stock->product;
                $branch = $stock->branch;

                // Select adjustment reason
                $reasonData = $adjustmentReasons[array_rand($adjustmentReasons)];
                $reason = $reasonData['reason'];

                // Determine adjustment type
                if ($reasonData['type'] === 'both') {
                    $adjustmentType = rand(0, 1) ? 'increase' : 'decrease';
                } else {
                    $adjustmentType = $reasonData['type'];
                }

                // Calculate quantity to adjust (1-10% of current stock, but at least 1)
                $maxAdjustment = max(1, (int)($stock->quantity * 0.10));
                $quantityAdjusted = rand(1, $maxAdjustment);

                // For decrease, ensure we don't go below zero
                if ($adjustmentType === 'decrease' && $quantityAdjusted > $stock->quantity) {
                    $quantityAdjusted = max(1, (int)($stock->quantity * 0.5));
                }

                $beforeQuantity = (float) $stock->quantity;
                $afterQuantity = $adjustmentType === 'increase'
                    ? $beforeQuantity + $quantityAdjusted
                    : $beforeQuantity - $quantityAdjusted;

                // Calculate cost impact
                $costImpact = $quantityAdjusted * (float) $stock->unit_cost;
                if ($adjustmentType === 'decrease') {
                    $costImpact = -$costImpact;
                }

                // Random date in last 20 days
                $adjustmentDate = Carbon::now()->subDays(rand(0, 20));

                // Generate notes based on reason
                $notes = $this->generateNotes($reason, $product->name, $quantityAdjusted);

                // Create adjustment
                $adjustment = StockAdjustment::create([
                    'business_id' => $business->id,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'adjusted_by' => $user->id,
                    'adjustment_type' => $adjustmentType,
                    'quantity_adjusted' => $quantityAdjusted,
                    'before_quantity' => $beforeQuantity,
                    'after_quantity' => $afterQuantity,
                    'reason' => $reason,
                    'cost_impact' => $costImpact,
                    'notes' => $notes,
                    'created_at' => $adjustmentDate,
                    'updated_at' => $adjustmentDate,
                ]);

                // Randomly approve some adjustments (70% approved)
                if (rand(1, 10) <= 7) {
                    $adjustment->update([
                        'approved_by' => $user->id,
                        'approved_at' => $adjustmentDate->copy()->addMinutes(rand(5, 120)),
                    ]);
                }

                // Update stock quantity
                $stock->quantity = $afterQuantity;
                if ($adjustmentType === 'increase') {
                    $stock->last_restocked_at = $adjustmentDate;
                }
                $stock->save();

                // Create stock movement
                $movementQuantity = $adjustmentType === 'increase'
                    ? $quantityAdjusted
                    : -$quantityAdjusted;

                StockMovement::create([
                    'business_id' => $business->id,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'movement_type' => 'adjustment',
                    'quantity' => $movementQuantity,
                    'previous_quantity' => $beforeQuantity,
                    'new_quantity' => $afterQuantity,
                    'unit_cost' => $stock->unit_cost,
                    'reference_type' => 'App\Models\StockAdjustment',
                    'reference_id' => $adjustment->id,
                    'reason' => $reason,
                    'notes' => $notes,
                    'created_at' => $adjustmentDate,
                    'updated_at' => $adjustmentDate,
                ]);

                $adjustmentCount++;
                $typeSymbol = $adjustmentType === 'increase' ? '+' : '-';
                $approvedText = $adjustment->is_approved ? '✓ Approved' : '⏳ Pending';

                $this->command->info("✓ Adjustment #{$adjustmentCount}: {$product->name} ({$typeSymbol}{$quantityAdjusted}) - {$reason} - {$approvedText}");

                DB::commit();

            } catch (\Exception $e) {
                DB::rollBack();
                $this->command->error("✗ Failed adjustment #{$i}: " . $e->getMessage());
            }
        }

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  Stock Adjustments Seeded Successfully!');
        $this->command->info('========================================');
        $this->command->info("  Total Adjustments Created: {$adjustmentCount}");
        $this->command->newLine();
    }

    /**
     * Generate contextual notes based on reason
     */
    private function generateNotes($reason, $productName, $quantity): string
    {
        $notes = [
            'damaged' => [
                "Found {$quantity} units of {$productName} damaged during inspection",
                "{$quantity} units damaged due to improper handling",
                "Physical damage detected on {$quantity} units during stock check",
            ],
            'expired' => [
                "Removed {$quantity} expired units from inventory",
                "{$quantity} units past expiry date - removed from stock",
                "Expiry date verification resulted in removal of {$quantity} units",
            ],
            'theft' => [
                "Missing inventory: {$quantity} units unaccounted for",
                "{$quantity} units missing after security audit",
                "Stock discrepancy due to potential theft",
            ],
            'count_error' => [
                "Physical count revealed discrepancy of {$quantity} units",
                "Stock count correction after inventory audit",
                "System vs physical count mismatch corrected",
            ],
            'lost' => [
                "{$quantity} units lost during handling",
                "Misplaced inventory: {$quantity} units",
                "Unable to locate {$quantity} units during audit",
            ],
            'found' => [
                "Found {$quantity} additional units during stock check",
                "{$quantity} units discovered in alternate location",
                "Inventory recovery: {$quantity} units found",
            ],
            'other' => [
                "Stock adjustment for {$productName}",
                "Miscellaneous adjustment of {$quantity} units",
                "Administrative stock correction",
            ],
        ];

        $reasonNotes = $notes[$reason] ?? $notes['other'];
        return $reasonNotes[array_rand($reasonNotes)];
    }
}
