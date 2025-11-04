<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sale;
use App\Models\Payment;
use App\Models\SalePayment;
use App\Models\PaymentMethod;
use Carbon\Carbon;

class PaymentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        echo "Starting Payment Seeder...\n\n";

        // Create payments for existing sales
        $this->createPaymentsForSales();

        echo "\n✅ Payment seeding completed!\n";
    }

    /**
     * Create payments for existing sales
     */
    private function createPaymentsForSales()
    {
        // Get all completed/pending sales without payments
        $sales = Sale::whereDoesntHave('salePayments')
            ->whereIn('status', ['completed', 'pending'])
            ->orderBy('completed_at')
            ->get();

        if ($sales->isEmpty()) {
            echo "⚠️  No sales found without payments.\n";
            return;
        }

        echo "💰 Creating payments for {$sales->count()} sales...\n\n";

        $progressCount = 0;
        foreach ($sales as $sale) {
            $this->createPaymentForSale($sale);
            $progressCount++;

            if ($progressCount % 10 == 0) {
                echo "   Progress: {$progressCount}/{$sales->count()}\n";
            }
        }

        echo "\n✅ Created payments for {$progressCount} sales\n";
    }

    /**
     * Create payment for a specific sale
     */
    private function createPaymentForSale(Sale $sale)
    {
        // Get payment methods for this business
        $paymentMethods = PaymentMethod::where('business_id', $sale->business_id)
            ->where('is_active', true)
            ->get();

        if ($paymentMethods->isEmpty()) {
            echo "❌ No payment methods found for business {$sale->business_id}\n";
            return;
        }

        // Select payment method based on sale type
        if ($sale->is_credit_sale) {
            // Credit sale - use store credit payment method
            $paymentMethod = $paymentMethods->where('type', 'store_credit')->first()
                ?? $paymentMethods->where('code', 'STORE_CREDIT')->first()
                ?? $paymentMethods->first();
        } else {
            // Regular sale - random selection with realistic distribution
            $paymentMethod = $this->selectRandomPaymentMethod($paymentMethods);
        }

        // Calculate fees
        $feeAmount = $paymentMethod->calculateFee($sale->total_amount);
        $netAmount = $sale->total_amount - $feeAmount;

        // Generate transaction ID for non-cash payments
        $transactionId = null;
        if ($paymentMethod->type !== 'cash' && $paymentMethod->type !== 'store_credit') {
            $transactionId = $this->generateTransactionId($paymentMethod->code);
        }

        // Use sale's completed_at or created_at timestamp (SAME TIME AS SALE)
        $paymentDate = $sale->completed_at ?? $sale->created_at;

        // Create payment record
        $payment = Payment::create([
            'business_id' => $sale->business_id,
            'branch_id' => $sale->branch_id,
            'payment_method_id' => $paymentMethod->id,
            'customer_id' => $sale->customer_id,
            'reference_number' => 'PAY-' . Carbon::parse($paymentDate)->format('Ymd') . '-' . str_pad($sale->id, 5, '0', STR_PAD_LEFT),
            'transaction_id' => $transactionId,
            'amount' => $sale->total_amount,
            'currency' => $sale->currency ?? 'KES',
            'exchange_rate' => $sale->exchange_rate ?? 1,
            'amount_in_base_currency' => $sale->total_in_base_currency ?? $sale->total_amount,
            'fee_amount' => $feeAmount,
            'net_amount' => $netAmount,
            'status' => $sale->is_credit_sale ? 'pending' : 'completed',
            'payment_type' => 'payment',
            'payment_date' => $paymentDate,
            'processed_by' => $sale->user_id,
            'created_at' => $paymentDate, // SAME TIME AS SALE
            'updated_at' => $paymentDate, // SAME TIME AS SALE
        ]);

        // Link payment to sale
        SalePayment::create([
            'sale_id' => $sale->id,
            'payment_id' => $payment->id,
            'amount' => $sale->total_amount,
            'created_at' => $paymentDate, // SAME TIME AS SALE
            'updated_at' => $paymentDate, // SAME TIME AS SALE
        ]);

        echo "✓ Sale #{$sale->sale_number} → {$paymentMethod->name} ({$sale->currency} " . number_format($sale->total_amount, 2) . ")\n";
    }

    /**
     * Select random payment method with realistic distribution
     * Based on your existing payment methods
     */
    private function selectRandomPaymentMethod($paymentMethods)
    {
        // Payment method distribution for Kenya:
        // Cash: 35%
        // M-Pesa: 30%
        // Airtel Money: 10%
        // Card (Visa/Mastercard): 15%
        // Bank Transfer: 5%
        // Other: 5%

        $rand = rand(1, 100);

        if ($rand <= 35) {
            // Cash (35%)
            $method = $paymentMethods->where('type', 'cash')->first()
                ?? $paymentMethods->where('code', 'CASH')->first();
        } elseif ($rand <= 65) {
            // M-Pesa (30%)
            $method = $paymentMethods->where('code', 'MPESA')->first()
                ?? $paymentMethods->where('name', 'M-Pesa')->first();
        } elseif ($rand <= 75) {
            // Airtel Money (10%)
            $method = $paymentMethods->where('code', 'AIRTEL')->first()
                ?? $paymentMethods->where('name', 'Airtel Money')->first();
        } elseif ($rand <= 90) {
            // Card (15%) - Visa or Mastercard
            $cardMethods = $paymentMethods->whereIn('code', ['VISA', 'MASTERCARD']);
            $method = $cardMethods->isNotEmpty() ? $cardMethods->random() : null;

            if (!$method) {
                $method = $paymentMethods->where('type', 'card')->first();
            }
        } elseif ($rand <= 95) {
            // Bank Transfer (5%)
            $method = $paymentMethods->where('code', 'BANK_TRANSFER')->first()
                ?? $paymentMethods->where('type', 'bank_transfer')->first();
        } else {
            // Other methods (5%) - Cheque, PayPal, Gift Card
            $otherMethods = $paymentMethods->whereIn('type', ['cheque', 'digital_wallet', 'gift_card']);
            $method = $otherMethods->isNotEmpty() ? $otherMethods->random() : null;
        }

        // Fallback to any available method
        return $method ?? $paymentMethods->first();
    }

    /**
     * Generate realistic transaction ID
     */
    private function generateTransactionId($code)
    {
        $timestamp = now()->format('YmdHis');
        $random = strtoupper(substr(md5(uniqid()), 0, 8));
        return strtoupper($code) . $timestamp . $random;
    }
}
