<!-- < ?php
// database/migrations/2024_01_01_000002_create_permissions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Permission;

return new class extends Migration
{
    public function up()
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->string('category')->nullable(); // 'system', 'ticket', 'user', 'report'
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        // Seed default permissions
        $this->seedPermissions();
    }

    public function down()
    {
        Schema::dropIfExists('permissions');
    }

    private function seedPermissions()
    {
        $permissions = [
            // System Management Permissions
            [
                'name' => 'manage_systems',
                'display_name' => 'Manage Business Systems',
                'description' => 'Create, update, and delete business systems',
                'category' => 'system',
                'is_active' => true,
            ],
            [
                'name' => 'access_pos_system',
                'display_name' => 'Access POS System',
                'description' => 'Handle support requests for POS system',
                'category' => 'system',
                'is_active' => true,
            ],
            [
                'name' => 'access_fms_system',
                'display_name' => 'Access FMS System',
                'description' => 'Handle support requests for Facility Management system',
                'category' => 'system',
                'is_active' => true,
            ],
            [
                'name' => 'access_erp_system',
                'display_name' => 'Access ERP System',
                'description' => 'Handle support requests for Kusoya ERP system',
                'category' => 'system',
                'is_active' => true,
            ],
            [
                'name' => 'access_payroll_system',
                'display_name' => 'Access Payroll System',
                'description' => 'Handle support requests for Payroll system',
                'category' => 'system',
                'is_active' => true,
            ],

            // Ticket Management Permissions
            [
                'name' => 'view_all_tickets',
                'display_name' => 'View All Tickets',
                'description' => 'View all support tickets across all systems',
                'category' => 'ticket',
                'is_active' => true,
            ],
            [
                'name' => 'view_own_tickets',
                'display_name' => 'View Own Tickets',
                'description' => 'View only tickets assigned to the user',
                'category' => 'ticket',
                'is_active' => true,
            ],
            [
                'name' => 'create_tickets',
                'display_name' => 'Create Tickets',
                'description' => 'Create new support tickets',
                'category' => 'ticket',
                'is_active' => true,
            ],
            [
                'name' => 'update_tickets',
                'display_name' => 'Update Tickets',
                'description' => 'Update ticket status and information',
                'category' => 'ticket',
                'is_active' => true,
            ],
            [
                'name' => 'assign_tickets',
                'display_name' => 'Assign Tickets',
                'description' => 'Assign tickets to other users',
                'category' => 'ticket',
                'is_active' => true,
            ],
            [
                'name' => 'resolve_tickets',
                'display_name' => 'Resolve Tickets',
                'description' => 'Mark tickets as resolved',
                'category' => 'ticket',
                'is_active' => true,
            ],
            [
                'name' => 'close_tickets',
                'display_name' => 'Close Tickets',
                'description' => 'Close resolved tickets',
                'category' => 'ticket',
                'is_active' => true,
            ],

            // User Management Permissions
            [
                'name' => 'manage_users',
                'display_name' => 'Manage Users',
                'description' => 'Create, update, and deactivate users',
                'category' => 'user',
                'is_active' => true,
            ],
            [
                'name' => 'view_user_stats',
                'display_name' => 'View User Statistics',
                'description' => 'View performance statistics for all users',
                'category' => 'user',
                'is_active' => true,
            ],
            [
                'name' => 'manage_roles',
                'display_name' => 'Manage Roles',
                'description' => 'Create and modify user roles and permissions',
                'category' => 'user',
                'is_active' => true,
            ],

            // Reporting Permissions
            [
                'name' => 'view_analytics',
                'display_name' => 'View System Analytics',
                'description' => 'Access system-wide analytics and reports',
                'category' => 'report',
                'is_active' => true,
            ],
            [
                'name' => 'export_reports',
                'display_name' => 'Export Reports',
                'description' => 'Export analytics and ticket data',
                'category' => 'report',
                'is_active' => true,
            ],
        ];

        foreach ($permissions as $permissionData) {
            Permission::create($permissionData);
        }
    }
}; -->
