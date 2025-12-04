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
        Schema::table('businesses', function (Blueprint $table) {
            // Add base currency (for accounting/reporting)
            $table->foreignId('base_currency_id')
                ->after('status')
                ->nullable()
                ->constrained('currencies')
                ->onDelete('restrict');

            // Add default product currency (for product pricing)
            $table->foreignId('default_product_currency_id')
                ->after('base_currency_id')
                ->nullable()
                ->constrained('currencies')
                ->onDelete('restrict');
        });

        // Set default values for existing businesses
        $baseCurrency = DB::table('currencies')->where('is_base', true)->first();

        if ($baseCurrency) {
            DB::table('businesses')->update([
                'base_currency_id' => $baseCurrency->id,
                'default_product_currency_id' => $baseCurrency->id,
            ]);
        }

        // Make columns non-nullable after setting defaults
        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('base_currency_id')->nullable(false)->change();
            $table->foreignId('default_product_currency_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropForeign(['base_currency_id']);
            $table->dropForeign(['default_product_currency_id']);
            $table->dropColumn(['base_currency_id', 'default_product_currency_id']);
        });
    }
};
