<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('payment_method_id')->constrained('payment_methods')->onDelete('restrict');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('set null');

            // Payment identification
            $table->string('reference_number')->unique();
            $table->string('transaction_id')->nullable()->index(); // External transaction ID

            // Amount details
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 10, 4)->default(1.0000);
            $table->decimal('amount_in_base_currency', 15, 2);
            $table->decimal('fee_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);

            // Payment status and type
            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled', 'refunded'])->default('pending');
            $table->enum('payment_type', ['payment', 'refund', 'adjustment'])->default('payment');

            // Reference to related entity (polymorphic)
            $table->string('reference_type')->nullable(); // Sale, Invoice, etc.
            $table->unsignedBigInteger('reference_id')->nullable();

            // Timestamps
            $table->timestamp('payment_date');
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->onDelete('set null');

            // Failure tracking
            $table->text('failure_reason')->nullable();

            // Additional info
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable(); // Store additional data like card last 4 digits, etc.

            $table->foreignId('processed_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('business_id');
            $table->index('branch_id');
            $table->index('payment_method_id');
            $table->index('customer_id');
            $table->index('reference_number');
            $table->index('status');
            $table->index('payment_type');
            $table->index(['reference_type', 'reference_id']);
            $table->index('payment_date');
            $table->index('reconciled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
