<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Role;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('employee_id')->nullable()->unique();

            // Role (Foreign Key to roles table)
            $table->unsignedBigInteger('role_id');
            $table->string('department')->nullable();
            $table->string('specialization')->nullable();

            // Availability
            $table->boolean('is_available')->default(true);
            $table->string('availability_status')->default('available');

            // Performance Metrics
            $table->integer('tickets_assigned')->default(0);
            $table->integer('tickets_resolved')->default(0);
            $table->decimal('avg_resolution_time_hours', 8, 2)->nullable();

            // Account Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();

            // Notifications
            $table->boolean('email_notifications')->default(true);
            $table->boolean('sms_notifications')->default(false);

            $table->rememberToken();
            $table->timestamps();

            // Foreign Key
            $table->foreign('role_id')->references('id')->on('roles');

            // Indexes
            $table->index(['role_id', 'is_active']);
            $table->index(['is_active', 'availability_status']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }



    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
