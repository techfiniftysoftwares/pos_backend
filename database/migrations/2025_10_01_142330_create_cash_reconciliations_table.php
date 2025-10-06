<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Cashier who did reconciliation

            $table->date('reconciliation_date');
            $table->enum('shift_type', ['morning', 'afternoon', 'evening', 'full_day'])->default('full_day');

            // Cash amounts
            $table->decimal('opening_float', 15, 2)->default(0);
            $table->decimal('expected_cash', 15, 2)->default(0);
            $table->decimal('actual_cash', 15, 2)->default(0);
            $table->decimal('variance', 15, 2)->default(0); // actual - expected

            // Cash flow components
            $table->decimal('cash_sales', 15, 2)->default(0);
            $table->decimal('cash_payments_received', 15, 2)->default(0);
            $table->decimal('cash_refunds', 15, 2)->default(0);
            $table->decimal('cash_expenses', 15, 2)->default(0);
            $table->decimal('cash_drops', 15, 2)->default(0); // Cash removed during day

            // Currency
            $table->string('currency', 3)->default('USD');
            $table->json('currency_breakdown')->nullable(); // Denomination count

            // Status
            $table->enum('status', ['pending', 'completed', 'approved', 'disputed'])->default('pending');

            $table->text('notes')->nullable();

            // Approval
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('business_id');
            $table->index('branch_id');
            $table->index('user_id');
            $table->index('reconciliation_date');
            $table->index('status');
            $table->index('currency');

            // Unique constraint with custom short name
            $table->unique(['branch_id', 'reconciliation_date', 'shift_type', 'currency'], 'cash_recon_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_reconciliations');
    }
};
