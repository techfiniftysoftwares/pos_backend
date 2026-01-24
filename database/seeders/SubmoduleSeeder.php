<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use App\Models\Submodule;

class SubmoduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seeds all submodules (active and inactive from modules.js).
     */
    public function run(): void
    {
        $this->command->info('Seeding submodules...');

        $submodules = [
            // Sales & POS
            'Sales & POS' => [
                ['title' => 'Sales Dashboard', 'is_active' => true],
                ['title' => 'New Sale', 'is_active' => true],
                ['title' => 'Sales History', 'is_active' => true],
                // Inactive
                ['title' => 'Returns & Refunds', 'is_active' => false],
                ['title' => 'Cash Reconciliation', 'is_active' => false],
            ],

            // Products
            'Products' => [
                ['title' => 'All Products', 'is_active' => true],
                ['title' => 'Add Product', 'is_active' => true],
                ['title' => 'Categories', 'is_active' => true],
                ['title' => 'Units', 'is_active' => true],
                ['title' => 'Low Stock Alerts', 'is_active' => true],
                // Inactive
                ['title' => 'Bulk Operations', 'is_active' => false],
            ],

            // Inventory
            'Inventory' => [
                ['title' => 'Stock Overview', 'is_active' => true],
                ['title' => 'Stock Adjustments', 'is_active' => true],
                ['title' => 'Stock Transfers', 'is_active' => true],
                ['title' => 'Stock Movements', 'is_active' => true],
                ['title' => 'Storage Locations', 'is_active' => true],
                ['title' => 'Branch Summary', 'is_active' => true],
            ],

            // Procurement
            'Procurement' => [
                ['title' => 'Purchase Orders', 'is_active' => true],
                ['title' => 'Suppliers', 'is_active' => true],
                ['title' => 'Receive Goods', 'is_active' => true],
                // Inactive
                ['title' => 'Purchase History', 'is_active' => false],
            ],

            // Customers
            'Customers' => [
                ['title' => 'All Customers', 'is_active' => true],
                ['title' => 'Add Customer', 'is_active' => true],
                ['title' => 'Customer Groups', 'is_active' => true],
                ['title' => 'Credit Management', 'is_active' => true],
                ['title' => 'Loyalty Points', 'is_active' => true],
                ['title' => 'Gift Cards', 'is_active' => true],
                // Inactive
                ['title' => 'Customer Search', 'is_active' => false],
            ],

            // Payments
            'Payments' => [
                ['title' => 'Payment Methods', 'is_active' => true],
                ['title' => 'Transaction History', 'is_active' => true],
                // Inactive
                ['title' => 'Payment Reports', 'is_active' => false],
            ],

            // Revenue
            'Revenue' => [
                ['title' => 'Revenue Streams', 'is_active' => true],
                ['title' => 'Revenue Entries', 'is_active' => true],
            ],

            // Reports & Analytics
            'Reports & Analytics' => [
                ['title' => 'Sales Reports', 'is_active' => true],
                ['title' => 'Inventory & Stock Reports', 'is_active' => true],
                ['title' => 'Customer Credit Reports', 'is_active' => true],
                ['title' => 'Payment & Cash Reports', 'is_active' => true],
                ['title' => 'Revenue Reports', 'is_active' => true],
                // Inactive
                ['title' => 'Financial Reports', 'is_active' => false],
                ['title' => 'Variance Reports', 'is_active' => false],
                ['title' => 'Credit Aging Report', 'is_active' => false],
            ],

            // User Management
            'User Management' => [
                ['title' => 'Users', 'is_active' => true],
                ['title' => 'Add User', 'is_active' => true],
                ['title' => 'Roles & Permissions', 'is_active' => true],
                // Inactive
                ['title' => 'User Activity', 'is_active' => false],
            ],

            // Settings
            'Settings' => [
                ['title' => 'Businesses', 'is_active' => true],
                ['title' => 'Branches', 'is_active' => true],
                ['title' => 'Exchange Rates', 'is_active' => true],
                ['title' => 'Currency', 'is_active' => true],
                ['title' => 'Taxes', 'is_active' => true],
                // Inactive
                ['title' => 'Loyalty Program', 'is_active' => false],
                ['title' => 'System Configuration', 'is_active' => false],
                ['title' => 'Backup & Restore', 'is_active' => false],
            ],
        ];

        $activeCount = 0;
        $inactiveCount = 0;

        foreach ($submodules as $moduleName => $moduleSubmodules) {
            $module = Module::where('name', $moduleName)->first();

            if (!$module) {
                $this->command->warn("⚠ Module '{$moduleName}' not found. Run ModuleSeeder first.");
                continue;
            }

            foreach ($moduleSubmodules as $submoduleData) {
                Submodule::updateOrCreate(
                    [
                        'module_id' => $module->id,
                        'title' => $submoduleData['title'],
                    ],
                    [
                        'module_id' => $module->id,
                        'is_active' => $submoduleData['is_active'],
                    ]
                );

                if ($submoduleData['is_active']) {
                    $activeCount++;
                } else {
                    $inactiveCount++;
                }
            }

            $this->command->info("✓ {$moduleName}: " . count($moduleSubmodules) . " submodules");
        }

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  Submodules Seeded Successfully!');
        $this->command->info('========================================');
        $this->command->info("  Active: {$activeCount}");
        $this->command->info("  Inactive: {$inactiveCount}");
        $this->command->info('  Total: ' . ($activeCount + $inactiveCount));
        $this->command->newLine();
    }
}
