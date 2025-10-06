<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('user_id')->constrained()->onDelete('restrict'); // cashier
            $table->string('sale_number')->unique();
            $table->string('invoice_number')->nullable()->unique();

            // Amounts
            $table->decimal('subtotal', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);

            // Currency
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 10, 4)->default(1.0000);
            $table->decimal('total_in_base_currency', 15, 2);

            // Status
            $table->enum('status', ['pending', 'completed', 'cancelled', 'refunded', 'partially_refunded'])->default('pending');
            $table->enum('payment_status', ['unpaid', 'partial', 'paid', 'refunded'])->default('unpaid');

            // Payment type - CRITICAL for credit sales
            $table->enum('payment_type', ['cash', 'credit', 'mixed'])->default('cash');

            // Credit tracking
            $table->boolean('is_credit_sale')->default(false);
            $table->date('credit_due_date')->nullable();

            // Additional info
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            // Timestamps
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('sale_number');
            $table->index('status');
            $table->index('payment_status');
            $table->index('is_credit_sale');
            $table->index(['business_id', 'branch_id']);
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sales');
    }
};
