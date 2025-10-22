<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\CustomerSegment;
use App\Models\Business;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\CustomerPoint;
use Illuminate\Support\Facades\DB;

class CustomerDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::beginTransaction();

        try {
            // Get existing data for relationships
            $businesses = Business::all();
            $branches = Branch::all();
            $paymentMethods = PaymentMethod::all();
            $users = User::all();

            if ($businesses->isEmpty() || $branches->isEmpty() || $paymentMethods->isEmpty() || $users->isEmpty()) {
                $this->command->error('Please ensure you have businesses, branches, payment methods, and users seeded first!');
                return;
            }

            $this->command->info('Starting to seed customer data for DRC...');

            // Customer types distribution
            $customerTypes = ['regular', 'vip', 'wholesale'];
            $customerTypeWeights = [70, 20, 10]; // 70% regular, 20% vip, 10% wholesale

            // DRC cities (Kinshasa and major cities)
            $cities = ['Kinshasa', 'Lubumbashi', 'Mbuji-Mayi', 'Kisangani', 'Kananga', 'Likasi', 'Kolwezi', 'Goma'];

            // Congolese first names
            $firstNames = ['Jean', 'Marie', 'Joseph', 'Grace', 'Emmanuel', 'Beatrice', 'Patrick', 'Nicole',
                          'Claude', 'Angelique', 'Pascal', 'Chantal', 'Andre', 'Francine', 'Michel', 'Cecile',
                          'Pierre', 'Josephine', 'Jacques', 'Sylvie', 'Robert', 'Helene', 'Paul', 'Christine',
                          'David', 'Florence', 'Daniel', 'Brigitte', 'Christian', 'Laurette', 'Antoine', 'Nadine'];

            // Congolese last names
            $lastNames = ['Kabila', 'Tshisekedi', 'Lumumba', 'Mulumba', 'Nguza', 'Mbuyi', 'Kalonji', 'Kasongo',
                         'Ilunga', 'Mukendi', 'Banza', 'Mwamba', 'Kalala', 'Mutombo', 'Kayembe', 'Mbala',
                         'Ntumba', 'Kapend', 'Makasi', 'Lukusa', 'Ndala', 'Masengo', 'Kibwe', 'Luamba',
                         'Bakajika', 'Nkulu', 'Mpiana', 'Kalombo', 'Museng', 'Kabongo', 'Tshilombo', 'Mwenze'];

            // Kinshasa communes for more specific addresses
            $communes = ['Gombe', 'Kinshasa', 'Barumbu', 'Lingwala', 'Kintambo', 'Ngaliema', 'Lemba', 'Matete', 'Kalamu', 'Makala'];
            $avenues = ['Avenue de la Liberation', 'Boulevard du 30 Juin', 'Avenue Kasavubu', 'Avenue de la Justice',
                       'Avenue Wagenia', 'Avenue Tombalbaye', 'Avenue Colonel Mondjiba', 'Avenue des Aviateurs'];

            $customers = [];

            // Create 100 customers
            for ($i = 0; $i < 100; $i++) {
                $business = $businesses->random();
                $customerType = $this->weightedRandom($customerTypes, $customerTypeWeights);

                // Set credit limit based on customer type (in Congolese Francs - CDF)
                $creditLimit = match($customerType) {
                    'vip' => rand(100000, 500000),      // 100K-500K CDF
                    'wholesale' => rand(500000, 2000000), // 500K-2M CDF
                    default => rand(0, 100000),          // 0-100K CDF
                };

                $firstName = $firstNames[array_rand($firstNames)];
                $lastName = $lastNames[array_rand($lastNames)];
                $fullName = "$firstName $lastName";
                $email = strtolower($firstName . '.' . $lastName . rand(1, 999) . '@mail.cd');

                // DRC phone format: +243 followed by 9 digits (mobile operators: 81, 82, 83, 84, 85, 89, 97, 98, 99)
                $mobilePrefix = [81, 82, 83, 84, 85, 89, 97, 98, 99][array_rand([81, 82, 83, 84, 85, 89, 97, 98, 99])];
                $phone = '+243' . $mobilePrefix . rand(1000000, 9999999);

                $city = $cities[array_rand($cities)];
                $address = ($city === 'Kinshasa')
                    ? rand(1, 999) . ' ' . $avenues[array_rand($avenues)] . ', ' . $communes[array_rand($communes)]
                    : rand(1, 999) . ' Avenue ' . ['Lumumba', 'Mobutu', 'Kabila', 'Commerce'][array_rand(['Lumumba', 'Mobutu', 'Kabila', 'Commerce'])];

                $customer = Customer::create([
                    'business_id' => $business->id,
                    'name' => $fullName,
                    'email' => rand(0, 100) > 20 ? $email : null, // 80% have email
                    'phone' => $phone,
                    'secondary_phone' => rand(0, 100) > 70 ? '+243' . $mobilePrefix . rand(1000000, 9999999) : null,
                    'address' => $address,
                    'city' => $city,
                    'country' => 'Democratic Republic of Congo',
                    'customer_type' => $customerType,
                    'credit_limit' => $creditLimit,
                    'current_credit_balance' => 0,
                    'is_active' => rand(0, 100) > 10, // 90% active
                    'notes' => rand(0, 100) > 70 ? 'Client fidèle depuis ' . rand(2020, 2024) : null,
                ]);

                $customers[] = $customer;

                if (($i + 1) % 20 == 0) {
                    $this->command->info("Created " . ($i + 1) . " customers...");
                }
            }

            $this->command->info('✓ Created 100 customers');

            // Create customer segments (5 segments per business)
            $segments = [];
            $segmentData = [
                [
                    'name' => 'Clients VIP',
                    'description' => 'Clients à haute valeur - VIP',
                    'criteria' => ['customer_type' => ['operator' => '=', 'value' => 'vip']],
                ],
                [
                    'name' => 'Acheteurs en Gros',
                    'description' => 'Clients grossistes',
                    'criteria' => ['customer_type' => ['operator' => '=', 'value' => 'wholesale']],
                ],
                [
                    'name' => 'Crédit Élevé',
                    'description' => 'Clients avec limite de crédit supérieure à 500K CDF',
                    'criteria' => ['credit_balance' => ['operator' => '>=', 'value' => 500000]],
                ],
                [
                    'name' => 'Clients Kinshasa',
                    'description' => 'Clients basés à Kinshasa',
                    'criteria' => ['city' => ['operator' => '=', 'value' => 'Kinshasa']],
                ],
                [
                    'name' => 'Clients Réguliers Actifs',
                    'description' => 'Base de clients réguliers actifs',
                    'criteria' => ['customer_type' => ['operator' => '=', 'value' => 'regular']],
                ],
            ];

            foreach ($segmentData as $segmentInfo) {
                foreach ($businesses as $business) {
                    $segment = CustomerSegment::create([
                        'business_id' => $business->id,
                        'name' => $segmentInfo['name'],
                        'description' => $segmentInfo['description'],
                        'criteria' => $segmentInfo['criteria'],
                        'is_active' => true,
                    ]);
                    $segments[] = $segment;
                }
            }

            $this->command->info('✓ Created customer segments');

            // Assign customers to segments (without timestamps)
            foreach ($segments as $segment) {
                $businessCustomers = collect($customers)->where('business_id', $segment->business_id);

                if ($businessCustomers->count() > 0) {
                    $numberOfCustomersToAssign = min(rand(5, 15), $businessCustomers->count());
                    $matchingCustomers = $businessCustomers->random($numberOfCustomersToAssign);

                    foreach ($matchingCustomers as $customer) {
                        try {
                            DB::table('customer_segment')->insert([
                                'customer_id' => $customer->id,
                                'customer_segment_id' => $segment->id,
                                'assigned_at' => now(),
                                'assigned_by' => $users->random()->id,
                            ]);
                        } catch (\Exception $e) {
                            // Skip if already assigned
                            continue;
                        }
                    }
                }
            }

            $this->command->info('✓ Assigned customers to segments');

            // Create credit transactions for random customers (30% of customers)
            $customersWithCredit = collect($customers)->random(min(30, count($customers)));
            $transactionCount = 0;

            foreach ($customersWithCredit as $customer) {
                $numberOfTransactions = rand(2, 8);
                $branchesForBusiness = $branches->where('business_id', $customer->business_id);

                if ($branchesForBusiness->isEmpty()) {
                    continue;
                }

                $branch = $branchesForBusiness->random();
                $user = $users->random();

                for ($j = 0; $j < $numberOfTransactions; $j++) {
                    // More payments than sales for realistic data
                    $transactionType = ['sale', 'payment', 'payment'][array_rand(['sale', 'payment', 'payment'])];

                    // Determine amount based on customer type (in CDF)
                    $amount = match($customer->customer_type) {
                        'vip' => rand(10000, 100000),      // 10K-100K CDF
                        'wholesale' => rand(50000, 500000), // 50K-500K CDF
                        default => rand(1000, 50000),       // 1K-50K CDF
                    };

                    CustomerCreditTransaction::create([
                        'customer_id' => $customer->id,
                        'transaction_type' => $transactionType,
                        'amount' => $amount,
                        'payment_method_id' => $transactionType === 'payment' ? $paymentMethods->random()->id : null,
                        'reference_number' => 'REF-' . strtoupper(uniqid()),
                        'notes' => $transactionType === 'sale' ? 'Vente à crédit' : 'Paiement reçu',
                        'processed_by' => $user->id,
                        'branch_id' => $branch->id,
                    ]);

                    $transactionCount++;
                }
            }

            $this->command->info("✓ Created {$transactionCount} credit transactions for " . $customersWithCredit->count() . " customers");

            // Create customer points for random customers (40% of customers)
            $customersWithPoints = collect($customers)->random(min(40, count($customers)));
            $pointsCount = 0;

            foreach ($customersWithPoints as $customer) {
                $numberOfPointTransactions = rand(3, 10);
                $branchesForBusiness = $branches->where('business_id', $customer->business_id);

                if ($branchesForBusiness->isEmpty()) {
                    continue;
                }

                $branch = $branchesForBusiness->random();
                $user = $users->random();

                for ($k = 0; $k < $numberOfPointTransactions; $k++) {
                    $transactionType = ['earned', 'redeemed'][array_rand(['earned', 'redeemed'])];
                    $points = $transactionType === 'earned' ? rand(10, 500) : -rand(5, 200);

                    CustomerPoint::create([
                        'customer_id' => $customer->id,
                        'transaction_type' => $transactionType,
                        'points' => $points,
                        'reference_type' => 'App\Models\Sale',
                        'reference_id' => rand(1, 1000),
                        'expires_at' => $transactionType === 'earned' ? now()->addYear() : null,
                        'processed_by' => $user->id,
                        'branch_id' => $branch->id,
                        'notes' => $transactionType === 'earned' ? 'Points gagnés sur achat' : 'Points utilisés',
                    ]);

                    $pointsCount++;
                }
            }

            $this->command->info("✓ Created {$pointsCount} point transactions for " . $customersWithPoints->count() . " customers");

            DB::commit();

            $this->command->info('');
            $this->command->info('====================================');
            $this->command->info('✓ Customer data seeding completed!');
            $this->command->info('====================================');
            $this->command->info('Summary:');
            $this->command->info("- Customers: 100");
            $this->command->info("- Customer Segments: " . count($segments));
            $this->command->info("- Credit Transactions: {$transactionCount}");
            $this->command->info("- Point Transactions: {$pointsCount}");
            $this->command->info('- Location: Democratic Republic of Congo');
            $this->command->info('- Main City: Kinshasa');
            $this->command->info('====================================');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Seeding failed: ' . $e->getMessage());
            $this->command->error('Line: ' . $e->getLine());
            $this->command->error('File: ' . $e->getFile());
            throw $e;
        }
    }

    /**
     * Get weighted random value
     */
    private function weightedRandom(array $values, array $weights): mixed
    {
        $totalWeight = array_sum($weights);
        $random = rand(1, $totalWeight);

        $currentWeight = 0;
        foreach ($values as $index => $value) {
            $currentWeight += $weights[$index];
            if ($random <= $currentWeight) {
                return $value;
            }
        }

        return $values[0];
    }
}
