<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Business;
use App\Models\Branch;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\Product;
use Carbon\Carbon;

class AdditionalBranchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('========================================');
        $this->command->info('  Seeding Additional Branch...');
        $this->command->info('========================================');
        $this->command->newLine();

        $business = Business::first();

        if (!$business) {
            $this->command->error('No business found. Run InitialSetupSeeder first.');
            return;
        }

        // Check if second branch already exists
        $existingBranch = Branch::where('business_id', $business->id)
            ->where('code', 'WH001')
            ->first();

        if ($existingBranch) {
            $this->command->warn('⚠ Warehouse branch already exists. Skipping...');
            return;
        }

        // Create Warehouse Branch in DRC
        $warehouseBranch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Warehouse Branch - Goma',
            'code' => 'WH001',
            'phone' => '+243997000020',
            'address' => 'Avenue de la Paix, Goma, North Kivu, DRC',
            'is_active' => true,
            'is_main_branch' => false,
        ]);

        $this->command->info("✓ Created branch: {$warehouseBranch->name} ({$warehouseBranch->code})");

        // Create initial stock records for all products at the new branch
        $products = Product::where('business_id', $business->id)->get();
        $stockCount = 0;
        $batchCount = 0;

        foreach ($products as $product) {
            // Give warehouse branch more stock than main branch (it's a warehouse!)
            $initialQuantity = rand(50, 200);
            $unitCost = $product->cost_price ?? rand(100, 5000);

            // Create stock record
            $stock = Stock::create([
                'business_id' => $business->id,
                'branch_id' => $warehouseBranch->id,
                'product_id' => $product->id,
                'quantity' => $initialQuantity,
                'reserved_quantity' => 0,
                'unit_cost' => $unitCost,
                'last_restocked_at' => Carbon::now()->subDays(rand(1, 15)),
            ]);

            // Create stock batch for FIFO tracking
            StockBatch::create([
                'business_id' => $business->id,
                'branch_id' => $warehouseBranch->id,
                'stock_id' => $stock->id,
                'product_id' => $product->id,
                'batch_number' => 'BATCH-WH-' . strtoupper(uniqid()),
                'quantity_received' => $initialQuantity,
                'quantity_remaining' => $initialQuantity,
                'unit_cost' => $unitCost,
                'received_date' => Carbon::now()->subDays(rand(1, 15)),
                'expiry_date' => null,
            ]);

            $stockCount++;
            $batchCount++;
            $this->command->info("  ✓ Created stock: {$product->name} - Qty: {$initialQuantity}");
        }

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  Additional Branch Seeded Successfully!');
        $this->command->info('========================================');
        $this->command->info("  Branch Created: {$warehouseBranch->name}");
        $this->command->info("  Stock Records Created: {$stockCount}");
        $this->command->info("  Stock Batches Created: {$batchCount}");
        $this->command->newLine();
    }
}
