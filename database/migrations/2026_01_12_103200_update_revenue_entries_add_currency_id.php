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
        Schema::table('revenue_entries', function (Blueprint $table) {
            // Add currency_id FK after branch_id
            $table->foreignId('currency_id')->nullable()->after('amount')->constrained('currencies')->onDelete('set null');
        });

        // Drop old currency string column
        Schema::table('revenue_entries', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('revenue_entries', function (Blueprint $table) {
            // Re-add old currency column
            $table->string('currency', 3)->default('KES')->after('amount');
        });

        Schema::table('revenue_entries', function (Blueprint $table) {
            // Drop new column
            $table->dropForeign(['currency_id']);
            $table->dropColumn('currency_id');
        });
    }
};
