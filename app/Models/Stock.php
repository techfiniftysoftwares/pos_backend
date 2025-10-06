<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'branch_id',
        'product_id',
        'quantity',
        'reserved_quantity',
        'unit_cost',
        'last_restocked_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'reserved_quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'last_restocked_at' => 'datetime',
    ];

    protected $appends = [
        'available_quantity',
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

    public function movements()
    {
        return $this->hasMany(StockMovement::class, 'product_id', 'product_id')
                    ->where('branch_id', $this->branch_id);
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

    public function scopeLowStock($query)
    {
        return $query->whereHas('product', function($q) {
            $q->where('track_inventory', true)
              ->whereColumn('stocks.quantity', '<', 'products.minimum_stock_level');
        });
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('quantity', '<=', 0);
    }

    // Accessors
    public function getAvailableQuantityAttribute()
    {
        return max(0, (float) $this->quantity - (float) $this->reserved_quantity);
    }

    public function getIsLowStockAttribute()
    {
        return $this->product->track_inventory &&
               $this->quantity < $this->product->minimum_stock_level;
    }

    public function getIsOutOfStockAttribute()
    {
        return $this->available_quantity <= 0;
    }

    public function getStockValueAttribute()
    {
        return (float) $this->quantity * (float) $this->unit_cost;
    }

    /**
     * Get or create stock record for product at branch
     */
    public static function getOrCreate($businessId, $branchId, $productId)
    {
        return self::firstOrCreate(
            [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'product_id' => $productId,
            ],
            [
                'quantity' => 0,
                'reserved_quantity' => 0,
                'unit_cost' => 0,
            ]
        );
    }
}
