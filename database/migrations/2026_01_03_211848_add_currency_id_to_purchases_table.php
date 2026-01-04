<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('total_amount')->constrained()->onDelete('restrict');
        });

        // Populate currency_id based on currency code
        $currencies = \Illuminate\Support\Facades\DB::table('currencies')->get();
        foreach ($currencies as $currency) {
            \Illuminate\Support\Facades\DB::table('purchases')
                ->where('currency', $currency->code)
                ->update(['currency_id' => $currency->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
            $table->dropColumn('currency_id');
        });
    }
};
