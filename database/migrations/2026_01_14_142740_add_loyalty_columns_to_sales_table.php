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
        Schema::table('sales', function (Blueprint $table) {
            $table->integer('loyalty_points_used')->default(0)->after('notes');
            $table->decimal('loyalty_points_discount', 15, 2)->default(0)->after('loyalty_points_used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['loyalty_points_used', 'loyalty_points_discount']);
        });
    }
};
