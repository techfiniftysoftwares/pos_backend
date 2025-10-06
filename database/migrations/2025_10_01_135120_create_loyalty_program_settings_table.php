<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_program_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('businesses')->onDelete('cascade');
            $table->decimal('points_per_currency', 10, 2)->default(1.00); // 1 point per $1
            $table->decimal('currency_per_point', 10, 4)->default(0.01); // $0.01 per point
            $table->integer('minimum_redemption_points')->default(100);
            $table->integer('point_expiry_months')->nullable(); // null = never expires
            $table->boolean('is_active')->default(true);
            $table->json('bonus_multiplier_days')->nullable(); // {"monday": 2, "friday": 1.5}
            $table->boolean('allow_partial_redemption')->default(true);
            $table->decimal('maximum_redemption_percentage', 5, 2)->default(100.00); // Max % of purchase that can be paid with points
            $table->timestamps();

            // Indexes
            $table->index('business_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_program_settings');
    }
};
