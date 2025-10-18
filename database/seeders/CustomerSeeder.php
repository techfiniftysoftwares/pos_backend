<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;
use App\Models\Business;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding customers...');

        $business = Business::first();

        if (!$business) {
            $this->command->error('No business found. Please run InitialSetupSeeder first.');
            return;
        }

        $customers = [
            // Regular Customers
            [
                'business_id' => $business->id,
                'name' => 'John Doe',
                'email' => 'john.doe@email.com',
                'phone' => '+254712000001',
                'secondary_phone' => '+254722000001',
                'address' => 'Westlands, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'regular',
                'credit_limit' => 50000.00,
                'current_credit_balance' => 0.00,
                'is_active' => true,
                'notes' => 'Regular customer',
            ],
            [
                'business_id' => $business->id,
                'name' => 'Mary Wanjiku',
                'email' => 'mary.wanjiku@email.com',
                'phone' => '+254712000002',
                'address' => 'Kilimani, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'regular',
                'credit_limit' => 30000.00,
                'current_credit_balance' => 5000.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Peter Omondi',
                'email' => 'peter.omondi@email.com',
                'phone' => '+254712000003',
                'address' => 'Parklands, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'regular',
                'credit_limit' => 25000.00,
                'current_credit_balance' => 0.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Grace Muthoni',
                'email' => 'grace.muthoni@email.com',
                'phone' => '+254712000004',
                'address' => 'Karen, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'regular',
                'credit_limit' => 40000.00,
                'current_credit_balance' => 12000.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'David Kipchoge',
                'email' => 'david.kipchoge@email.com',
                'phone' => '+254712000005',
                'address' => 'Upperhill, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'regular',
                'credit_limit' => 35000.00,
                'current_credit_balance' => 0.00,
                'is_active' => true,
            ],

            // VIP Customers
            [
                'business_id' => $business->id,
                'name' => 'Sarah Njeri',
                'email' => 'sarah.njeri@email.com',
                'phone' => '+254712000006',
                'secondary_phone' => '+254722000006',
                'address' => 'Runda, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'vip',
                'credit_limit' => 200000.00,
                'current_credit_balance' => 0.00,
                'is_active' => true,
                'notes' => 'VIP customer - Priority service',
            ],
            [
                'business_id' => $business->id,
                'name' => 'James Otieno',
                'email' => 'james.otieno@email.com',
                'phone' => '+254712000007',
                'address' => 'Lavington, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'vip',
                'credit_limit' => 150000.00,
                'current_credit_balance' => 25000.00,
                'is_active' => true,
                'notes' => 'VIP - High value customer',
            ],
            [
                'business_id' => $business->id,
                'name' => 'Lucy Achieng',
                'email' => 'lucy.achieng@email.com',
                'phone' => '+254712000008',
                'address' => 'Muthaiga, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'vip',
                'credit_limit' => 250000.00,
                'current_credit_balance' => 0.00,
                'is_active' => true,
                'notes' => 'VIP - Platinum tier',
            ],

            // Wholesale Customers
            [
                'business_id' => $business->id,
                'name' => 'Ahmed Hassan Trading Co.',
                'email' => 'ahmed.hassan@tradingco.com',
                'phone' => '+254712000009',
                'secondary_phone' => '+254722000009',
                'address' => 'River Road, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'wholesale',
                'credit_limit' => 500000.00,
                'current_credit_balance' => 150000.00,
                'is_active' => true,
                'notes' => 'Wholesale customer - Bulk orders',
            ],
            [
                'business_id' => $business->id,
                'name' => 'Mohammed Ali Enterprises',
                'email' => 'mohammed.ali@enterprises.com',
                'phone' => '+254712000010',
                'address' => 'Eastleigh, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'wholesale',
                'credit_limit' => 750000.00,
                'current_credit_balance' => 200000.00,
                'is_active' => true,
                'notes' => 'Major wholesale customer',
            ],
            [
                'business_id' => $business->id,
                'name' => 'Jane Wambui Distributors',
                'email' => 'jane.wambui@distributors.com',
                'phone' => '+254712000011',
                'address' => 'Industrial Area, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'wholesale',
                'credit_limit' => 600000.00,
                'current_credit_balance' => 0.00,
                'is_active' => true,
                'notes' => 'Wholesale - Prompt payments',
            ],

            // Walk-in Customers
            [
                'business_id' => $business->id,
                'name' => 'Walk-in Customer',
                'phone' => '+254700000000',
                'address' => 'N/A',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'regular',
                'credit_limit' => 0.00,
                'current_credit_balance' => 0.00,
                'is_active' => true,
                'notes' => 'Default walk-in customer',
            ],

            // More Regular Customers
            [
                'business_id' => $business->id,
                'name' => 'George Mutua',
                'email' => 'george.mutua@email.com',
                'phone' => '+254712000012',
                'address' => 'Ngong Road, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'regular',
                'credit_limit' => 45000.00,
                'current_credit_balance' => 8000.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Paul Muiruri',
                'email' => 'paul.muiruri@email.com',
                'phone' => '+254712000013',
                'address' => 'Kitengela, Kajiado',
                'city' => 'Kajiado',
                'country' => 'Kenya',
                'customer_type' => 'regular',
                'credit_limit' => 30000.00,
                'current_credit_balance' => 0.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Robert Kariuki',
                'email' => 'robert.kariuki@email.com',
                'phone' => '+254712000014',
                'address' => 'Mlolongo, Machakos',
                'city' => 'Machakos',
                'country' => 'Kenya',
                'customer_type' => 'regular',
                'credit_limit' => 20000.00,
                'current_credit_balance' => 5000.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Anne Njoki',
                'email' => 'anne.njoki@email.com',
                'phone' => '+254712000015',
                'address' => 'CBD, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'customer_type' => 'regular',
                'credit_limit' => 35000.00,
                'current_credit_balance' => 0.00,
                'is_active' => true,
            ],
        ];

        foreach ($customers as $customerData) {
            Customer::create($customerData);
            $this->command->info("✓ Created customer: {$customerData['name']}");
        }

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  ' . count($customers) . ' Customers Seeded Successfully!');
        $this->command->info('========================================');
        $this->command->newLine();
    }
}
