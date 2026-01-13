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
            // Auto-generated entry reference number
            $table->string('entry_number')->nullable()->after('id');
            $table->unique(['business_id', 'entry_number']);

            // Index for receipt number uniqueness check
            $table->index(['business_id', 'receipt_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('revenue_entries', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'entry_number']);
            $table->dropColumn('entry_number');
            $table->dropIndex(['business_id', 'receipt_number']);
        });
    }
};
