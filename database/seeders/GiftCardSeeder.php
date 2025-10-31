<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class GiftCardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::beginTransaction();

        try {
            // Get existing records we'll need
            $businesses = Business::all();
            $customers = Customer::all();
            $users = User::all();
            $branches = Branch::all();

            if ($businesses->isEmpty() || $users->isEmpty() || $branches->isEmpty()) {
                $this->command->error('Please seed Businesses, Users, and Branches first!');
                return;
            }

            $this->command->info('Seeding gift cards...');

            foreach ($businesses as $business) {
                $businessBranches = $branches->where('business_id', $business->id);

                if ($businessBranches->isEmpty()) {
                    continue;
                }

                // Create 10-15 gift cards per business
                for ($i = 0; $i < rand(10, 15); $i++) {
                    $branch = $businessBranches->random();
                    $issuer = $users->random();

                    // 70% of cards have a customer, 30% anonymous
                    $customer = rand(1, 10) <= 7 ? $customers->random() : null;

                    // Random initial amount between 10 and 500
                    $initialAmount = round(rand(1000, 50000) / 100, 2); // 10.00 to 500.00

                    // Determine status distribution
                    $statusRand = rand(1, 100);
                    if ($statusRand <= 60) {
                        // 60% active with various balances
                        $status = 'active';
                        $usagePercent = rand(10, 100) / 100;
                        $currentBalance = round($initialAmount * $usagePercent, 2);
                    } elseif ($statusRand <= 75) {
                        // 15% depleted
                        $status = 'depleted';
                        $currentBalance = 0.00;
                    } elseif ($statusRand <= 85) {
                        // 10% expired
                        $status = 'expired';
                        $currentBalance = round(rand(0, $initialAmount * 100) / 100, 2);
                    } else {
                        // 15% inactive
                        $status = 'inactive';
                        $currentBalance = round(rand(0, $initialAmount * 100) / 100, 2);
                    }

                    // Set expiration date
                    $expiresAt = null;
                    if ($status === 'expired') {
                        $expiresAt = now()->subDays(rand(1, 180));
                    } elseif (rand(1, 10) <= 7) {
                        // 70% of active cards have expiration
                        $expiresAt = now()->addMonths(rand(3, 24));
                    }

                    // Set issued date (between 1 year ago and now)
                    $issuedAt = now()->subDays(rand(0, 365));

                    // Create gift card - let auto-generation handle card_number
                    $giftCard = new GiftCard([
                        'business_id' => $business->id,
                        'customer_id' => $customer?->id,
                        'initial_amount' => $initialAmount,
                        'current_balance' => $initialAmount,
                        'status' => 'active', // Start as active
                        'issued_by' => $issuer->id,
                        'issued_at' => $issuedAt,
                        'expires_at' => $expiresAt,
                        'branch_id' => $branch->id,
                    ]);

                    // 50% of cards have a PIN
                    if (rand(1, 10) <= 5) {
                        $giftCard->setPin('1234');
                    }

                    $giftCard->save();

                    // Manually create first transaction without triggering boot
                    DB::table('gift_card_transactions')->insert([
                        'gift_card_id' => $giftCard->id,
                        'transaction_type' => 'issued',
                        'amount' => $initialAmount,
                        'previous_balance' => 0,
                        'new_balance' => $initialAmount,
                        'processed_by' => $issuer->id,
                        'branch_id' => $branch->id,
                        'notes' => 'Gift card issued',
                        'created_at' => $issuedAt,
                        'updated_at' => $issuedAt,
                    ]);

                    // Create usage transactions for cards that have been used
                    if ($currentBalance < $initialAmount) {
                        $amountToDeduct = $initialAmount - $currentBalance;
                        $numTransactions = rand(1, 5);
                        $runningBalance = $initialAmount;
                        $lastTransactionDate = Carbon::parse($issuedAt);

                        for ($t = 0; $t < $numTransactions && $runningBalance > 0 && $amountToDeduct > 0; $t++) {
                            $daysToAdd = rand(1, 30);
                            $lastTransactionDate = $lastTransactionDate->addDays($daysToAdd);

                            // Determine transaction type
                            $typeRand = rand(1, 100);

                            if ($typeRand <= 75) {
                                // 75% used transactions
                                if ($amountToDeduct > 0 && $runningBalance > 0) {
                                    $maxAmount = min($runningBalance, $amountToDeduct);
                                    $amount = round(rand(500, $maxAmount * 100) / 100, 2);
                                    $type = 'used';
                                    $previousBalance = $runningBalance;
                                    $runningBalance = round($runningBalance - $amount, 2);
                                    $amountToDeduct = round($amountToDeduct - $amount, 2);
                                } else {
                                    continue;
                                }
                            } elseif ($typeRand <= 90) {
                                // 15% refunds
                                $amount = round(rand(500, 5000) / 100, 2);
                                $type = 'refunded';
                                $previousBalance = $runningBalance;
                                $runningBalance = round($runningBalance + $amount, 2);
                            } else {
                                // 10% adjustments
                                $amount = round(rand(500, 2000) / 100, 2);
                                $type = 'adjustment';
                                $previousBalance = $runningBalance;
                                $runningBalance = round($runningBalance + $amount, 2);
                            }

                            DB::table('gift_card_transactions')->insert([
                                'gift_card_id' => $giftCard->id,
                                'transaction_type' => $type,
                                'amount' => $amount,
                                'previous_balance' => $previousBalance,
                                'new_balance' => $runningBalance,
                                'reference_number' => 'REF-' . strtoupper(uniqid()),
                                'processed_by' => $users->random()->id,
                                'branch_id' => $businessBranches->random()->id,
                                'notes' => ucfirst($type) . ' transaction',
                                'created_at' => $lastTransactionDate,
                                'updated_at' => $lastTransactionDate,
                            ]);
                        }

                        // Use the final running balance
                        $finalBalance = $runningBalance;
                    } else {
                        $finalBalance = $currentBalance;
                    }

                    // Manually set final balance and status
                    $giftCard->current_balance = round($finalBalance >= 0 ? $finalBalance : 0, 2);
                    $giftCard->status = $status;
                    $giftCard->save();
                }
            }

            DB::commit();
            $this->command->info('Gift cards seeded successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Error seeding gift cards: ' . $e->getMessage());
        }
    }
}
