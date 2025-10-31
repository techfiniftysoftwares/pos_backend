<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Business;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockMovement;
use App\Models\StockBatch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StockTransfersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('========================================');
        $this->command->info('  Seeding Stock Transfers...');
        $this->command->info('========================================');
        $this->command->newLine();

        $business = Business::first();

        if (!$business) {
            $this->command->error('No business found. Run InitialSetupSeeder first.');
            return;
        }

        $branches = Branch::where('business_id', $business->id)->get();
        $user = User::where('business_id', $business->id)->first();

        if ($branches->count() < 2) {
            $this->command->error('Need at least 2 branches. Run AdditionalBranchSeeder first.');
            return;
        }

        if (!$user) {
            $this->command->error('No users found. Run InitialSetupSeeder first.');
            return;
        }

        // Get products that have stock in both branches
        $products = Product::where('business_id', $business->id)
            ->whereHas('stocks', function($q) use ($business) {
                $q->where('business_id', $business->id)
                  ->where('quantity', '>', 10);
            })
            ->get();

        if ($products->isEmpty()) {
            $this->command->error('No products with sufficient stock found.');
            return;
        }

        $transferCount = 0;
        $numberOfTransfers = 8;

        // Define transfer statuses with their weights
        $statusWeights = [
            'completed' => 4,  // 40% completed
            'in_transit' => 2, // 20% in transit
            'approved' => 2,   // 20% approved
            'pending' => 2,    // 20% pending
        ];

        $transferReasons = [
            'Stock replenishment',
            'Branch transfer request',
            'Balancing inventory levels',
            'Customer order fulfillment',
            'Seasonal stock adjustment',
            'Emergency restocking',
            'Warehouse reorganization',
            'Branch closure preparation',
        ];

        for ($i = 1; $i <= $numberOfTransfers; $i++) {
            try {
                DB::beginTransaction();

                // Randomly select source and destination branches
                $fromBranch = $branches->random();
                $toBranch = $branches->where('id', '!=', $fromBranch->id)->random();

                // Select 2-4 random products for this transfer
                $transferProducts = $products->random(rand(2, min(4, $products->count())));

                // Random transfer date (last 25 days)
                $transferDate = Carbon::now()->subDays(rand(0, 25));

                // Select transfer status based on weights
                $status = $this->getWeightedRandomStatus($statusWeights);

                // Generate transfer number
                $transferNumber = $this->generateTransferNumber($business->id, $transferDate);

                // Create transfer
                $transfer = StockTransfer::create([
                    'transfer_number' => $transferNumber,
                    'business_id' => $business->id,
                    'from_branch_id' => $fromBranch->id,
                    'to_branch_id' => $toBranch->id,
                    'initiated_by' => $user->id,
                    'status' => $status,
                    'transfer_date' => $transferDate,
                    'expected_delivery_date' => $transferDate->copy()->addDays(rand(1, 3)),
                    'transfer_reason' => $transferReasons[array_rand($transferReasons)],
                    'notes' => "Seeded transfer from {$fromBranch->name} to {$toBranch->name}",
                    'created_at' => $transferDate,
                    'updated_at' => $transferDate,
                ]);

                // Set approval timestamp if approved or beyond
                if (in_array($status, ['approved', 'in_transit', 'completed'])) {
                    $transfer->update([
                        'approved_by' => $user->id,
                        'approved_at' => $transferDate->copy()->addHours(rand(1, 4)),
                    ]);
                }

                // Set completion timestamp if completed
                if ($status === 'completed') {
                    $transfer->update([
                        'received_by' => $user->id,
                        'completed_at' => $transferDate->copy()->addDays(rand(1, 3))->addHours(rand(1, 12)),
                    ]);
                }

                $itemsCreated = 0;

                // Create transfer items
                foreach ($transferProducts as $product) {
                    // Get stock at source branch
                    $sourceStock = Stock::where('business_id', $business->id)
                        ->where('branch_id', $fromBranch->id)
                        ->where('product_id', $product->id)
                        ->first();

                    if (!$sourceStock || $sourceStock->quantity <= 0) {
                        continue; // Skip if no stock
                    }

                    // Calculate quantity to transfer (5-20% of current stock, minimum 1)
                    $maxTransfer = max(1, (int)($sourceStock->quantity * 0.20));
                    $quantityToTransfer = rand(1, min($maxTransfer, (int)$sourceStock->quantity));

                    // Determine quantities based on status
                    $quantitySent = in_array($status, ['in_transit', 'completed']) ? $quantityToTransfer : 0;
                    $quantityReceived = $status === 'completed' ? $quantityToTransfer : 0;

                    // Create transfer item
                    StockTransferItem::create([
                        'stock_transfer_id' => $transfer->id,
                        'product_id' => $product->id,
                        'quantity_requested' => $quantityToTransfer,
                        'quantity_sent' => $quantitySent,
                        'quantity_received' => $quantityReceived,
                        'unit_cost' => $sourceStock->unit_cost,
                        'notes' => null,
                    ]);

                    $itemsCreated++;

                    // Update stock if transfer is in-transit or completed
                    if ($quantitySent > 0) {
                        // Decrease source stock
                        $previousQty = (float) $sourceStock->quantity;
                        $sourceStock->quantity = $previousQty - $quantitySent;
                        $sourceStock->save();

                        // Create stock movement for source
                        StockMovement::create([
                            'business_id' => $business->id,
                            'branch_id' => $fromBranch->id,
                            'product_id' => $product->id,
                            'user_id' => $user->id,
                            'movement_type' => 'transfer_out',
                            'quantity' => -$quantitySent,
                            'previous_quantity' => $previousQty,
                            'new_quantity' => (float) $sourceStock->quantity,
                            'unit_cost' => $sourceStock->unit_cost,
                            'reference_type' => 'App\Models\StockTransfer',
                            'reference_id' => $transfer->id,
                            'notes' => "Transfer to {$toBranch->name} - {$transferNumber}",
                            'created_at' => $transferDate->copy()->addHours(rand(2, 5)),
                            'updated_at' => $transferDate->copy()->addHours(rand(2, 5)),
                        ]);

                        // Deduct from FIFO batches
                        $this->deductFromBatches($sourceStock, $quantitySent);
                    }

                    // Increase destination stock if completed
                    if ($quantityReceived > 0) {
                        $destStock = Stock::where('business_id', $business->id)
                            ->where('branch_id', $toBranch->id)
                            ->where('product_id', $product->id)
                            ->first();

                        if (!$destStock) {
                            // Create stock if doesn't exist
                            $destStock = Stock::create([
                                'business_id' => $business->id,
                                'branch_id' => $toBranch->id,
                                'product_id' => $product->id,
                                'quantity' => $quantityReceived,
                                'reserved_quantity' => 0,
                                'unit_cost' => $sourceStock->unit_cost,
                                'last_restocked_at' => now(),
                            ]);

                            // Create batch for new stock
                            StockBatch::create([
                                'business_id' => $business->id,
                                'branch_id' => $toBranch->id,
                                'stock_id' => $destStock->id,
                                'product_id' => $product->id,
                                'batch_number' => 'TRF-' . $transferNumber . '-' . $product->id,
                                'quantity_received' => $quantityReceived,
                                'quantity_remaining' => $quantityReceived,
                                'unit_cost' => $sourceStock->unit_cost,
                                'received_date' => $transfer->completed_at ?? now(),
                                'expiry_date' => null,
                            ]);
                        } else {
                            // Update existing stock
                            $previousDestQty = (float) $destStock->quantity;
                            $destStock->quantity = $previousDestQty + $quantityReceived;
                            $destStock->unit_cost = $sourceStock->unit_cost;
                            $destStock->last_restocked_at = $transfer->completed_at ?? now();
                            $destStock->save();

                            // Create batch for transferred stock
                            StockBatch::create([
                                'business_id' => $business->id,
                                'branch_id' => $toBranch->id,
                                'stock_id' => $destStock->id,
                                'product_id' => $product->id,
                                'batch_number' => 'TRF-' . $transferNumber . '-' . $product->id,
                                'quantity_received' => $quantityReceived,
                                'quantity_remaining' => $quantityReceived,
                                'unit_cost' => $sourceStock->unit_cost,
                                'received_date' => $transfer->completed_at ?? now(),
                                'expiry_date' => null,
                            ]);
                        }

                        // Create stock movement for destination
                        StockMovement::create([
                            'business_id' => $business->id,
                            'branch_id' => $toBranch->id,
                            'product_id' => $product->id,
                            'user_id' => $user->id,
                            'movement_type' => 'transfer_in',
                            'quantity' => $quantityReceived,
                            'previous_quantity' => $destStock->quantity - $quantityReceived,
                            'new_quantity' => (float) $destStock->quantity,
                            'unit_cost' => $sourceStock->unit_cost,
                            'reference_type' => 'App\Models\StockTransfer',
                            'reference_id' => $transfer->id,
                            'notes' => "Transfer from {$fromBranch->name} - {$transferNumber}",
                            'created_at' => $transfer->completed_at ?? now(),
                            'updated_at' => $transfer->completed_at ?? now(),
                        ]);
                    }
                }

                if ($itemsCreated === 0) {
                    DB::rollBack();
                    $this->command->warn("⚠ Transfer #{$i}: No valid items - skipped");
                    continue;
                }

                DB::commit();
                $transferCount++;

                $statusIcon = [
                    'completed' => '✓',
                    'in_transit' => '→',
                    'approved' => '✓',
                    'pending' => '⏳',
                ];

                $icon = $statusIcon[$status] ?? '•';
                $this->command->info("{$icon} Transfer #{$transferCount}: {$transferNumber} - {$fromBranch->name} → {$toBranch->name} ({$status}) - {$itemsCreated} items");

            } catch (\Exception $e) {
                DB::rollBack();
                $this->command->error("✗ Failed transfer #{$i}: " . $e->getMessage());
            }
        }

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  Stock Transfers Seeded Successfully!');
        $this->command->info('========================================');
        $this->command->info("  Total Transfers Created: {$transferCount}");
        $this->command->newLine();
    }

    /**
     * Get weighted random status
     */
    private function getWeightedRandomStatus(array $weights): string
    {
        $totalWeight = array_sum($weights);
        $random = rand(1, $totalWeight);
        $currentWeight = 0;

        foreach ($weights as $status => $weight) {
            $currentWeight += $weight;
            if ($random <= $currentWeight) {
                return $status;
            }
        }

        return 'pending';
    }

    /**
     * Generate transfer number
     */
    private function generateTransferNumber($businessId, $date): string
    {
        $prefix = 'TRF';
        $dateStr = $date->format('Ymd');
        $count = StockTransfer::where('business_id', $businessId)
            ->whereDate('created_at', $date->toDateString())
            ->count();

        return $prefix . '-' . $dateStr . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Deduct quantity from FIFO batches
     */
    private function deductFromBatches($stock, $quantity)
    {
        $remainingToDeduct = $quantity;

        $batches = StockBatch::where('stock_id', $stock->id)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_date', 'asc')
            ->get();

        foreach ($batches as $batch) {
            if ($remainingToDeduct <= 0) break;

            $deductFromThisBatch = min($batch->quantity_remaining, $remainingToDeduct);
            $batch->quantity_remaining = $batch->quantity_remaining - $deductFromThisBatch;
            $batch->save();

            $remainingToDeduct -= $deductFromThisBatch;
        }
    }
}
