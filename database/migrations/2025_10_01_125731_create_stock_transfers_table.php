<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number')->unique(); // AUTO-TRF-00001
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('from_branch_id')->constrained('branches')->onDelete('restrict');
            $table->foreignId('to_branch_id')->constrained('branches')->onDelete('restrict');

            $table->foreignId('initiated_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('received_by')->nullable()->constrained('users')->onDelete('set null');

            $table->enum('status', [
                'pending',
                'approved',
                'in_transit',
                'completed',
                'cancelled'
            ])->default('pending');

            $table->date('transfer_date');
            $table->date('expected_delivery_date')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->string('transfer_reason')->nullable();
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('business_id');
            $table->index('from_branch_id');
            $table->index('to_branch_id');
            $table->index('status');
            $table->index('transfer_number');
            $table->index('transfer_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
