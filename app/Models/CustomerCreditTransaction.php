<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerCreditTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'transaction_type',
        'amount',
        'previous_balance',
        'new_balance',
        'payment_method_id',
        'reference_number',
        'notes',
        'processed_by',
        'branch_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'previous_balance' => 'decimal:2',
        'new_balance' => 'decimal:2',
    ];

    // Relationships
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // Auto-calculate balances
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            $customer = Customer::find($transaction->customer_id);
            $transaction->previous_balance = $customer->current_credit_balance;

            // Sale increases balance (debt), payment decreases it
            if ($transaction->transaction_type === 'sale') {
                $transaction->new_balance = $transaction->previous_balance + $transaction->amount;
            } else if ($transaction->transaction_type === 'payment') {
                $transaction->new_balance = $transaction->previous_balance - $transaction->amount;
            } else {
                // adjustment - can be positive or negative
                $transaction->new_balance = $transaction->previous_balance + $transaction->amount;
            }

            // Update customer balance
            $customer->current_credit_balance = $transaction->new_balance;
            $customer->save();
        });
    }
}
