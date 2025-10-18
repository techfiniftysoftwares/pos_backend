<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentMethod;
use App\Models\Business;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding payment methods...');

        $business = Business::first();

        if (!$business) {
            $this->command->error('No business found. Please run InitialSetupSeeder first.');
            return;
        }

        $paymentMethods = [
            [
                'business_id' => $business->id,
                'name' => 'Cash',
                'type' => 'cash',
                'code' => 'CASH',
                'description' => 'Cash payment',
                'is_active' => true,
                'is_default' => true,
                'requires_reference' => false,
                'transaction_fee_percentage' => 0,
                'transaction_fee_fixed' => 0,
                'minimum_amount' => null,
                'maximum_amount' => null,
                'supported_currencies' => ['KES', 'USD', 'EUR'],
                'icon' => 'cash',
                'sort_order' => 1,
            ],
            [
                'business_id' => $business->id,
                'name' => 'M-Pesa',
                'type' => 'mobile_money',
                'code' => 'MPESA',
                'description' => 'M-Pesa mobile money payment',
                'is_active' => true,
                'is_default' => false,
                'requires_reference' => true,
                'transaction_fee_percentage' => 1.5,
                'transaction_fee_fixed' => 0,
                'minimum_amount' => 10,
                'maximum_amount' => 500000,
                'supported_currencies' => ['KES'],
                'icon' => 'mpesa',
                'sort_order' => 2,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Visa Card',
                'type' => 'card',
                'code' => 'VISA',
                'description' => 'Visa credit/debit card',
                'is_active' => true,
                'is_default' => false,
                'requires_reference' => true,
                'transaction_fee_percentage' => 2.5,
                'transaction_fee_fixed' => 50,
                'minimum_amount' => 100,
                'maximum_amount' => null,
                'supported_currencies' => ['KES', 'USD', 'EUR'],
                'icon' => 'visa',
                'sort_order' => 3,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Mastercard',
                'type' => 'card',
                'code' => 'MASTERCARD',
                'description' => 'Mastercard credit/debit card',
                'is_active' => true,
                'is_default' => false,
                'requires_reference' => true,
                'transaction_fee_percentage' => 2.5,
                'transaction_fee_fixed' => 50,
                'minimum_amount' => 100,
                'maximum_amount' => null,
                'supported_currencies' => ['KES', 'USD', 'EUR'],
                'icon' => 'mastercard',
                'sort_order' => 4,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Bank Transfer',
                'type' => 'bank_transfer',
                'code' => 'BANK_TRANSFER',
                'description' => 'Direct bank transfer',
                'is_active' => true,
                'is_default' => false,
                'requires_reference' => true,
                'transaction_fee_percentage' => 0,
                'transaction_fee_fixed' => 100,
                'minimum_amount' => 1000,
                'maximum_amount' => null,
                'supported_currencies' => ['KES', 'USD', 'EUR'],
                'icon' => 'bank',
                'sort_order' => 5,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Airtel Money',
                'type' => 'mobile_money',
                'code' => 'AIRTEL',
                'description' => 'Airtel Money mobile payment',
                'is_active' => true,
                'is_default' => false,
                'requires_reference' => true,
                'transaction_fee_percentage' => 1.5,
                'transaction_fee_fixed' => 0,
                'minimum_amount' => 10,
                'maximum_amount' => 500000,
                'supported_currencies' => ['KES'],
                'icon' => 'airtel',
                'sort_order' => 6,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Cheque',
                'type' => 'cheque',
                'code' => 'CHEQUE',
                'description' => 'Bank cheque payment',
                'is_active' => true,
                'is_default' => false,
                'requires_reference' => true,
                'transaction_fee_percentage' => 0,
                'transaction_fee_fixed' => 0,
                'minimum_amount' => 5000,
                'maximum_amount' => null,
                'supported_currencies' => ['KES', 'USD', 'EUR'],
                'icon' => 'cheque',
                'sort_order' => 7,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Store Credit',
                'type' => 'store_credit',
                'code' => 'STORE_CREDIT',
                'description' => 'Customer store credit',
                'is_active' => true,
                'is_default' => false,
                'requires_reference' => false,
                'transaction_fee_percentage' => 0,
                'transaction_fee_fixed' => 0,
                'minimum_amount' => null,
                'maximum_amount' => null,
                'supported_currencies' => ['KES'],
                'icon' => 'credit',
                'sort_order' => 8,
            ],
            [
                'business_id' => $business->id,
                'name' => 'Gift Card',
                'type' => 'gift_card',
                'code' => 'GIFT_CARD',
                'description' => 'Gift card payment',
                'is_active' => true,
                'is_default' => false,
                'requires_reference' => true,
                'transaction_fee_percentage' => 0,
                'transaction_fee_fixed' => 0,
                'minimum_amount' => null,
                'maximum_amount' => null,
                'supported_currencies' => ['KES'],
                'icon' => 'gift',
                'sort_order' => 9,
            ],
            [
                'business_id' => $business->id,
                'name' => 'PayPal',
                'type' => 'digital_wallet',
                'code' => 'PAYPAL',
                'description' => 'PayPal payment',
                'is_active' => true,
                'is_default' => false,
                'requires_reference' => true,
                'transaction_fee_percentage' => 3.5,
                'transaction_fee_fixed' => 0,
                'minimum_amount' => 100,
                'maximum_amount' => null,
                'supported_currencies' => ['USD', 'EUR'],
                'icon' => 'paypal',
                'sort_order' => 10,
            ],
        ];

        foreach ($paymentMethods as $methodData) {
            PaymentMethod::create($methodData);
            $this->command->info("✓ Created payment method: {$methodData['name']}");
        }

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('  ' . count($paymentMethods) . ' Payment Methods Seeded Successfully!');
        $this->command->info('========================================');
        $this->command->newLine();
    }
}
