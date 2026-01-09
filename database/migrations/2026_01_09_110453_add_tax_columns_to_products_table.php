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
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('tax_id')->nullable()->after('category_id')->constrained('taxes')->nullOnDelete();
            $table->boolean('tax_inclusive')->default(false)->after('tax_id');
            // We'll keep tax_rate for now as fallback or until fully migrated data, but make it nullable
            $table->decimal('tax_rate', 5, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['tax_id']);
            $table->dropColumn(['tax_id', 'tax_inclusive']);
            $table->decimal('tax_rate', 5, 2)->nullable(false)->change();
        });
    }
};
