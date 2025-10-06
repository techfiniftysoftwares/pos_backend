<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('restrict');
            $table->foreignId('user_id')->constrained()->onDelete('restrict'); // Who made the change

            // Movement details
            $table->enum('movement_type', [
                'purchase',      // Stock in from supplier
                'sale',          // Stock out to customer
                'adjustment',    // Manual correction
                'transfer_out',  // Sent to another branch
                'transfer_in',   // Received from another branch
                'return_in',     // Customer return
                'return_out',    // Return to supplier
                'damage',        // Damaged goods
                'expired',       // Expired products
            ]);

            $table->decimal('quantity', 15, 2); // Positive for IN, Negative for OUT
            $table->decimal('previous_quantity', 15, 2);
            $table->decimal('new_quantity', 15, 2);
            $table->decimal('unit_cost', 10, 2)->nullable();

            // Reference to related record (polymorphic)
            $table->string('reference_type')->nullable(); // Sale, Purchase, StockAdjustment, etc.
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('business_id');
            $table->index('branch_id');
            $table->index('product_id');
            $table->index('user_id');
            $table->index('movement_type');
            $table->index(['reference_type', 'reference_id']);
            $table->index('created_at'); // For reporting by date
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
