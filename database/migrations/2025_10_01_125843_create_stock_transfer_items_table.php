<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('restrict');

            $table->decimal('quantity_requested', 15, 2);
            $table->decimal('quantity_sent', 15, 2)->default(0);
            $table->decimal('quantity_received', 15, 2)->default(0);
            $table->decimal('unit_cost', 10, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('stock_transfer_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
