<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates permissions for ACTIVE submodules only.
     * Each active submodule gets 4 permissions: read, create, update, delete
     */
    public function run(): void
    {
        $this->command->info('Seeding permissions...');

        $actions = ['read', 'create', 'update', 'delete'];

        // Get only active submodules
        $activeSubmodules = Submodule::where('is_active', true)
            ->with('module')
            ->get();

        if ($activeSubmodules->isEmpty()) {
            $this->command->error('No active submodules found. Run SubmoduleSeeder first.');
            return;
        }

        $permissionCount = 0;

        foreach ($activeSubmodules as $submodule) {
            foreach ($actions as $action) {
                Permission::updateOrCreate(
                    [
                        'module_id' => $submodule->module_id,
                        'submodule_id' => $submodule->id,
                        'action' => $action,
                    ],
                    [
                        'module_id' => $submodule->module_id,
                        'submodule_id' => $submodule->id,
                        'action' => $action,
                    ]
                );
                $permissionCount++;
            }
        }

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  Permissions Seeded Successfully!');
        $this->command->info('========================================');
        $this->command->info("  Total Permissions: {$permissionCount}");
        $this->command->info("  (4 actions × {$activeSubmodules->count()} active submodules)");
        $this->command->newLine();
    }
}
