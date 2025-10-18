<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Supplier;
use App\Models\Business;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding suppliers...');

        // Get the first business (or specify your business ID)
        $business = Business::first();

        if (!$business) {
            $this->command->error('No business found. Please run InitialSetupSeeder first.');
            return;
        }

        $suppliers = [
            // Electronics Suppliers
            [
                'business_id' => $business->id,
                'name' => 'Samsung Kenya Ltd',
                'email' => 'orders@samsung.co.ke',
                'phone' => '+254712345001',
                'address' => 'Westlands, Nairobi',
                'contact_person' => 'John Kamau',
                'tax_number' => 'TAX001234',
                'payment_terms' => '30 days',
                'credit_limit' => 5000000.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Apple Authorized Distributor',
                'email' => 'sales@applekeeper.co.ke',
                'phone' => '+254712345002',
                'address' => 'Kilimani, Nairobi',
                'contact_person' => 'Mary Wanjiku',
                'tax_number' => 'TAX001235',
                'payment_terms' => '60 days',
                'credit_limit' => 10000000.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Xiaomi Kenya',
                'email' => 'info@xiaomi.co.ke',
                'phone' => '+254712345003',
                'address' => 'Parklands, Nairobi',
                'contact_person' => 'Peter Omondi',
                'tax_number' => 'TAX001236',
                'payment_terms' => '45 days',
                'credit_limit' => 3000000.00,
                'is_active' => true,
            ],

            // Food & Beverage Suppliers
            [
                'business_id' => $business->id,
                'name' => 'Kenya Food Suppliers Ltd',
                'email' => 'orders@kenyafood.co.ke',
                'phone' => '+254712345004',
                'address' => 'Industrial Area, Nairobi',
                'contact_person' => 'Grace Muthoni',
                'tax_number' => 'TAX001237',
                'payment_terms' => '14 days',
                'credit_limit' => 1000000.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Coca-Cola East Africa',
                'email' => 'sales@coca-cola.co.ke',
                'phone' => '+254712345005',
                'address' => 'Upperhill, Nairobi',
                'contact_person' => 'David Kipchoge',
                'tax_number' => 'TAX001238',
                'payment_terms' => '21 days',
                'credit_limit' => 2000000.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Fresh Farm Produce Ltd',
                'email' => 'info@freshfarm.co.ke',
                'phone' => '+254712345006',
                'address' => 'Kiambu Road, Kiambu',
                'contact_person' => 'Sarah Njeri',
                'tax_number' => 'TAX001239',
                'payment_terms' => '7 days',
                'credit_limit' => 500000.00,
                'is_active' => true,
            ],

            // Clothing & Textiles Suppliers
            [
                'business_id' => $business->id,
                'name' => 'Fashion Textiles Kenya',
                'email' => 'orders@fashiontextiles.co.ke',
                'phone' => '+254712345007',
                'address' => 'Gikomba, Nairobi',
                'contact_person' => 'James Otieno',
                'tax_number' => 'TAX001240',
                'payment_terms' => '30 days',
                'credit_limit' => 1500000.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Nike Kenya Distributors',
                'email' => 'sales@nikekenya.co.ke',
                'phone' => '+254712345008',
                'address' => 'Westlands, Nairobi',
                'contact_person' => 'Lucy Achieng',
                'tax_number' => 'TAX001241',
                'payment_terms' => '60 days',
                'credit_limit' => 3000000.00,
                'is_active' => true,
            ],

            // General Merchandise
            [
                'business_id' => $business->id,
                'name' => 'General Traders Ltd',
                'email' => 'info@generaltraders.co.ke',
                'phone' => '+254712345009',
                'address' => 'River Road, Nairobi',
                'contact_person' => 'Ahmed Hassan',
                'tax_number' => 'TAX001242',
                'payment_terms' => '30 days',
                'credit_limit' => 2000000.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Wholesale Kenya Supplies',
                'email' => 'orders@wholesalekenya.co.ke',
                'phone' => '+254712345010',
                'address' => 'Eastleigh, Nairobi',
                'contact_person' => 'Mohammed Ali',
                'tax_number' => 'TAX001243',
                'payment_terms' => '45 days',
                'credit_limit' => 4000000.00,
                'is_active' => true,
            ],

            // Pharmaceuticals & Health
            [
                'business_id' => $business->id,
                'name' => 'Kenya Pharma Distributors',
                'email' => 'sales@kenyapharma.co.ke',
                'phone' => '+254712345011',
                'address' => 'Mombasa Road, Nairobi',
                'contact_person' => 'Dr. Jane Wambui',
                'tax_number' => 'TAX001244',
                'payment_terms' => '30 days',
                'credit_limit' => 5000000.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Health Plus Supplies',
                'email' => 'info@healthplus.co.ke',
                'phone' => '+254712345012',
                'address' => 'Ngong Road, Nairobi',
                'contact_person' => 'George Mutua',
                'tax_number' => 'TAX001245',
                'payment_terms' => '21 days',
                'credit_limit' => 3000000.00,
                'is_active' => true,
            ],

            // Hardware & Construction
            [
                'business_id' => $business->id,
                'name' => 'BuildMart Kenya Ltd',
                'email' => 'orders@buildmart.co.ke',
                'phone' => '+254712345013',
                'address' => 'Kitengela, Kajiado',
                'contact_person' => 'Paul Muiruri',
                'tax_number' => 'TAX001246',
                'payment_terms' => '30 days',
                'credit_limit' => 8000000.00,
                'is_active' => true,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Steel & Iron Supplies',
                'email' => 'sales@steeliron.co.ke',
                'phone' => '+254712345014',
                'address' => 'Mlolongo, Machakos',
                'contact_person' => 'Robert Kariuki',
                'tax_number' => 'TAX001247',
                'payment_terms' => '45 days',
                'credit_limit' => 10000000.00,
                'is_active' => true,
            ],

            // Stationery & Office Supplies
            [
                'business_id' => $business->id,
                'name' => 'Office Mart Kenya',
                'email' => 'info@officemart.co.ke',
                'phone' => '+254712345015',
                'address' => 'CBD, Nairobi',
                'contact_person' => 'Anne Njoki',
                'tax_number' => 'TAX001248',
                'payment_terms' => '30 days',
                'credit_limit' => 1000000.00,
                'is_active' => true,
            ],
        ];

        foreach ($suppliers as $supplierData) {
            Supplier::create($supplierData);
            $this->command->info("✓ Created supplier: {$supplierData['name']}");
        }

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  ' . count($suppliers) . ' Suppliers Seeded Successfully!');
        $this->command->info('========================================');
        $this->command->newLine();
    }
}
