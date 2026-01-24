<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create businesses table (the company/chain)
        if (!Schema::hasTable('businesses')) {
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
        }

        // Create branches table (physical locations/outlets)
        if (!Schema::hasTable('branches')) {
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
        }

        // Update users table to include business, branch, and PIN
        Schema::table('users', function (Blueprint $table) {
            // Add new columns
            if (!Schema::hasColumn('users', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('role_id')->constrained('businesses')->onDelete('cascade');
            }
            if (!Schema::hasColumn('users', 'primary_branch_id')) {
                $table->foreignId('primary_branch_id')->nullable()->after('business_id')->constrained('branches')->onDelete('set null');
            }
            if (!Schema::hasColumn('users', 'pin')) {
                $table->string('pin', 255)->nullable()->after('password'); // Hashed PIN
            }
            // employee_id is already in users table from previous migration, so we skip adding it.

            if (!Schema::hasColumn('users', 'failed_pin_attempts')) {
                $table->integer('failed_pin_attempts')->default(0)->after('pin');
            }
            if (!Schema::hasColumn('users', 'pin_locked_until')) {
                $table->timestamp('pin_locked_until')->nullable()->after('failed_pin_attempts');
            }

            // Add indexes for performance
            // Note: Checking for index existence is harder, but since these indexes were likely not created due to crash before this point, we will attempt to add them if the columns they depend on are being added?
            // Actually, if columns exist, we assume index might exist or not. 
            // Safest: use try-catch or just add them. If migration crashed at employee_id, these lines were NOT reached.

            $table->index(['business_id', 'is_active']);
            $table->index(['primary_branch_id', 'is_active']);
            $table->index('pin_locked_until');
        });

        // Create user_branches pivot table for multi-branch access
        if (!Schema::hasTable('user_branches')) {
            Schema::create('user_branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['user_id', 'branch_id']);
            });
        }
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
                'failed_pin_attempts',
                'pin_locked_until'
            ]);
        });

        Schema::dropIfExists('branches');
        Schema::dropIfExists('businesses');
    }
};
