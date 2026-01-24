<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;

class ModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seeds the 9 application modules.
     */
    public function run(): void
    {
        $this->command->info('Seeding modules...');

        $modules = [
            ['name' => 'Sales & POS', 'is_active' => true],
            ['name' => 'Products', 'is_active' => true],
            ['name' => 'Inventory', 'is_active' => true],
            ['name' => 'Procurement', 'is_active' => true],
            ['name' => 'Customers', 'is_active' => true],
            ['name' => 'Payments', 'is_active' => true],
            ['name' => 'Revenue', 'is_active' => true],
            ['name' => 'Reports & Analytics', 'is_active' => true],
            ['name' => 'User Management', 'is_active' => true],
            ['name' => 'Settings', 'is_active' => true],
        ];

        foreach ($modules as $moduleData) {
            Module::updateOrCreate(
                ['name' => $moduleData['name']],
                $moduleData
            );
            $this->command->info("✓ Created module: {$moduleData['name']}");
        }

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  ' . count($modules) . ' Modules Seeded Successfully!');
        $this->command->info('========================================');
        $this->command->newLine();
    }
}
