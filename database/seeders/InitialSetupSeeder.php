<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Business;
use App\Models\Branch;
use App\Models\Role;

class InitialSetupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::beginTransaction();

        try {
            $this->command->info('Starting initial setup...');

            // 1. Create Super Admin Role (only with name field)
            $this->command->info('Creating Super Admin role...');
            $adminRole = Role::create([
                'name' => 'Super Admin',
            ]);
            $this->command->info('✓ Super Admin role created');

            // 2. Create Business
            $this->command->info('Creating business...');
            $business = Business::create([
                'name' => 'KKC Business',
                'email' => 'business@kkc.com',
                'phone' => '+254700000000',
                'address' => 'Nairobi, Kenya',
                'status' => 'active',
            ]);
            $this->command->info('✓ Business created: ' . $business->name);

            // 3. Create Main Branch (without email field)
            $this->command->info('Creating main branch...');
            $branch = Branch::create([
                'business_id' => $business->id,
                'name' => 'Main Branch',
                'code' => 'MAIN001',
                'phone' => '+254700000001',
                'address' => 'Main Street, Nairobi',
                'is_main_branch' => true,
                'is_active' => true,
            ]);
            $this->command->info('✓ Main branch created: ' . $branch->name);

            // 4. Create Admin User
            $this->command->info('Creating admin user...');
            $user = User::create([
                'business_id' => $business->id,
                'primary_branch_id' => $branch->id,
                'role_id' => $adminRole->id,
                'name' => 'Joshua John',
                'email' => 'joshujohn03@gmail.com',
                'phone' => '+254700000000',
                'password' => Hash::make('password123'),
                'pin' => '1234',  // Will be hashed by setPinAttribute mutator
                'employee_id' => 'KK0001',
                'is_active' => true,
            ]);
            $this->command->info('✓ Admin user created: ' . $user->name);

            DB::commit();

            // Display credentials
            $this->command->newLine();
            $this->command->info('========================================');
            $this->command->info('    SETUP COMPLETED SUCCESSFULLY!');
            $this->command->info('========================================');
            $this->command->newLine();
            $this->command->info('Login Credentials:');
            $this->command->info('------------------');
            $this->command->info('Email: joshujohn03@gmail.com');
            $this->command->info('Password: password123');
            $this->command->info('PIN: 1234');
            $this->command->newLine();
            $this->command->info('Business: ' . $business->name);
            $this->command->info('Branch: ' . $branch->name);
            $this->command->info('Role: ' . $adminRole->name);
            $this->command->newLine();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Seeding failed: ' . $e->getMessage());
            $this->command->error($e->getTraceAsString());
            throw $e;
        }
    }
}
