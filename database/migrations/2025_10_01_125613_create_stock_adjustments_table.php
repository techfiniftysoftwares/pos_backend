<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('restrict');
            $table->foreignId('adjusted_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');

            $table->enum('adjustment_type', ['increase', 'decrease']);
            $table->decimal('quantity_adjusted', 15, 2);
            $table->decimal('before_quantity', 15, 2);
            $table->decimal('after_quantity', 15, 2);

            $table->enum('reason', [
                'damaged',
                'expired',
                'theft',
                'count_error',
                'lost',
                'found',
                'other'
            ]);

            $table->decimal('cost_impact', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('business_id');
            $table->index('branch_id');
            $table->index('product_id');
            $table->index('adjusted_by');
            $table->index('adjustment_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
