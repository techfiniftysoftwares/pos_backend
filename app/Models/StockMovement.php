<?php

namespace App\Models;

use App\Models\Traits\ScopedByBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockMovement extends Model
{
    use HasFactory, ScopedByBusiness;

    protected $fillable = [
        'business_id',
        'branch_id',
        'product_id',
        'user_id',
        'movement_type',
        'quantity',
        'previous_quantity',
        'new_quantity',
        'unit_cost',
        'reference_type',
        'reference_id',
        'reason',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'previous_quantity' => 'decimal:2',
        'new_quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reference()
    {
        return $this->morphTo();
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

    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('movement_type', $type);
    }

    public function scopeStockIn($query)
    {
        return $query->whereIn('movement_type', ['purchase', 'transfer_in', 'return_in', 'adjustment', 'cancellation'])
            ->where('quantity', '>', 0);
    }

    public function scopeStockOut($query)
    {
        return $query->whereIn('movement_type', ['sale', 'transfer_out', 'return_out', 'damage', 'expired', 'adjustment'])
            ->where('quantity', '<', 0);
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    // Accessors
    public function getIsStockInAttribute()
    {
        return $this->quantity > 0;
    }

    public function getIsStockOutAttribute()
    {
        return $this->quantity < 0;
    }

    public function getAbsoluteQuantityAttribute()
    {
        return abs((float) $this->quantity);
    }
}
