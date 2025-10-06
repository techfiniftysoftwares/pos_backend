<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GiftCardTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'gift_card_id',
        'transaction_type',
        'amount',
        'previous_balance',
        'new_balance',
        'reference_number',
        'processed_by',
        'branch_id',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'previous_balance' => 'decimal:2',
        'new_balance' => 'decimal:2',
    ];

    // Relationships
    public function giftCard()
    {
        return $this->belongsTo(GiftCard::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // Auto-calculate balances and update gift card
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            $giftCard = GiftCard::find($transaction->gift_card_id);
            $transaction->previous_balance = $giftCard->current_balance;

            // Calculate new balance based on transaction type
            switch ($transaction->transaction_type) {
                case 'issued':
                    $transaction->new_balance = $transaction->amount;
                    break;
                case 'used':
                    $transaction->new_balance = $transaction->previous_balance - $transaction->amount;
                    break;
                case 'refunded':
                    $transaction->new_balance = $transaction->previous_balance + $transaction->amount;
                    break;
                case 'adjustment':
                    $transaction->new_balance = $transaction->previous_balance + $transaction->amount;
                    break;
            }

            // Update gift card balance and status
            $giftCard->current_balance = $transaction->new_balance;

            if ($giftCard->current_balance <= 0) {
                $giftCard->status = 'depleted';
            }

            $giftCard->save();
        });
    }
}