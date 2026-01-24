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
                ['title' => 'Sales Dashboard', 'path' => '', 'is_active' => true],
                ['title' => 'New Sale', 'path' => 'sales/new', 'is_active' => true],
                ['title' => 'Sales History', 'path' => 'sales/history', 'is_active' => true],
                // Inactive
                ['title' => 'Returns & Refunds', 'path' => 'sales/returns', 'is_active' => false],
                ['title' => 'Cash Reconciliation', 'path' => 'sales/cash-reconciliation', 'is_active' => false],
            ],

            // Products
            'Products' => [
                ['title' => 'All Products', 'path' => 'products', 'is_active' => true],
                ['title' => 'Add Product', 'path' => 'products/create', 'is_active' => true],
                ['title' => 'Categories', 'path' => 'products/categories', 'is_active' => true],
                ['title' => 'Units', 'path' => 'products/units', 'is_active' => true],
                ['title' => 'Low Stock Alerts', 'path' => 'products/low-stock', 'is_active' => true],
                // Inactive
                ['title' => 'Bulk Operations', 'path' => 'products/bulk-update', 'is_active' => false],
            ],

            // Inventory
            'Inventory' => [
                ['title' => 'Stock Overview', 'path' => 'inventory/overview', 'is_active' => true],
                ['title' => 'Stock Adjustments', 'path' => 'inventory/adjustments', 'is_active' => true],
                ['title' => 'Stock Transfers', 'path' => 'inventory/transfers', 'is_active' => true],
                ['title' => 'Stock Movements', 'path' => 'inventory/movements', 'is_active' => true],
                ['title' => 'Storage Locations', 'path' => 'inventory/storage-locations', 'is_active' => true],
                ['title' => 'Branch Summary', 'path' => 'inventory/branch-summary', 'is_active' => true],
            ],

            // Procurement
            'Procurement' => [
                ['title' => 'Purchase Orders', 'path' => 'procurement/purchase-orders', 'is_active' => true],
                ['title' => 'Suppliers', 'path' => 'procurement/suppliers', 'is_active' => true],
                ['title' => 'Receive Goods', 'path' => 'procurement/receive-goods', 'is_active' => true],
                // Inactive
                ['title' => 'Purchase History', 'path' => 'procurement/history', 'is_active' => false],
            ],

            // Customers
            'Customers' => [
                ['title' => 'All Customers', 'path' => 'customers', 'is_active' => true],
                ['title' => 'Add Customer', 'path' => 'customers/create', 'is_active' => true],
                ['title' => 'Customer Groups', 'path' => 'customers/segments', 'is_active' => true],
                ['title' => 'Credit Management', 'path' => 'customers/credit', 'is_active' => true],
                ['title' => 'Loyalty Points', 'path' => 'customers/points', 'is_active' => true],
                ['title' => 'Gift Cards', 'path' => 'customers/gift-cards', 'is_active' => true],
                // Inactive
                ['title' => 'Customer Search', 'path' => 'customers/search', 'is_active' => false],
            ],

            // Payments
            'Payments' => [
                ['title' => 'Payment Methods', 'path' => 'payments/methods', 'is_active' => true],
                ['title' => 'Transaction History', 'path' => 'payments/transactions', 'is_active' => true],
                // Inactive
                ['title' => 'Payment Reports', 'path' => 'payments/reports', 'is_active' => false],
            ],

            // Revenue
            'Revenue' => [
                ['title' => 'Revenue Streams', 'path' => 'revenue/streams', 'is_active' => true],
                ['title' => 'Revenue Entries', 'path' => 'revenue/entries', 'is_active' => true],
            ],

            // Reports & Analytics
            'Reports & Analytics' => [
                ['title' => 'Sales Reports', 'path' => 'reports/sales', 'is_active' => true],
                ['title' => 'Inventory & Stock Reports', 'path' => 'reports/inventory', 'is_active' => true],
                ['title' => 'Customer Credit Reports', 'path' => 'reports/credit', 'is_active' => true],
                ['title' => 'Payment & Cash Reports', 'path' => 'reports/payments', 'is_active' => true],
                ['title' => 'Revenue Reports', 'path' => 'reports/revenue', 'is_active' => true],
                // Inactive
                ['title' => 'Financial Reports', 'path' => 'reports/financial', 'is_active' => false],
                ['title' => 'Variance Reports', 'path' => 'reports/variance', 'is_active' => false],
                ['title' => 'Credit Aging Report', 'path' => 'reports/credit-aging', 'is_active' => false],
            ],

            // User Management
            'User Management' => [
                ['title' => 'Users', 'path' => 'user-management/users', 'is_active' => true],
                ['title' => 'Add User', 'path' => 'user-management/users/create', 'is_active' => true],
                ['title' => 'Roles & Permissions', 'path' => 'user-management/roles', 'is_active' => true],
                // Inactive
                ['title' => 'User Activity', 'path' => 'users/activity', 'is_active' => false],
            ],

            // Settings
            'Settings' => [
                ['title' => 'Businesses', 'path' => 'settings/businesses', 'is_active' => true],
                ['title' => 'Branches', 'path' => 'settings/branches', 'is_active' => true],
                ['title' => 'Exchange Rates', 'path' => 'settings/exchange-rates', 'is_active' => true],
                ['title' => 'Currency', 'path' => 'settings/currency', 'is_active' => true],
                ['title' => 'Taxes', 'path' => 'settings/tax', 'is_active' => true],
                // Inactive
                ['title' => 'Loyalty Program', 'path' => 'settings/loyalty', 'is_active' => false],
                ['title' => 'System Configuration', 'path' => 'settings/system', 'is_active' => false],
                ['title' => 'Backup & Restore', 'path' => 'settings/backup', 'is_active' => false],
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
                        'path' => $submoduleData['path'],
                    ],
                    [
                        'title' => $submoduleData['title'],
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
