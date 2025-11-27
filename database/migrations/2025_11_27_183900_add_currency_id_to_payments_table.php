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
            // Add currency_id as foreign key
            $table->foreignId('currency_id')
                  ->nullable()
                  ->after('amount')
                  ->constrained('currencies')
                  ->onDelete('set null');

            // Optional: Keep the old 'currency' string column for backward compatibility
            // or drop it if you want to fully migrate
            // $table->dropColumn('currency'); // Uncomment to remove old column

            // Note: exchange_rate and amount_in_base_currency should already exist
            // If they don't exist, uncomment these:
            // $table->decimal('exchange_rate', 10, 4)->default(1.0)->after('currency_id');
            // $table->decimal('amount_in_base_currency', 15, 2)->nullable()->after('exchange_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Drop foreign key and column
            $table->dropForeign(['currency_id']);
            $table->dropColumn('currency_id');

            // If you dropped the currency column, restore it:
            // $table->string('currency', 3)->nullable();
        });
    }
};
