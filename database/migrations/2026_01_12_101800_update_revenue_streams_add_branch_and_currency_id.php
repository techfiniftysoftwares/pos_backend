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
        Schema::table('revenue_streams', function (Blueprint $table) {
            // Add branch_id
            $table->foreignId('branch_id')->nullable()->after('business_id')->constrained()->onDelete('cascade');
        });

        // Drop old columns
        Schema::table('revenue_streams', function (Blueprint $table) {
            $table->dropIndex('revenue_streams_code_index');
            $table->dropUnique('revenue_streams_code_unique');
            $table->dropColumn('code');
            $table->dropColumn('default_currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('revenue_streams', function (Blueprint $table) {
            // Re-add old columns
            $table->string('code')->unique()->after('name');
            $table->string('default_currency', 3)->default('KES')->after('description');
            $table->index('code');
        });

        Schema::table('revenue_streams', function (Blueprint $table) {
            // Drop new columns
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
