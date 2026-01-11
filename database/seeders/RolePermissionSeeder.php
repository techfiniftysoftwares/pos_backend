<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Assigns ALL permissions to the Super Admin role.
     */
    public function run(): void
    {
        $this->command->info('Seeding role permissions...');

        // Get the Super Admin role
        $superAdminRole = Role::where('name', 'Super Admin')->first();

        if (!$superAdminRole) {
            $this->command->error('Super Admin role not found. Run InitialSetupSeeder first.');
            return;
        }

        // Get all permissions
        $allPermissions = Permission::pluck('id')->toArray();

        if (empty($allPermissions)) {
            $this->command->error('No permissions found. Run PermissionSeeder first.');
            return;
        }

        // Clear existing permissions for Super Admin to avoid duplicates
        DB::table('role_permission')
            ->where('role_id', $superAdminRole->id)
            ->delete();

        // Attach all permissions to Super Admin
        $superAdminRole->permissions()->attach($allPermissions);

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  Role Permissions Seeded Successfully!');
        $this->command->info('========================================');
        $this->command->info("  Role: {$superAdminRole->name}");
        $this->command->info("  Permissions Assigned: " . count($allPermissions));
        $this->command->newLine();
    }
}
