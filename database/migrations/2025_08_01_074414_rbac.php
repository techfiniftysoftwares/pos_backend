<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create modules table
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Create submodules table
        Schema::create('submodules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->onDelete('cascade');
            $table->string('title');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Create roles table (if not exists)
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->timestamps();
            });
        }

        // Create permissions table
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->onDelete('cascade');
            $table->foreignId('submodule_id')->constrained('submodules')->onDelete('cascade');
            $table->enum('action', ['create', 'read', 'update', 'delete']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Ensure unique permission per module-submodule-action combination
            $table->unique(['module_id', 'submodule_id', 'action']);
        });

        // Create role_permission pivot table
        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
            $table->timestamps();

            // Ensure unique role-permission combination
            $table->unique(['role_id', 'permission_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('submodules');
        Schema::dropIfExists('modules');

        // Only drop roles if it was created by this migration
        // Comment out the next line if roles table existed before
        Schema::dropIfExists('roles');


    }
};
