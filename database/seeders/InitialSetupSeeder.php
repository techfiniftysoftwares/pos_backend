<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Business;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Currency;

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

            // 1. Create Super Admin Role (global role - no business_id)
            $this->command->info('Creating Super Admin role...');
            $adminRole = Role::create([
                'name' => 'Super Admin',
                'business_id' => null, // Global role
            ]);
            $this->command->info('✓ Super Admin role created (Global)');

            // 2. Create Base Currency
            $this->command->info('Creating base currency...');
            $currency = Currency::firstOrCreate(
                ['code' => 'KES'],
                [
                    'name' => 'Kenyan Shilling',
                    'symbol' => 'KSh',
                    'is_base' => true,
                    'is_active' => true,
                ]
            );
            $this->command->info('✓ Base currency created: ' . $currency->code);

            // 3. Create Business
            $this->command->info('Creating business...');
            $business = Business::create([
                'name' => 'Sample Business',
                'email' => 'business@sample.com',
                'phone' => '+254700000000',
                'address' => 'Nairobi, Kenya',
                'status' => 'active',
                'base_currency_id' => $currency->id,
            ]);
            $this->command->info('✓ Business created: ' . $business->name);

            // 3. Create Main Branch
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

            // 4. Create Admin User (without role_id - will be set via pivot)
            $this->command->info('Creating admin user...');
            $user = User::create([
                'business_id' => $business->id,
                'primary_branch_id' => $branch->id,
                'name' => 'Admin User',
                'email' => 'admin@mail.com',
                'phone' => '+254700000000',
                'password' => Hash::make('password123'),
                'pin' => '1234',
                'employee_id' => 'EMP0001',
                'is_active' => true,
            ]);
            $this->command->info('✓ Admin user created: ' . $user->name);

            // 5. Assign Super Admin role to user via user_branch_roles pivot
            // Using null branch_id for global access (applies to all branches)
            DB::table('user_branch_roles')->insert([
                'user_id' => $user->id,
                'branch_id' => null, // Global access
                'role_id' => $adminRole->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->command->info('✓ Super Admin role assigned (Global Access)');

            // 6. Add user to branch via user_branches pivot
            DB::table('user_branches')->insert([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->command->info('✓ User added to branch');

            DB::commit();

            // Display credentials
            $this->command->newLine();
            $this->command->info('========================================');
            $this->command->info('    SETUP COMPLETED SUCCESSFULLY!');
            $this->command->info('========================================');
            $this->command->newLine();
            $this->command->info('Login Credentials:');
            $this->command->info('------------------');
            $this->command->info('Email: johndoe@gmail.com');
            $this->command->info('Password: password123');
            $this->command->info('PIN: 1234');
            $this->command->newLine();
            $this->command->info('Business: ' . $business->name);
            $this->command->info('Branch: ' . $branch->name);
            $this->command->info('Role: ' . $adminRole->name . ' (Global)');
            $this->command->newLine();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Seeding failed: ' . $e->getMessage());
            $this->command->error($e->getTraceAsString());
            throw $e;
        }
    }
}
