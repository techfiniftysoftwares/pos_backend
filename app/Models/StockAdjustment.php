<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'branch_id',
        'product_id',
        'adjusted_by',
        'approved_by',
        'adjustment_type',
        'quantity_adjusted',
        'before_quantity',
        'after_quantity',
        'reason',
        'cost_impact',
        'notes',
        'approved_at',
    ];

    protected $casts = [
        'quantity_adjusted' => 'decimal:2',
        'before_quantity' => 'decimal:2',
        'after_quantity' => 'decimal:2',
        'cost_impact' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function adjustedBy()
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes
    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopePending($query)
    {
        return $query->whereNull('approved_at');
    }

    public function scopeApproved($query)
    {
        return $query->whereNotNull('approved_at');
    }

    public function scopeByReason($query, $reason)
    {
        return $query->where('reason', $reason);
    }

    // Accessors
    public function getIsApprovedAttribute()
    {
        return !is_null($this->approved_at);
    }
}
