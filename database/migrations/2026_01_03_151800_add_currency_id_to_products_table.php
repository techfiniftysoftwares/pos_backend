<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     * Adds currency_id to products table for multi-currency product pricing.
     */
    public function up(): void
    {
        // First add the column as nullable
        if (!Schema::hasColumn('products', 'currency_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('currency_id')
                    ->nullable()
                    ->after('supplier_id')
                    ->constrained('currencies')
                    ->onDelete('restrict');
            });
        }

        // Backfill existing products with base currency
        $baseCurrency = DB::table('currencies')->where('is_base', true)->first();
        if ($baseCurrency) {
            DB::table('products')->whereNull('currency_id')->update(['currency_id' => $baseCurrency->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
            $table->dropColumn('currency_id');
        });
    }
};
