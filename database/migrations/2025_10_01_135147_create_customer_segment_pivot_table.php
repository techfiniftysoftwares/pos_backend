<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_segment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('customer_segment_id')->constrained('customer_segments')->onDelete('cascade');
            $table->timestamp('assigned_at')->useCurrent();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->onDelete('set null');

            // Indexes
            $table->unique(['customer_id', 'customer_segment_id']);
            $table->index('customer_id');
            $table->index('customer_segment_id');
            $table->index('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_segment');
    }
};
