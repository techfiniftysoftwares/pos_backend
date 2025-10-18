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
        $this->command->info('Seeding sales...');

        $business = Business::first();
        $branch = Branch::where('business_id', $business->id)->first();
        $user = User::where('business_id', $business->id)->first();

        if (!$business || !$branch || !$user) {
            $this->command->error('Missing business, branch, or user. Run previous seeders first.');
            return;
        }

        // Get all available data
        $customers = Customer::where('business_id', $business->id)->get();
        $paymentMethods = PaymentMethod::where('business_id', $business->id)->where('is_active', true)->get();

        // Get products that have stock at this branch
        $productsWithStock = Stock::where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('quantity', '>', 0)
            ->with('product')
            ->get()
            ->pluck('product')
            ->filter(); // Remove nulls

        if ($customers->isEmpty() || $paymentMethods->isEmpty() || $productsWithStock->isEmpty()) {
            $this->command->error('Missing customers, payment methods, or products with stock.');
            return;
        }

        $salesCount = 0;
        $numberOfSales = 30; // Create 30 sales

        for ($i = 1; $i <= $numberOfSales; $i++) {
            try {
                DB::beginTransaction();

                // Random date in the last 30 days
                $saleDate = Carbon::now()->subDays(rand(0, 30))->setTime(rand(8, 20), rand(0, 59));

                // Random customer (or walk-in)
                $customer = rand(1, 10) > 3 ? $customers->random() : $customers->where('name', 'Walk-in Customer')->first();

                // Random payment type
                $paymentType = rand(1, 10) > 8 ? 'credit' : 'cash';

                // Select 1-5 random products for this sale
                $saleProducts = $productsWithStock->random(rand(1, min(5, $productsWithStock->count())));

                $subtotal = 0;
                $totalTax = 0;
                $totalDiscount = 0;
                $saleItemsData = [];

                // Calculate totals and prepare sale items
                foreach ($saleProducts as $product) {
                    $quantity = rand(1, 3); // Reduce to 1-3 to avoid running out of stock

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
                    $itemDiscount = rand(0, 10) > 7 ? rand(50, 500) : 0; // Random discount sometimes

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
                $sale->notes = 'Seeded sale transaction';
                $sale->completed_at = $saleDate;
                $sale->created_at = $saleDate;
                $sale->updated_at = $saleDate;
                $sale->sale_number = $saleNumber; // Set custom sale number
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
                    // Randomly choose 1 or 2 payment methods
                    $numPayments = rand(1, 2);
                    $remainingAmount = $grandTotal;

                    for ($p = 0; $p < $numPayments; $p++) {
                        $paymentMethod = $paymentMethods->random();

                        // Last payment gets remaining amount, others get random split
                        if ($p === $numPayments - 1) {
                            $paymentAmount = $remainingAmount;
                        } else {
                            $maxSplit = $remainingAmount > 2000 ? $remainingAmount * 0.7 : $remainingAmount - 100;
                            $paymentAmount = rand(100, $maxSplit);
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
                $this->command->info("✓ Created sale #{$salesCount}: {$sale->sale_number} - KES " . number_format($grandTotal, 2));

            } catch (\Exception $e) {
                DB::rollBack();
                $this->command->error("✗ Failed to create sale #{$i}: " . $e->getMessage());
            }
        }

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  Sales Seeding Complete!');
        $this->command->info('========================================');
        $this->command->info("  Total Sales Created: {$salesCount}");
        $this->command->newLine();
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
}
