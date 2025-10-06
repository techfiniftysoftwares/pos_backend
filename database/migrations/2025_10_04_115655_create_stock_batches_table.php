<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('restrict');
            $table->foreignId('purchase_item_id')->nullable()->constrained()->onDelete('set null');

            $table->string('batch_number')->unique();
            $table->string('purchase_reference')->nullable(); // PO number for reference

            $table->decimal('quantity_received', 10, 2);
            $table->decimal('quantity_remaining', 10, 2);
            $table->decimal('unit_cost', 15, 2);

            $table->date('received_date');
            $table->date('expiry_date')->nullable(); // For perishable items

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['stock_id', 'received_date']);
            $table->index('quantity_remaining');
            $table->index(['business_id', 'branch_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('stock_batches');
    }
};
