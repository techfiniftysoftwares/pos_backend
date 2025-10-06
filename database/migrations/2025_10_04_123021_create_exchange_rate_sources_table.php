<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('exchange_rate_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Manual Entry, Central Bank, API Provider
            $table->string('code')->unique(); // manual, central_bank, api_provider
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('exchange_rate_sources');
    }
};
