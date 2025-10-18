<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\Business;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Supplier;
use Carbon\Carbon;

class ProductStockSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding products with stock...');

        $business = Business::first();
        $branch = Branch::where('business_id', $business->id)->first();

        if (!$business || !$branch) {
            $this->command->error('No business or branch found. Please run InitialSetupSeeder first.');
            return;
        }

        // Get existing data
        $mobileCategory = Category::where('name', 'mobile phones')->first();
        $cerealsCategory = Category::where('name', 'cereals')->first();
        $kgUnit = Unit::where('symbol', 'kg')->first();
        $gramUnit = Unit::where('symbol', 'g')->first();
        $boxUnit = Unit::where('symbol', 'box')->first();
        $supplier = Supplier::first();

        if (!$mobileCategory || !$cerealsCategory || !$kgUnit || !$supplier) {
            $this->command->error('Missing required categories, units, or suppliers.');
            return;
        }

        $products = [
            // Mobile Phones (Category 1)
            [
                'name' => 'Samsung Galaxy S23',
                'category_id' => $mobileCategory->id,
                'unit_id' => $boxUnit->id,
                'sku' => 'SAM-S23-BLK',
                'barcode' => '8806094891234',
                'cost_price' => 65000,
                'selling_price' => 79000,
                'minimum_stock_level' => 5,
                'tax_rate' => 16,
                'stock_quantity' => 15,
                'unit_cost' => 65000,
            ],
            [
                'name' => 'iPhone 14 Pro',
                'category_id' => $mobileCategory->id,
                'unit_id' => $boxUnit->id,
                'sku' => 'APL-14P-BLU',
                'barcode' => '0194253123456',
                'cost_price' => 120000,
                'selling_price' => 145000,
                'minimum_stock_level' => 3,
                'tax_rate' => 16,
                'stock_quantity' => 8,
                'unit_cost' => 120000,
            ],
            [
                'name' => 'Xiaomi Redmi Note 12',
                'category_id' => $mobileCategory->id,
                'unit_id' => $boxUnit->id,
                'sku' => 'XIA-RN12-BLK',
                'barcode' => '6934177734567',
                'cost_price' => 18000,
                'selling_price' => 23000,
                'minimum_stock_level' => 10,
                'tax_rate' => 16,
                'stock_quantity' => 25,
                'unit_cost' => 18000,
            ],
            [
                'name' => 'Tecno Spark 10',
                'category_id' => $mobileCategory->id,
                'unit_id' => $boxUnit->id,
                'sku' => 'TEC-SP10-WHT',
                'barcode' => '6943295678901',
                'cost_price' => 12000,
                'selling_price' => 15500,
                'minimum_stock_level' => 15,
                'tax_rate' => 16,
                'stock_quantity' => 30,
                'unit_cost' => 12000,
            ],
            [
                'name' => 'Samsung Galaxy A54',
                'category_id' => $mobileCategory->id,
                'unit_id' => $boxUnit->id,
                'sku' => 'SAM-A54-GRN',
                'barcode' => '8806094567890',
                'cost_price' => 32000,
                'selling_price' => 39000,
                'minimum_stock_level' => 8,
                'tax_rate' => 16,
                'stock_quantity' => 20,
                'unit_cost' => 32000,
            ],

            // Cereals & Food (Category 2)
            [
                'name' => 'White Maize - 1KG',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $kgUnit->id,
                'sku' => 'CER-MAZ-WHT-1KG',
                'barcode' => '6001234567890',
                'cost_price' => 80,
                'selling_price' => 120,
                'minimum_stock_level' => 100,
                'tax_rate' => 0,
                'stock_quantity' => 500,
                'unit_cost' => 80,
            ],
            [
                'name' => 'Yellow Maize - 2KG',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $kgUnit->id,
                'sku' => 'CER-MAZ-YEL-2KG',
                'barcode' => '6001234567891',
                'cost_price' => 150,
                'selling_price' => 220,
                'minimum_stock_level' => 80,
                'tax_rate' => 0,
                'stock_quantity' => 400,
                'unit_cost' => 150,
            ],
            [
                'name' => 'Rice - Basmati 1KG',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $kgUnit->id,
                'sku' => 'CER-RIC-BAS-1KG',
                'barcode' => '6001234567892',
                'cost_price' => 180,
                'selling_price' => 250,
                'minimum_stock_level' => 100,
                'tax_rate' => 0,
                'stock_quantity' => 350,
                'unit_cost' => 180,
            ],
            [
                'name' => 'Rice - Pishori 2KG',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $kgUnit->id,
                'sku' => 'CER-RIC-PIS-2KG',
                'barcode' => '6001234567893',
                'cost_price' => 320,
                'selling_price' => 450,
                'minimum_stock_level' => 60,
                'tax_rate' => 0,
                'stock_quantity' => 280,
                'unit_cost' => 320,
            ],
            [
                'name' => 'Wheat Flour - 2KG',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $kgUnit->id,
                'sku' => 'CER-WHT-FLR-2KG',
                'barcode' => '6001234567894',
                'cost_price' => 140,
                'selling_price' => 200,
                'minimum_stock_level' => 120,
                'tax_rate' => 0,
                'stock_quantity' => 600,
                'unit_cost' => 140,
            ],
            [
                'name' => 'Beans - Red 1KG',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $kgUnit->id,
                'sku' => 'CER-BEN-RED-1KG',
                'barcode' => '6001234567895',
                'cost_price' => 120,
                'selling_price' => 180,
                'minimum_stock_level' => 80,
                'tax_rate' => 0,
                'stock_quantity' => 320,
                'unit_cost' => 120,
            ],
            [
                'name' => 'Beans - White 1KG',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $kgUnit->id,
                'sku' => 'CER-BEN-WHT-1KG',
                'barcode' => '6001234567896',
                'cost_price' => 110,
                'selling_price' => 170,
                'minimum_stock_level' => 80,
                'tax_rate' => 0,
                'stock_quantity' => 300,
                'unit_cost' => 110,
            ],
            [
                'name' => 'Green Grams - 500G',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $gramUnit->id,
                'sku' => 'CER-GRM-GRN-500G',
                'barcode' => '6001234567897',
                'cost_price' => 90,
                'selling_price' => 140,
                'minimum_stock_level' => 100,
                'tax_rate' => 0,
                'stock_quantity' => 450,
                'unit_cost' => 90,
            ],
            [
                'name' => 'Lentils - 1KG',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $kgUnit->id,
                'sku' => 'CER-LEN-1KG',
                'barcode' => '6001234567898',
                'cost_price' => 150,
                'selling_price' => 220,
                'minimum_stock_level' => 60,
                'tax_rate' => 0,
                'stock_quantity' => 250,
                'unit_cost' => 150,
            ],
            [
                'name' => 'Millet - 1KG',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $kgUnit->id,
                'sku' => 'CER-MIL-1KG',
                'barcode' => '6001234567899',
                'cost_price' => 100,
                'selling_price' => 150,
                'minimum_stock_level' => 70,
                'tax_rate' => 0,
                'stock_quantity' => 280,
                'unit_cost' => 100,
            ],
            [
                'name' => 'Sorghum - 1KG',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $kgUnit->id,
                'sku' => 'CER-SOR-1KG',
                'barcode' => '6001234567800',
                'cost_price' => 95,
                'selling_price' => 145,
                'minimum_stock_level' => 70,
                'tax_rate' => 0,
                'stock_quantity' => 270,
                'unit_cost' => 95,
            ],
            [
                'name' => 'Oats - 500G',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $gramUnit->id,
                'sku' => 'CER-OAT-500G',
                'barcode' => '6001234567801',
                'cost_price' => 180,
                'selling_price' => 260,
                'minimum_stock_level' => 50,
                'tax_rate' => 0,
                'stock_quantity' => 200,
                'unit_cost' => 180,
            ],
            [
                'name' => 'Barley - 1KG',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $kgUnit->id,
                'sku' => 'CER-BAR-1KG',
                'barcode' => '6001234567802',
                'cost_price' => 130,
                'selling_price' => 190,
                'minimum_stock_level' => 60,
                'tax_rate' => 0,
                'stock_quantity' => 240,
                'unit_cost' => 130,
            ],
            [
                'name' => 'Quinoa - 500G',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $gramUnit->id,
                'sku' => 'CER-QUI-500G',
                'barcode' => '6001234567803',
                'cost_price' => 450,
                'selling_price' => 650,
                'minimum_stock_level' => 30,
                'tax_rate' => 0,
                'stock_quantity' => 120,
                'unit_cost' => 450,
            ],
            [
                'name' => 'Corn Flour - 1KG',
                'category_id' => $cerealsCategory->id,
                'unit_id' => $kgUnit->id,
                'sku' => 'CER-CRN-FLR-1KG',
                'barcode' => '6001234567804',
                'cost_price' => 85,
                'selling_price' => 130,
                'minimum_stock_level' => 100,
                'tax_rate' => 0,
                'stock_quantity' => 500,
                'unit_cost' => 85,
            ],
        ];

        $createdCount = 0;
        $stockCreatedCount = 0;

        foreach ($products as $productData) {
            // Extract stock data
            $stockQuantity = $productData['stock_quantity'];
            $unitCost = $productData['unit_cost'];
            unset($productData['stock_quantity'], $productData['unit_cost']);

            // Add common fields
            $productData['business_id'] = $business->id;
            $productData['supplier_id'] = $supplier->id;
            $productData['track_inventory'] = true;
            $productData['allow_negative_stock'] = false;
            $productData['is_active'] = true;

            // Check if product already exists by SKU
            $product = Product::where('sku', $productData['sku'])->first();

            if ($product) {
                $this->command->warn("⚠ Product already exists: {$product->name} - Skipping");
                continue; // Skip to next product
            }

            // Create product
            $product = Product::create($productData);
            $createdCount++;
            $this->command->info("✓ Created product: {$product->name}");

            // Check if stock already exists for this product at this branch
            $existingStock = Stock::where('business_id', $business->id)
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->first();

            if ($existingStock) {
                $this->command->warn("  ⚠ Stock already exists for this product - Skipping stock creation");
                continue;
            }

            // Create stock for this product
            $stock = Stock::create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'quantity' => $stockQuantity,
                'reserved_quantity' => 0,
                'unit_cost' => $unitCost,
                'last_restocked_at' => Carbon::now()->subDays(rand(1, 30)),
            ]);
            $stockCreatedCount++;
            $this->command->info("  ↳ Created stock: {$stockQuantity} units at {$branch->name}");

            // Create stock batch (for FIFO)
StockBatch::create([
    'business_id' => $business->id,
    'branch_id' => $branch->id,
    'stock_id' => $stock->id,
    'product_id' => $product->id,  // ← ADD THIS LINE
    'batch_number' => 'BATCH-' . strtoupper(uniqid()),
    'quantity_received' => $stockQuantity,
    'quantity_remaining' => $stockQuantity,
    'unit_cost' => $unitCost,
    'received_date' => Carbon::now()->subDays(rand(1, 30)),
    'expiry_date' => null,
]);
            $this->command->info("  ↳ Created stock batch for FIFO tracking");
        }

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  Seeding Complete!');
        $this->command->info('========================================');
        $this->command->info("  Products Created: {$createdCount}");
        $this->command->info("  Stock Records Created: {$stockCreatedCount}");
        $this->command->info("  Stock Batches Created: {$stockCreatedCount}");
        $this->command->newLine();
    }
}