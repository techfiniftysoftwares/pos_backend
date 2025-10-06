<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_reconciliation_id')->nullable()->constrained('cash_reconciliations')->onDelete('cascade');
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');

            $table->enum('movement_type', ['cash_in', 'cash_out', 'cash_drop', 'opening_float', 'expense']);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');

            $table->string('reason')->nullable(); // E.g., "Opening Float", "Bank Deposit", "Petty Cash"
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('processed_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('movement_time');

            $table->timestamps();

            // Indexes
            $table->index('cash_reconciliation_id');
            $table->index('business_id');
            $table->index('branch_id');
            $table->index('movement_type');
            $table->index('movement_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
