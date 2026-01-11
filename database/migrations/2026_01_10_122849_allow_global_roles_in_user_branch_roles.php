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
            // Drop the branch_id foreign key so we can modify the column
            $table->dropForeign(['branch_id']);

            // Drop existing unique constraint
            $table->dropUnique(['user_id', 'branch_id']);
        });

        Schema::table('user_branch_roles', function (Blueprint $table) {
            // Make branch_id nullable (for global roles that apply to all branches)
            $table->unsignedBigInteger('branch_id')->nullable()->change();

            // Re-add branch_id FK with nullable support
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');

            // Add new unique constraint (user can have same role on different branches, or global)
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
