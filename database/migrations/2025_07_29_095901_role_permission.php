<?php
// database/migrations/2024_01_01_000003_create_role_permission_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Role;
use App\Models\Permission;

return new class extends Migration
{
    public function up()
    {
        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();

            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');

            $table->unique(['role_id', 'permission_id']);
            $table->index(['role_id']);
            $table->index(['permission_id']);
        });

        // Seed role-permission relationships
        $this->seedRolePermissions();
    }

    public function down()
    {
        Schema::dropIfExists('role_permission');
    }

    private function seedRolePermissions()
    {
        // Get roles and permissions
        $adminRole = Role::where('name', 'admin')->first();
        $managerRole = Role::where('name', 'support_manager')->first();
        $agentRole = Role::where('name', 'support_agent')->first();
        $technicianRole = Role::where('name', 'technician')->first();
        $customerServiceRole = Role::where('name', 'customer_service')->first();

        // Admin gets all permissions
        $allPermissions = Permission::pluck('id')->toArray();
        $adminRole->permissions()->attach($allPermissions);

        // Support Manager permissions
        $managerPermissions = Permission::whereIn('name', [
            'view_all_tickets',
            'create_tickets',
            'update_tickets',
            'assign_tickets',
            'resolve_tickets',
            'close_tickets',
            'view_user_stats',
            'view_analytics',
            'export_reports',
            'access_pos_system',
            'access_fms_system',
            'access_erp_system',
            'access_payroll_system',
        ])->pluck('id')->toArray();
        $managerRole->permissions()->attach($managerPermissions);

        // Support Agent permissions
        $agentPermissions = Permission::whereIn('name', [
            'view_own_tickets',
            'create_tickets',
            'update_tickets',
            'resolve_tickets',
            'access_pos_system',
            'access_fms_system',
            'access_erp_system',
            'access_payroll_system',
        ])->pluck('id')->toArray();
        $agentRole->permissions()->attach($agentPermissions);

        // Technician permissions (specialized)
        $technicianPermissions = Permission::whereIn('name', [
            'view_own_tickets',
            'update_tickets',
            'resolve_tickets',
            'access_fms_system', // Primary specialization
            'access_pos_system', // Secondary
        ])->pluck('id')->toArray();
        $technicianRole->permissions()->attach($technicianPermissions);

        // Customer Service permissions (basic)
        $customerServicePermissions = Permission::whereIn('name', [
            'view_own_tickets',
            'create_tickets',
            'update_tickets',
            'access_pos_system',
        ])->pluck('id')->toArray();
        $customerServiceRole->permissions()->attach($customerServicePermissions);
    }
};