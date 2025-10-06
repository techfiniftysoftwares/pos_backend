<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->enum('transaction_type', ['earned', 'redeemed', 'expired', 'adjustment']);
            $table->integer('points'); // Positive for earned, negative for redeemed
            $table->integer('previous_balance')->default(0);
            $table->integer('new_balance')->default(0);
            $table->string('reference_type')->nullable(); // 'sale', 'promotion', etc.
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('processed_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('customer_id');
            $table->index('transaction_type');
            $table->index(['reference_type', 'reference_id']);
            $table->index('expires_at');
            $table->index('processed_by');
            $table->index('branch_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_points');
    }
};
