<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
                // Core Setup (must run first)
            InitialSetupSeeder::class,

                // RBAC (modules -> submodules -> permissions -> role_permissions)
            ModuleSeeder::class,
            SubmoduleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,

                // Reference Data
            CurrencySeeder::class,
            ExchangeRateSourceSeeder::class,
            ExchangeRateSeeder::class,
            PaymentMethodSeeder::class,
            UnitSeeder::class,
        ]);
    }
}
