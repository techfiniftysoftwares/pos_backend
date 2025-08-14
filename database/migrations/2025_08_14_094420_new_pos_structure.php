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
        // Create businesses table (the company/chain)
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "SuperMart Chain", "Hardware Plus"
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->text('address')->nullable(); // Head office address
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamps();

            $table->index('status');
        });

        // Create branches table (physical locations/outlets)
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->string('name'); // e.g., "SuperMart Downtown", "SuperMart Mall Branch"
            $table->string('code')->unique(); // Branch identifier code (e.g., SM001, SM002)
            $table->string('phone')->nullable();
            $table->text('address'); // Physical branch address
            $table->boolean('is_active')->default(true);
            $table->boolean('is_main_branch')->default(false); // Main/head office branch
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'is_main_branch']);
        });

        // Update users table to include business, branch, and PIN
        Schema::table('users', function (Blueprint $table) {
            // Add new columns
            $table->foreignId('business_id')->nullable()->after('role_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('primary_branch_id')->nullable()->after('business_id')->constrained('branches')->onDelete('set null');
            $table->string('pin', 6)->nullable()->after('password'); // 4-6 digit PIN for POS login
            $table->string('employee_id')->nullable()->after('pin'); // Employee/staff ID
            $table->integer('failed_pin_attempts')->default(0)->after('employee_id');
            $table->timestamp('pin_locked_until')->nullable()->after('failed_pin_attempts');

            // Add indexes for performance
            $table->index(['business_id', 'is_active']);
            $table->index(['primary_branch_id', 'is_active']);
            $table->index('employee_id');
            $table->index('pin_locked_until');
        });

        // Create user_branches pivot table for multi-branch access
        Schema::create('user_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_branches');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropForeign(['primary_branch_id']);
            $table->dropColumn([
                'business_id',
                'primary_branch_id',
                'pin',
                'employee_id',
                'failed_pin_attempts',
                'pin_locked_until'
            ]);
        });

        Schema::dropIfExists('branches');
        Schema::dropIfExists('businesses');
    }
};
