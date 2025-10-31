<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'category_id',
        'unit_id',
        'supplier_id',
        'name',
        'sku',
        'barcode',
        'description',
        'image',
        'cost_price',
        'selling_price',
        'minimum_stock_level',
        'tax_rate',
        'track_inventory',
        'allow_negative_stock',
        'is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'track_inventory' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'profit_margin',
        'profit_percentage',
    ];

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    // Will add later when Stock model exists
    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }
    /**
 * Get stock for specific branch
 */
public function stockAtBranch($branchId)
{
    return $this->hasOne(Stock::class)->where('branch_id', $branchId);
}

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeLowStock($query)
    {
        return $query->whereRaw('minimum_stock_level > 0')
                     ->where('track_inventory', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('sku', 'like', "%{$search}%")
              ->orWhere('barcode', 'like', "%{$search}%");
        });
    }

    // Accessors
    public function getProfitMarginAttribute()
    {
        return $this->selling_price - $this->cost_price;
    }

    public function getProfitPercentageAttribute()
    {
        if ($this->cost_price == 0) {
            return 0;
        }
        return (($this->selling_price - $this->cost_price) / $this->cost_price) * 100;
    }

    public function getPriceWithTaxAttribute()
    {
        return $this->selling_price * (1 + ($this->tax_rate / 100));
    }

    // Helper Methods
    public function generateSKU()
    {
        $prefix = strtoupper(substr($this->category->name ?? 'PRD', 0, 3));
        $count = Product::where('business_id', $this->business_id)->count();
        return $prefix . str_pad($count + 1, 6, '0', STR_PAD_LEFT);
    }
}
