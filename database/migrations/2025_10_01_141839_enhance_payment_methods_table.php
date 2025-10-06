<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // Add new columns
            $table->string('code')->unique()->after('type');
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->boolean('requires_reference')->default(false)->after('is_default');
            $table->decimal('minimum_amount', 15, 2)->nullable()->after('transaction_fee_fixed');
            $table->decimal('maximum_amount', 15, 2)->nullable()->after('minimum_amount');
            $table->json('supported_currencies')->nullable()->after('maximum_amount');
            $table->string('icon')->nullable()->after('supported_currencies');
            $table->integer('sort_order')->default(0)->after('icon');
            $table->json('config')->nullable()->after('sort_order');
            $table->softDeletes();

            // Add indexes
            $table->index('code');
            $table->index('is_default');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropIndex(['code']);
            $table->dropIndex(['is_default']);
            $table->dropIndex(['sort_order']);

            $table->dropColumn([
                'code',
                'is_default',
                'requires_reference',
                'minimum_amount',
                'maximum_amount',
                'supported_currencies',
                'icon',
                'sort_order',
                'config',
                'deleted_at'
            ]);
        });
    }
};
