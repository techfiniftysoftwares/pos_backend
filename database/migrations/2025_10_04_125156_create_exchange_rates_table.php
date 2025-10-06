<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('from_currency_id')->constrained('currencies')->onDelete('restrict');
            $table->foreignId('to_currency_id')->constrained('currencies')->onDelete('restrict');
            $table->foreignId('source_id')->nullable()->constrained('exchange_rate_sources')->onDelete('set null');

            $table->decimal('rate', 12, 6);
            $table->date('effective_date');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->timestamps();

            // Custom short index name
            $table->index(['from_currency_id', 'to_currency_id', 'effective_date'], 'idx_exchange_rates_currencies_date');
            $table->index('is_active');
        });
    }

    public function down()
    {
        Schema::dropIfExists('exchange_rates');
    }
};
