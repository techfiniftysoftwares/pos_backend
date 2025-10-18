<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('revenue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('revenue_stream_id')->constrained()->onDelete('restrict');

            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('KES');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);
            $table->decimal('amount_in_base_currency', 15, 2);

            $table->date('entry_date');
            $table->string('receipt_number')->nullable();
            $table->string('receipt_attachment')->nullable(); // File path
            $table->text('notes')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->foreignId('recorded_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('restrict');
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['business_id', 'branch_id', 'entry_date']);
            $table->index(['revenue_stream_id', 'status']);
            $table->index('entry_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revenue_entries');
    }
};
