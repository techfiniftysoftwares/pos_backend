<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Business;
use App\Models\Branch;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('========================================');
        $this->command->info('  Seeding Purchases...');
        $this->command->info('========================================');
        $this->command->newLine();

        $business = Business::first();

        if (!$business) {
            $this->command->error('No business found. Run InitialSetupSeeder first.');
            return;
        }

        $branches = Branch::where('business_id', $business->id)->get();
        $suppliers = Supplier::where('business_id', $business->id)->get();
        $products = Product::where('business_id', $business->id)->get();
        $user = User::where('business_id', $business->id)->first();

        if ($branches->isEmpty() || $suppliers->isEmpty() || $products->isEmpty() || !$user) {
            $this->command->error('Missing required data. Run previous seeders first.');
            return;
        }

        $purchaseCount = 0;
        $numberOfPurchases = 15;

        // Define purchase statuses with weights
        $statusWeights = [
            'received' => 5,           // 50% fully received
            'partially_received' => 2, // 20% partially received
            'ordered' => 2,            // 20% ordered (not yet received)
            'draft' => 1,              // 10% draft
        ];

        for ($i = 1; $i <= $numberOfPurchases; $i++) {
            try {
                DB::beginTransaction();

                // Random date (last 45 days)
                $purchaseDate = Carbon::now()->subDays(rand(0, 45));
                $expectedDeliveryDate = $purchaseDate->copy()->addDays(rand(3, 14));

                // Random supplier and branch
                $supplier = $suppliers->random();
                $branch = $branches->random();

                // Select status
                $status = $this->getWeightedRandomStatus($statusWeights);

                // Select 2-6 random products
                $purchaseProducts = $products->random(rand(2, min(6, $products->count())));

                $subtotal = 0;
                $totalTax = 0;
                $purchaseItemsData = [];

                // Calculate totals
                foreach ($purchaseProducts as $product) {
                    $quantityOrdered = rand(10, 100);
                    $unitCost = $product->cost_price > 0
                        ? $product->cost_price * rand(90, 110) / 100 // Vary cost by ±10%
                        : rand(100, 5000);

                    $taxRate = $product->tax_rate ?? 0;
                    $lineSubtotal = $unitCost * $quantityOrdered;
                    $lineTax = ($lineSubtotal * $taxRate) / 100;
                    $lineTotal = $lineSubtotal + $lineTax;

                    $subtotal += $lineSubtotal;
                    $totalTax += $lineTax;

                    $purchaseItemsData[] = [
                        'product' => $product,
                        'quantity_ordered' => $quantityOrdered,
                        'unit_cost' => $unitCost,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $lineTax,
                        'line_total' => $lineTotal,
                    ];
                }

                $grandTotal = $subtotal + $totalTax;

                // Generate unique purchase number based on purchase date
                $purchaseNumber = $this->generatePurchaseNumber($business->id, $purchaseDate);

                // Create Purchase
                $purchase = Purchase::create([
                    'business_id' => $business->id,
                    'branch_id' => $branch->id,
                    'supplier_id' => $supplier->id,
                    'purchase_number' => $purchaseNumber, // Set explicitly
                    'purchase_date' => $purchaseDate,
                    'expected_delivery_date' => $expectedDeliveryDate,
                    'subtotal' => $subtotal,
                    'tax_amount' => $totalTax,
                    'total_amount' => $grandTotal,
                    'currency' => 'USD',
                    'exchange_rate' => 1.0,
                    'status' => $status,
                    'payment_status' => $status === 'received' ? 'paid' : 'unpaid',
                    'invoice_number' => 'INV-' . strtoupper(uniqid()),
                    'notes' => "Seeded purchase order from {$supplier->name}",
                    'created_by' => $user->id,
                    'created_at' => $purchaseDate,
                    'updated_at' => $purchaseDate,
                ]);

                // Set received date and user for received purchases
                if (in_array($status, ['received', 'partially_received'])) {
                    $receivedDate = $expectedDeliveryDate->copy()->addDays(rand(0, 3));
                    $purchase->update([
                        'received_date' => $receivedDate,
                        'received_by' => $user->id,
                    ]);
                }

                // Create Purchase Items
                foreach ($purchaseItemsData as $itemData) {
                    $product = $itemData['product'];
                    $quantityOrdered = $itemData['quantity_ordered'];

                    // Determine quantity received based on status
                    $quantityReceived = 0;
                    if ($status === 'received') {
                        $quantityReceived = $quantityOrdered; // Fully received
                    } elseif ($status === 'partially_received') {
                        $quantityReceived = rand((int)($quantityOrdered * 0.5), (int)($quantityOrdered * 0.9)); // 50-90% received
                    }

                    // Create Purchase Item
                    $purchaseItem = PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id' => $product->id,
                        'quantity_ordered' => $quantityOrdered,
                        'quantity_received' => $quantityReceived,
                        'unit_cost' => $itemData['unit_cost'],
                        'tax_rate' => $itemData['tax_rate'],
                        'tax_amount' => $itemData['tax_amount'],
                        'line_total' => $itemData['line_total'],
                        'notes' => null,
                    ]);

                    // Process received stock
                    if ($quantityReceived > 0) {
                        // Get or create stock record
                        $stock = Stock::firstOrCreate(
                            [
                                'business_id' => $business->id,
                                'branch_id' => $branch->id,
                                'product_id' => $product->id,
                            ],
                            [
                                'quantity' => 0,
                                'reserved_quantity' => 0,
                                'unit_cost' => $itemData['unit_cost'],
                            ]
                        );

                        $previousQuantity = (float) $stock->quantity;
                        $newQuantity = $previousQuantity + $quantityReceived;

                        // Update stock quantity and cost
                        $stock->update([
                            'quantity' => $newQuantity,
                            'unit_cost' => $itemData['unit_cost'],
                            'last_restocked_at' => $purchase->received_date ?? now(),
                        ]);

                        // Create Stock Batch (FIFO)
                        $batchNumber = 'BATCH-' . $purchase->purchase_number . '-' . $product->id;
                        $stockBatch = StockBatch::create([
                            'business_id' => $business->id,
                            'branch_id' => $branch->id,
                            'stock_id' => $stock->id,
                            'product_id' => $product->id,
                            'batch_number' => $batchNumber,
                            'purchase_item_id' => $purchaseItem->id,
                            'purchase_reference' => $purchase->purchase_number,
                            'quantity_received' => $quantityReceived,
                            'quantity_remaining' => $quantityReceived,
                            'unit_cost' => $itemData['unit_cost'],
                            'received_date' => $purchase->received_date ?? now(),
                            'expiry_date' => null,
                            'notes' => "Purchase: {$purchase->purchase_number}",
                        ]);

                        // Create Stock Movement
                        StockMovement::create([
                            'business_id' => $business->id,
                            'branch_id' => $branch->id,
                            'product_id' => $product->id,
                            'user_id' => $user->id,
                            'movement_type' => 'purchase',
                            'quantity' => $quantityReceived,
                            'previous_quantity' => $previousQuantity,
                            'new_quantity' => $newQuantity,
                            'unit_cost' => $itemData['unit_cost'],
                            'reference_type' => 'App\Models\Purchase',
                            'reference_id' => $purchase->id,
                            'notes' => "Purchase received: {$purchase->purchase_number}. Batch: {$batchNumber}",
                            'created_at' => $purchase->received_date ?? now(),
                            'updated_at' => $purchase->received_date ?? now(),
                        ]);

                        // Update product cost price
                        $product->update(['cost_price' => $itemData['unit_cost']]);
                    }
                }

                DB::commit();
                $purchaseCount++;

                $statusIcons = [
                    'received' => '✓',
                    'partially_received' => '◐',
                    'ordered' => '→',
                    'draft' => '○',
                ];

                $icon = $statusIcons[$status] ?? '•';
                $this->command->info("{$icon} Purchase #{$purchaseCount}: {$purchase->purchase_number} - {$supplier->name} - USD " . number_format($grandTotal, 2) . " ({$status})");

            } catch (\Exception $e) {
                DB::rollBack();
                $this->command->error("✗ Failed purchase #{$i}: " . $e->getMessage());
            }
        }

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  Purchases Seeded Successfully!');
        $this->command->info('========================================');
        $this->command->info("  Total Purchases Created: {$purchaseCount}");
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

        return 'draft';
    }

    /**
     * Generate unique purchase number based on purchase date
     */
    private function generatePurchaseNumber($businessId, $purchaseDate)
    {
        $dateStr = $purchaseDate->format('Ymd');

        // Get count of purchases for this business on this date
        $count = Purchase::where('business_id', $businessId)
            ->whereDate('purchase_date', $purchaseDate->toDateString())
            ->count();

        do {
            $count++;
            $purchaseNumber = 'PO-' . $dateStr . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
        } while (Purchase::where('purchase_number', $purchaseNumber)->exists());

        return $purchaseNumber;
    }
}
