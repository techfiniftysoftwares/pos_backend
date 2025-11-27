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
        Schema::table('sales', function (Blueprint $table) {
            // Add currency_id as foreign key
            $table->foreignId('currency_id')
                  ->nullable()
                  ->after('total_amount')
                  ->constrained('currencies')
                  ->onDelete('set null');

            // Optional: You can keep the old 'currency' string column for backward compatibility
            // or drop it if you want to fully migrate
            // $table->dropColumn('currency'); // Uncomment to remove old column

            // Optional: Keep exchange_rate for backward compatibility or reporting
            // The new approach uses per-payment exchange rates in sale_payments table
            // $table->dropColumn('exchange_rate'); // Uncomment if you want to remove it
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Drop foreign key and column
            $table->dropForeign(['currency_id']);
            $table->dropColumn('currency_id');

            // If you dropped these columns, restore them:
            // $table->string('currency', 3)->nullable();
            // $table->decimal('exchange_rate', 10, 4)->nullable();
        });
    }
};
