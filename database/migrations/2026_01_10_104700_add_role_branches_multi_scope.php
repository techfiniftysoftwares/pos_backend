<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * This migration adds support for multi-branch role scopes.
     * Roles can now be assigned to multiple branches.
     */
    public function up(): void
    {
        // Add business_id to roles if not exists
        if (!Schema::hasColumn('roles', 'business_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->foreignId('business_id')->nullable()->after('name')->constrained()->cascadeOnDelete();
            });
        }

        // Create role_branches pivot table
        if (!Schema::hasTable('role_branches')) {
            Schema::create('role_branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['role_id', 'branch_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_branches');

        if (Schema::hasColumn('roles', 'business_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropForeign(['business_id']);
                $table->dropColumn('business_id');
            });
        }
    }
};
