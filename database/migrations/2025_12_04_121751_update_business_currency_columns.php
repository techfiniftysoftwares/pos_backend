<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old columns if they exist
        if (Schema::hasColumn('businesses', 'default_product_currency_id')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->dropForeign(['default_product_currency_id']);
                $table->dropColumn('default_product_currency_id');
            });
        }

        // Keep base_currency_id if it exists, or add it if it doesn't
        if (!Schema::hasColumn('businesses', 'base_currency_id')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->foreignId('base_currency_id')
                    ->after('status')
                    ->nullable()
                    ->constrained('currencies')
                    ->onDelete('restrict');
            });
        }

        // Set default values for existing businesses (if base_currency_id is null)
        $baseCurrency = DB::table('currencies')->where('is_base', true)->first();

        if ($baseCurrency) {
            DB::table('businesses')
                ->whereNull('base_currency_id')
                ->update(['base_currency_id' => $baseCurrency->id]);
        }

        // Make column non-nullable
        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('base_currency_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropForeign(['base_currency_id']);
            $table->dropColumn('base_currency_id');
        });
    }
};
