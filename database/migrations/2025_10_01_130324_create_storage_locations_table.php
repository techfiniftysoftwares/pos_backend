<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->string('name'); // "Aisle A", "Shelf 3", "Cold Storage"
            $table->string('code')->nullable()->unique(); // "A1", "CS-01"
            $table->enum('location_type', [
                'aisle',
                'shelf',
                'bin',
                'zone',
                'warehouse',
                'cold_storage',
                'dry_storage',
                'other'
            ])->default('shelf');
            $table->integer('capacity')->nullable(); // Max items
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes
            $table->index('business_id');
            $table->index('branch_id');
            $table->index('location_type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_locations');
    }
};
