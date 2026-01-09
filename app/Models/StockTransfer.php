<?php

namespace App\Models;

use App\Models\Traits\ScopedByBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockTransfer extends Model
{
    use HasFactory, ScopedByBusiness;

    protected $fillable = [
        'transfer_number',
        'business_id',
        'from_branch_id',
        'to_branch_id',
        'initiated_by',
        'approved_by',
        'received_by',
        'status',
        'transfer_date',
        'expected_delivery_date',
        'approved_at',
        'completed_at',
        'transfer_reason',
        'notes',
        'cancellation_reason',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'expected_delivery_date' => 'date',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    // Scopes
    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeFromBranch($query, $branchId)
    {
        return $query->where('from_branch_id', $branchId);
    }

    public function scopeToBranch($query, $branchId)
    {
        return $query->where('to_branch_id', $branchId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInTransit($query)
    {
        return $query->where('status', 'in_transit');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    // Accessors
    public function getIsPendingAttribute()
    {
        return $this->status === 'pending';
    }

    public function getIsApprovedAttribute()
    {
        return in_array($this->status, ['approved', 'in_transit', 'completed']);
    }

    public function getIsCompletedAttribute()
    {
        return $this->status === 'completed';
    }

    public function getIsCancelledAttribute()
    {
        return $this->status === 'cancelled';
    }

    public function getTotalItemsAttribute()
    {
        return $this->items()->count();
    }
}
