<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Increase exchange_rate precision from DECIMAL(8,4) to DECIMAL(20,10)
            $table->decimal('exchange_rate', 20, 10)->default(1.0000)->change();
        });

        // Also update sale_payments table if it has exchange_rate
        Schema::table('sale_payments', function (Blueprint $table) {
            // Increase exchange_rate precision from DECIMAL(8,4) to DECIMAL(20,10)
            $table->decimal('exchange_rate', 20, 10)->default(1.0000)->change();
        });

        // Also update sales table exchange_rate (sale to base currency rate)
        Schema::table('sales', function (Blueprint $table) {
            // Increase exchange_rate precision from DECIMAL(8,4) to DECIMAL(20,10)
            $table->decimal('exchange_rate', 20, 10)->default(1.0000)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Revert back to original precision
            $table->decimal('exchange_rate', 8, 4)->default(1.0000)->change();
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->decimal('exchange_rate', 8, 4)->default(1.0000)->change();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('exchange_rate', 8, 4)->default(1.0000)->change();
        });
    }
};
