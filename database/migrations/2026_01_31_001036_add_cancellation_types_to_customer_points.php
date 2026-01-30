<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify the enum to include 'cancelled' and 'restored'
        DB::statement("ALTER TABLE customer_points MODIFY COLUMN transaction_type ENUM(
            'earned',
            'redeemed',
            'expired',
            'adjustment',
            'cancelled',
            'restored'
        ) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'cancelled' and 'restored' from the enum
        DB::statement("ALTER TABLE customer_points MODIFY COLUMN transaction_type ENUM(
            'earned',
            'redeemed',
            'expired',
            'adjustment'
        ) NOT NULL");
    }
};
