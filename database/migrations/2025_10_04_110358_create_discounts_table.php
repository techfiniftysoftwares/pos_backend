<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->enum('type', ['percentage', 'fixed', 'bogo', 'quantity']);
            $table->decimal('value', 15, 2);
            $table->enum('applies_to', ['product', 'category', 'cart']);
            $table->json('target_ids')->nullable();
            $table->decimal('minimum_amount', 15, 2)->nullable();
            $table->integer('maximum_uses')->nullable();
            $table->integer('uses_count')->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('discounts');
    }
};
