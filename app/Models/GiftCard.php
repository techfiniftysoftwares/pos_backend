<?php

namespace App\Models;

use App\Models\Traits\ScopedByBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class GiftCard extends Model
{
    use HasFactory, ScopedByBusiness;

    protected $fillable = [
        'business_id',
        'customer_id',
        'card_number',
        'pin',
        'initial_amount',
        'current_balance',
        'status',
        'issued_by',
        'issued_at',
        'expires_at',
        'branch_id',
    ];

    protected $casts = [
        'initial_amount' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'pin',
    ];

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function transactions()
    {
        return $this->hasMany(GiftCardTransaction::class);
    }

    // Helper Methods
    public function hasBalance($amount)
    {
        return $this->current_balance >= $amount && $this->status === 'active';
    }

    public function isExpired()
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function verifyPin($pin)
    {
        return Hash::check($pin, $this->pin);
    }

    public function setPin($pin)
    {
        $this->pin = Hash::make($pin);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    // Auto-generate card number and set issued_at
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($giftCard) {
            if (empty($giftCard->card_number)) {
                $giftCard->card_number = self::generateCardNumber();
            }

            if (empty($giftCard->issued_at)) {
                $giftCard->issued_at = now();
            }

            $giftCard->current_balance = $giftCard->initial_amount;
        });

        static::deleting(function ($giftCard) {
            $giftCard->transactions()->delete();
        });
    }

    private static function generateCardNumber()
    {
        do {
            // Generate 16-digit number
            $number = '';
            for ($i = 0; $i < 16; $i++) {
                $number .= rand(0, 9);
            }
        } while (self::where('card_number', $number)->exists());

        return $number;
    }
}
