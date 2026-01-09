<?php

namespace App\Models;

use App\Models\Traits\ScopedByBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockBatch extends Model
{
    use HasFactory, ScopedByBusiness;

    protected $fillable = [
        'business_id',
        'branch_id',
        'stock_id',
        'product_id',
        'purchase_item_id',
        'batch_number',
        'purchase_reference',
        'quantity_received',
        'quantity_remaining',
        'unit_cost',
        'received_date',
        'expiry_date',
        'notes',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:2',
        'quantity_remaining' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'received_date' => 'date',
        'expiry_date' => 'date',
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

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseItem()
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('quantity_remaining', '>', 0);
    }

    public function scopeFifoOrder($query)
    {
        return $query->orderBy('received_date', 'asc');
    }

    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeExpiringSoon($query, $days = 30)
    {
        return $query->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays($days))
            ->where('quantity_remaining', '>', 0);
    }

    // Helper Methods
    public function isFullyUsed()
    {
        return $this->quantity_remaining <= 0;
    }

    public function isExpired()
    {
        return $this->expiry_date && now()->gt($this->expiry_date);
    }

    public function getRemainingValue()
    {
        return $this->quantity_remaining * $this->unit_cost;
    }

    // Boot
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($batch) {
            if (empty($batch->batch_number)) {
                $batch->batch_number = self::generateBatchNumber($batch->business_id);
            }

            // Set quantity_remaining equal to quantity_received on creation
            if (is_null($batch->quantity_remaining)) {
                $batch->quantity_remaining = $batch->quantity_received;
            }
        });
    }

    private static function generateBatchNumber($businessId)
    {
        $date = now()->format('Ymd');
        $count = self::where('business_id', $businessId)
            ->whereDate('created_at', now())
            ->count();

        return 'BATCH-' . $date . '-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);
    }
}