<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Ensure KES currency exists (since your seeded data uses KES)
        $this->ensureCurrenciesExist();

        // Step 2: Add columns WITHOUT constraints first
        Schema::table('sale_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_payments', 'currency_id')) {
                $table->unsignedBigInteger('currency_id')->nullable()->after('payment_id');
            }

            if (!Schema::hasColumn('sale_payments', 'exchange_rate')) {
                $table->decimal('exchange_rate', 10, 4)->default(1.0)->after('currency_id');
            }

            if (!Schema::hasColumn('sale_payments', 'amount_in_sale_currency')) {
                $table->decimal('amount_in_sale_currency', 15, 2)->nullable()->after('amount');
            }
        });

        // Step 3: Populate data for existing records
        $this->populateExistingData();

        // Step 4: Set default values for any remaining NULLs
        DB::statement('UPDATE sale_payments SET amount_in_sale_currency = amount WHERE amount_in_sale_currency IS NULL');
        DB::statement('UPDATE sale_payments SET exchange_rate = 1.0 WHERE exchange_rate IS NULL');

        // Step 5: Make currency_id NOT NULL and add constraints
        Schema::table('sale_payments', function (Blueprint $table) {
            // Make NOT NULL using raw SQL
            DB::statement('ALTER TABLE sale_payments MODIFY currency_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE sale_payments MODIFY amount_in_sale_currency DECIMAL(15,2) NOT NULL');

            // Add foreign key
            $table->foreign('currency_id')
                  ->references('id')
                  ->on('currencies')
                  ->onDelete('cascade');

            // Add index
            $table->index(['sale_id', 'currency_id']);
        });
    }

    /**
     * Ensure required currencies exist
     */
    private function ensureCurrenciesExist(): void
    {
        $currencies = [
            ['code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'is_base' => true],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'is_base' => false],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_base' => false],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'is_base' => false],
        ];

        foreach ($currencies as $currencyData) {
            $exists = DB::table('currencies')->where('code', $currencyData['code'])->exists();

            if (!$exists) {
                DB::table('currencies')->insert([
                    'code' => $currencyData['code'],
                    'name' => $currencyData['name'],
                    'symbol' => $currencyData['symbol'],
                    'is_base' => $currencyData['is_base'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Populate currency_id for existing sale_payments
     */
    private function populateExistingData(): void
    {
        // Get KES currency (your seeded data uses KES)
        $kesCurrency = DB::table('currencies')->where('code', 'KES')->first();

        if (!$kesCurrency) {
            throw new \Exception('KES currency not found. Cannot proceed with migration.');
        }

        // Get all sale_payments that need population
        $salePayments = DB::table('sale_payments')->get();

        foreach ($salePayments as $salePayment) {
            // Skip if already populated
            if ($salePayment->currency_id && $salePayment->amount_in_sale_currency) {
                continue;
            }

            $currencyId = $kesCurrency->id;
            $exchangeRate = 1.0;
            $amountInSaleCurrency = $salePayment->amount;

            // Try to get currency from linked payment
            $payment = DB::table('payments')
                ->where('id', $salePayment->payment_id)
                ->first();

            if ($payment) {
                // If payment has currency_id, use it
                if (isset($payment->currency_id) && $payment->currency_id) {
                    $currencyId = $payment->currency_id;
                    $exchangeRate = $payment->exchange_rate ?? 1.0;
                }
                // If payment has currency string, convert it
                elseif (isset($payment->currency) && $payment->currency) {
                    $currency = DB::table('currencies')
                        ->where('code', $payment->currency)
                        ->first();

                    if ($currency) {
                        $currencyId = $currency->id;
                        $exchangeRate = $payment->exchange_rate ?? 1.0;
                    }
                }

                // Get amount_in_base_currency from payment if available
                if (isset($payment->amount_in_base_currency) && $payment->amount_in_base_currency) {
                    $amountInSaleCurrency = $payment->amount_in_base_currency;
                }
            }

            // Try to get currency from linked sale if payment didn't help
            if (!isset($payment) || !$payment) {
                $sale = DB::table('sales')
                    ->where('id', $salePayment->sale_id)
                    ->first();

                if ($sale) {
                    // If sale has currency_id, use it
                    if (isset($sale->currency_id) && $sale->currency_id) {
                        $currencyId = $sale->currency_id;
                        $exchangeRate = $sale->exchange_rate ?? 1.0;
                    }
                    // If sale has currency string, convert it
                    elseif (isset($sale->currency) && $sale->currency) {
                        $currency = DB::table('currencies')
                            ->where('code', $sale->currency)
                            ->first();

                        if ($currency) {
                            $currencyId = $currency->id;
                            $exchangeRate = $sale->exchange_rate ?? 1.0;
                        }
                    }
                }
            }

            // Update the sale_payment record
            DB::table('sale_payments')
                ->where('id', $salePayment->id)
                ->update([
                    'currency_id' => $currencyId,
                    'exchange_rate' => $exchangeRate,
                    'amount_in_sale_currency' => $amountInSaleCurrency ?? $salePayment->amount,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            // Drop index
            if (Schema::hasColumn('sale_payments', 'currency_id')) {
                try {
                    $table->dropIndex(['sale_id', 'currency_id']);
                } catch (\Exception $e) {
                    // Index might not exist
                }
            }

            // Drop foreign key
            try {
                $table->dropForeign(['currency_id']);
            } catch (\Exception $e) {
                // Foreign key might not exist
            }

            // Drop columns
            if (Schema::hasColumn('sale_payments', 'currency_id')) {
                $table->dropColumn('currency_id');
            }
            if (Schema::hasColumn('sale_payments', 'exchange_rate')) {
                $table->dropColumn('exchange_rate');
            }
            if (Schema::hasColumn('sale_payments', 'amount_in_sale_currency')) {
                $table->dropColumn('amount_in_sale_currency');
            }
        });
    }
};
