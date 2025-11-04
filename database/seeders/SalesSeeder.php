<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Payment;
use App\Models\SalePayment;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Product;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Business;
use App\Models\Branch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚀 Starting Sales Seeding...');
        $this->command->newLine();

        $business = Business::first();
        $branch = Branch::where('business_id', $business->id)->first();
        $user = User::where('business_id', $business->id)->first();

        if (!$business || !$branch || !$user) {
            $this->command->error('❌ Missing business, branch, or user. Run previous seeders first.');
            return;
        }

        // Get all available data
        $customers = Customer::where('business_id', $business->id)->get();
        $paymentMethods = PaymentMethod::where('business_id', $business->id)
            ->where('is_active', true)
            ->get();

        // Get products that have stock at this branch
        $productsWithStock = Stock::where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('quantity', '>', 5) // Only products with at least 5 units
            ->with('product')
            ->get()
            ->pluck('product')
            ->filter();

        if ($customers->isEmpty() || $paymentMethods->isEmpty() || $productsWithStock->isEmpty()) {
            $this->command->error('❌ Missing customers, payment methods, or products with stock.');
            $this->command->info('Available customers: ' . $customers->count());
            $this->command->info('Available payment methods: ' . $paymentMethods->count());
            $this->command->info('Available products with stock: ' . $productsWithStock->count());
            return;
        }

        $this->command->info('📊 Found:');
        $this->command->info("   - Customers: {$customers->count()}");
        $this->command->info("   - Payment Methods: {$paymentMethods->count()}");
        $this->command->info("   - Products with Stock: {$productsWithStock->count()}");
        $this->command->newLine();

        $salesCount = 0;
        $failedCount = 0;
        $numberOfSales = 100; // Create 100 sales

        // Date distribution: Last 90 days with focus on recent dates
        $today = Carbon::parse('2025-11-04'); // Current date as per your requirement

        $this->command->info('📅 Generating sales from ' . $today->copy()->subDays(90)->format('Y-m-d') . ' to ' . $today->format('Y-m-d'));
        $this->command->newLine();

        $progressBar = $this->command->getOutput()->createProgressBar($numberOfSales);
        $progressBar->start();

        for ($i = 1; $i <= $numberOfSales; $i++) {
            try {
                DB::beginTransaction();

                // Weighted date distribution (more recent sales)
                $daysAgo = $this->getWeightedRandomDays();
                $saleDate = $today->copy()->subDays($daysAgo)->setTime(rand(8, 20), rand(0, 59), rand(0, 59));

                // Random customer (70% existing customers, 30% walk-in)
                $useWalkIn = rand(1, 10) > 7;
                $customer = $useWalkIn
                    ? $customers->where('name', 'Walk-in Customer')->first() ?? $customers->random()
                    : $customers->random();

                // Payment type distribution: 85% cash, 15% credit
                $paymentType = rand(1, 100) > 85 ? 'credit' : 'cash';

                // Select 1-4 random products for this sale
                $numProducts = $this->getWeightedProductCount();
                $availableProducts = $productsWithStock->shuffle();
                $saleProducts = $availableProducts->take(min($numProducts, $availableProducts->count()));

                $subtotal = 0;
                $totalTax = 0;
                $totalDiscount = 0;
                $saleItemsData = [];

                // Calculate totals and prepare sale items
                foreach ($saleProducts as $product) {
                    // Weighted quantity: more 1-2 items, fewer bulk purchases
                    $quantity = $this->getWeightedQuantity();

                    // Get stock for this product at this branch
                    $stock = Stock::where('business_id', $business->id)
                        ->where('branch_id', $branch->id)
                        ->where('product_id', $product->id)
                        ->first();

                    // Skip if not enough stock
                    if (!$stock || $stock->available_quantity < $quantity) {
                        continue;
                    }

                    $unitPrice = $product->selling_price;

                    // 20% chance of discount
                    $itemDiscount = rand(1, 100) <= 20 ? rand(10, min(100, $unitPrice * 0.15)) : 0;

                    $lineSubtotal = ($unitPrice * $quantity) - $itemDiscount;
                    $taxRate = $product->tax_rate ?? 0;
                    $taxAmount = ($lineSubtotal * $taxRate) / 100;
                    $lineTotal = $lineSubtotal + $taxAmount;

                    $subtotal += $lineSubtotal;
                    $totalTax += $taxAmount;
                    $totalDiscount += $itemDiscount;

                    $saleItemsData[] = [
                        'product' => $product,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'discount_amount' => $itemDiscount,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $taxAmount,
                        'line_total' => $lineTotal,
                        'stock' => $stock,
                    ];
                }

                // Skip if no valid items
                if (empty($saleItemsData)) {
                    DB::rollBack();
                    $failedCount++;
                    $progressBar->advance();
                    continue;
                }

                $grandTotal = $subtotal + $totalTax;

                // Generate unique sale number based on the sale date
                $saleNumber = $this->generateUniqueSaleNumber($branch, $saleDate);

                // Create Sale
                $sale = new Sale();
                $sale->business_id = $business->id;
                $sale->branch_id = $branch->id;
                $sale->customer_id = $customer->id;
                $sale->user_id = $user->id;
                $sale->subtotal = $subtotal;
                $sale->tax_amount = $totalTax;
                $sale->discount_amount = $totalDiscount;
                $sale->total_amount = $grandTotal;
                $sale->currency = 'KES';
                $sale->exchange_rate = 1.0;
                $sale->status = 'completed';
                $sale->payment_type = $paymentType;
                $sale->payment_status = $paymentType === 'credit' ? 'unpaid' : 'paid';
                $sale->is_credit_sale = $paymentType === 'credit';
                $sale->notes = 'Seeded sale transaction #' . ($i);
                $sale->completed_at = $saleDate;
                $sale->created_at = $saleDate;
                $sale->updated_at = $saleDate;
                $sale->sale_number = $saleNumber;
                $sale->save();

                // Create Sale Items and Deduct Stock using FIFO
                foreach ($saleItemsData as $itemData) {
                    $product = $itemData['product'];
                    $quantity = $itemData['quantity'];
                    $stock = $itemData['stock'];

                    // FIFO Batch Deduction
                    $previousQuantity = $stock->quantity;
                    $remainingToDeduct = $quantity;
                    $totalCostUsed = 0;

                    $batches = StockBatch::where('stock_id', $stock->id)
                        ->where('quantity_remaining', '>', 0)
                        ->orderBy('received_date', 'asc')
                        ->get();

                    foreach ($batches as $batch) {
                        if ($remainingToDeduct <= 0) break;

                        $deductFromThisBatch = min($batch->quantity_remaining, $remainingToDeduct);
                        $batch->decrement('quantity_remaining', $deductFromThisBatch);
                        $totalCostUsed += $deductFromThisBatch * $batch->unit_cost;
                        $remainingToDeduct -= $deductFromThisBatch;
                    }

                    $averageUnitCost = $quantity > 0 ? $totalCostUsed / $quantity : 0;

                    // Update stock quantity
                    $newQuantity = $previousQuantity - $quantity;
                    $stock->update(['quantity' => $newQuantity]);

                    // Create Sale Item
                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_price' => $itemData['unit_price'],
                        'unit_cost' => $averageUnitCost,
                        'tax_rate' => $itemData['tax_rate'],
                        'tax_amount' => $itemData['tax_amount'],
                        'discount_amount' => $itemData['discount_amount'],
                        'line_total' => $itemData['line_total'],
                    ]);

                    // Create Stock Movement
                    StockMovement::create([
                        'business_id' => $business->id,
                        'branch_id' => $branch->id,
                        'product_id' => $product->id,
                        'user_id' => $user->id,
                        'movement_type' => 'sale',
                        'quantity' => -$quantity,
                        'previous_quantity' => $previousQuantity,
                        'new_quantity' => $newQuantity,
                        'unit_cost' => $averageUnitCost,
                        'reference_type' => 'App\Models\Sale',
                        'reference_id' => $sale->id,
                        'notes' => "Sale: {$sale->sale_number}",
                        'created_at' => $saleDate,
                        'updated_at' => $saleDate,
                    ]);
                }

                // Create Payments (if not credit)
                if ($paymentType !== 'credit') {
                    // 80% single payment, 20% split payment
                    $numPayments = rand(1, 100) > 80 ? 2 : 1;
                    $remainingAmount = $grandTotal;

                    for ($p = 0; $p < $numPayments; $p++) {
                        $paymentMethod = $paymentMethods->random();

                        // Last payment gets remaining amount, others get random split
                        if ($p === $numPayments - 1) {
                            $paymentAmount = $remainingAmount;
                        } else {
                            $splitPercentage = rand(40, 70) / 100;
                            $paymentAmount = round($remainingAmount * $splitPercentage, 2);
                        }

                        $payment = Payment::create([
                            'business_id' => $business->id,
                            'branch_id' => $branch->id,
                            'payment_method_id' => $paymentMethod->id,
                            'customer_id' => $customer->id,
                            'amount' => $paymentAmount,
                            'currency' => 'KES',
                            'exchange_rate' => 1.0,
                            'status' => 'completed',
                            'payment_type' => 'payment',
                            'transaction_id' => $paymentMethod->requires_reference ? 'TXN' . strtoupper(uniqid()) : null,
                            'payment_date' => $saleDate,
                            'processed_by' => $user->id,
                            'created_at' => $saleDate,
                            'updated_at' => $saleDate,
                        ]);

                        // Link Payment to Sale
                        SalePayment::create([
                            'sale_id' => $sale->id,
                            'payment_id' => $payment->id,
                            'amount' => $paymentAmount,
                        ]);

                        $remainingAmount -= $paymentAmount;
                    }
                }

                DB::commit();
                $salesCount++;
                $progressBar->advance();

            } catch (\Exception $e) {
                DB::rollBack();
                $failedCount++;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->command->newLine(2);

        // Summary
        $this->command->info('========================================');
        $this->command->info('  ✅ Sales Seeding Complete!');
        $this->command->info('========================================');
        $this->command->info("  Total Sales Created: {$salesCount}");
        $this->command->info("  Failed Attempts: {$failedCount}");
        $this->command->info("  Success Rate: " . round(($salesCount / $numberOfSales) * 100, 2) . "%");
        $this->command->newLine();

        // Show date distribution
        $this->showDateDistribution($today);
    }

    /**
     * Generate unique sale number based on sale date
     */
    private function generateUniqueSaleNumber($branch, $saleDate)
    {
        $prefix = 'SALE-' . strtoupper($branch->code);
        $dateStr = $saleDate->format('Ymd');

        // Get count of sales for this branch on this date
        $count = Sale::where('branch_id', $branch->id)
            ->whereDate('created_at', $saleDate->toDateString())
            ->count();

        do {
            $count++;
            $saleNumber = $prefix . '-' . $dateStr . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
        } while (Sale::where('sale_number', $saleNumber)->exists());

        return $saleNumber;
    }

    /**
     * Get weighted random days (more recent = higher probability)
     */
    private function getWeightedRandomDays()
    {
        $rand = rand(1, 100);

        if ($rand <= 30) return rand(0, 7);      // 30% - Last week
        if ($rand <= 50) return rand(8, 14);     // 20% - 1-2 weeks ago
        if ($rand <= 70) return rand(15, 30);    // 20% - 2-4 weeks ago
        if ($rand <= 85) return rand(31, 60);    // 15% - 1-2 months ago
        return rand(61, 90);                      // 15% - 2-3 months ago
    }

    /**
     * Get weighted product count (realistic cart sizes)
     */
    private function getWeightedProductCount()
    {
        $rand = rand(1, 100);

        if ($rand <= 40) return 1;  // 40% - Single item
        if ($rand <= 70) return 2;  // 30% - Two items
        if ($rand <= 90) return 3;  // 20% - Three items
        return 4;                    // 10% - Four items
    }

    /**
     * Get weighted quantity (realistic purchase amounts)
     */
    private function getWeightedQuantity()
    {
        $rand = rand(1, 100);

        if ($rand <= 50) return 1;      // 50% - Single unit
        if ($rand <= 75) return 2;      // 25% - Two units
        if ($rand <= 90) return 3;      // 15% - Three units
        return rand(4, 5);               // 10% - 4-5 units
    }

    /**
     * Show date distribution of created sales
     */
    private function showDateDistribution($today)
    {
        $this->command->info('📊 Sales Distribution:');
        $this->command->newLine();

        $ranges = [
            ['name' => 'Today', 'days' => 0],
            ['name' => 'Last 7 Days', 'days' => 7],
            ['name' => 'Last 30 Days', 'days' => 30],
            ['name' => 'Last 60 Days', 'days' => 60],
            ['name' => 'Last 90 Days', 'days' => 90],
        ];

        foreach ($ranges as $range) {
            $startDate = $today->copy()->subDays($range['days']);
            $count = Sale::whereDate('completed_at', '>=', $startDate->toDateString())
                ->whereDate('completed_at', '<=', $today->toDateString())
                ->count();

            $totalAmount = Sale::whereDate('completed_at', '>=', $startDate->toDateString())
                ->whereDate('completed_at', '<=', $today->toDateString())
                ->sum('total_amount');

            $this->command->info("  {$range['name']}: {$count} sales (KES " . number_format($totalAmount, 2) . ")");
        }

        $this->command->newLine();
    }
}
