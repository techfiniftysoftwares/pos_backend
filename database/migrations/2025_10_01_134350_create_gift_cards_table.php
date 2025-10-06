<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('set null');
            $table->string('card_number', 20)->unique();
            $table->string('pin')->nullable(); // Hashed
            $table->decimal('initial_amount', 15, 2);
            $table->decimal('current_balance', 15, 2);
            $table->enum('status', ['active', 'inactive', 'expired', 'depleted'])->default('active');
            $table->foreignId('issued_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->timestamps();

            // Indexes
            $table->index('business_id');
            $table->index('customer_id');
            $table->index('card_number');
            $table->index('status');
            $table->index('expires_at');
            $table->index('issued_by');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
    }
};
