<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'transaction_type',
        'points',
        'previous_balance',
        'new_balance',
        'reference_type',
        'reference_id',
        'expires_at',
        'processed_by',
        'branch_id',
        'notes',
    ];

    protected $casts = [
        'points' => 'integer',
        'previous_balance' => 'integer',
        'new_balance' => 'integer',
        'expires_at' => 'datetime',
    ];

    // Relationships
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // Polymorphic relationship for reference
    public function referenceable()
    {
        return $this->morphTo('reference');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where(function($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
                     ->where('expires_at', '<=', now());
    }

    // Auto-calculate balances
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($pointTransaction) {
            $customer = Customer::find($pointTransaction->customer_id);

            // Get current balance
            $pointTransaction->previous_balance = $customer->getCurrentPointsBalance();
            $pointTransaction->new_balance = $pointTransaction->previous_balance + $pointTransaction->points;
        });
    }
}
