<?php

namespace App\Models;

use App\Models\Traits\ScopedByBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes, ScopedByBusiness;

    protected $fillable = [
        'business_id',
        'customer_number',
        'name',
        'email',
        'phone',
        'secondary_phone',
        'address',
        'city',
        'country',
        'customer_type',
        'credit_limit',
        'current_credit_balance',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'current_credit_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'customer_type' => 'regular',
        'current_credit_balance' => 0,
        'is_active' => true,
    ];

    protected $appends = [
        'utilized_credit',
        'available_credit',
    ];

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function creditTransactions()
    {
        return $this->hasMany(CustomerCreditTransaction::class);
    }

    public function points()
    {
        return $this->hasMany(CustomerPoint::class);
    }

    public function giftCards()
    {
        return $this->hasMany(GiftCard::class);
    }

    // Add segments relationship if you need customer segmentation
    public function segments()
    {
        return $this->belongsToMany(CustomerSegment::class, 'customer_segment_assignments');
    }

    // Helper Methods
    public function getCurrentPointsBalance()
    {
        return $this->points()->sum('points');
    }

    public function hasAvailableCredit($amount)
    {
        // If credit_limit is null, customer has unlimited credit
        if (is_null($this->credit_limit)) {
            return true;
        }
        return ($this->credit_limit - $this->current_credit_balance) >= $amount;
    }

    public function isVip()
    {
        return $this->customer_type === 'vip';
    }

    public function isWholesale()
    {
        return $this->customer_type === 'wholesale';
    }

    // Auto-generate customer number on creation
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($customer) {
            if (empty($customer->customer_number)) {
                $customer->customer_number = self::generateCustomerNumber($customer->business_id);
            }
        });

        static::deleting(function ($customer) {
            // Delete related records when customer is deleted
            $customer->creditTransactions()->delete();
            $customer->points()->delete();
        });
    }

    private static function generateCustomerNumber($businessId)
    {
        $business = Business::find($businessId);
        $prefix = strtoupper(substr($business->name, 0, 2));
        $count = self::where('business_id', $businessId)->withTrashed()->count();

        return 'CUST-' . $prefix . '-' . str_pad($count + 1, 6, '0', STR_PAD_LEFT);
    }
    public function getUtilizedCreditAttribute()
    {
        // current_credit_balance is positive for debt (sales add to it, payments subtract)
        // Utilized = the amount of credit used (debt)
        return max(0, (float) $this->current_credit_balance);
    }

    public function getAvailableCreditAttribute()
    {
        // If credit_limit is null, customer has unlimited credit
        if (is_null($this->credit_limit)) {
            return null; // null indicates unlimited credit
        }
        // Available = Limit - Balance (Balance is positive debt)
        return max(0, (float) $this->credit_limit - (float) $this->current_credit_balance);
    }
}
