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
        Schema::table('user_branch_roles', function (Blueprint $table) {
            // Drop user_id FK because it relies on the unique index (user_id, branch_id)
            $table->dropForeign(['user_id']);
        });

        Schema::table('user_branch_roles', function (Blueprint $table) {
            // Drop existing unique constraint
            $table->dropUnique(['user_id', 'branch_id']);

            // Make branch_id nullable
            $table->foreignId('branch_id')->nullable()->change();

            // Re-add user_id FK
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Ensure branch_id FK exists (re-add or add fresh)
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');

            // Add new unique constraint
            $table->unique(['user_id', 'role_id', 'branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_branch_roles', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'role_id', 'branch_id']);

            $table->dropForeign(['branch_id']);

            // Make non-nullable again
            $table->foreignId('branch_id')->nullable(false)->change();

            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');

            $table->unique(['user_id', 'branch_id']);
        });
    }
};
