<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * Creates user_branch_roles pivot table for per-branch role assignments.
     * Supports nullable branch_id for global roles that apply to all branches.
     * Migrates existing role_id data, then drops role_id from users.
     */
    public function up(): void
    {
        // 1. Create the pivot table with nullable branch_id for global roles
        Schema::create('user_branch_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable(); // Nullable for global roles
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Add foreign key for branch_id with cascade delete
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');

            // Unique constraint: user can have same role on different branches, or a global role (null branch)
            $table->unique(['user_id', 'role_id', 'branch_id']);
        });

        // 2. Migrate existing data only if role_id column still exists on users
        if (Schema::hasColumn('users', 'role_id')) {
            $users = DB::table('users')
                ->whereNotNull('role_id')
                ->get(['id', 'role_id']);

            foreach ($users as $user) {
                // Get user's accessible branches from user_branches pivot
                $branchIds = DB::table('user_branches')
                    ->where('user_id', $user->id)
                    ->pluck('branch_id');

                foreach ($branchIds as $branchId) {
                    DB::table('user_branch_roles')->insert([
                        'user_id' => $user->id,
                        'branch_id' => $branchId,
                        'role_id' => $user->role_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // 3. Drop the role_id column from users
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['role_id']);
                $table->dropColumn('role_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add role_id to users
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('business_id')->constrained();
        });

        // Migrate data back: use the role from their primary branch
        $users = DB::table('users')->get(['id', 'primary_branch_id']);

        foreach ($users as $user) {
            $roleId = DB::table('user_branch_roles')
                ->where('user_id', $user->id)
                ->where('branch_id', $user->primary_branch_id)
                ->value('role_id');

            if ($roleId) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['role_id' => $roleId]);
            }
        }

        // Drop the pivot table
        Schema::dropIfExists('user_branch_roles');
    }
};
