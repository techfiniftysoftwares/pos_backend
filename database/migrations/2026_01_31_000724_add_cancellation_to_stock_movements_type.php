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
        // Modify the enum to include 'cancellation'
        DB::statement("ALTER TABLE stock_movements MODIFY COLUMN movement_type ENUM(
            'purchase',
            'sale',
            'adjustment',
            'transfer_out',
            'transfer_in',
            'return_in',
            'return_out',
            'damage',
            'expired',
            'cancellation'
        ) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'cancellation' from the enum
        DB::statement("ALTER TABLE stock_movements MODIFY COLUMN movement_type ENUM(
            'purchase',
            'sale',
            'adjustment',
            'transfer_out',
            'transfer_in',
            'return_in',
            'return_out',
            'damage',
            'expired'
        ) NOT NULL");
    }
};
