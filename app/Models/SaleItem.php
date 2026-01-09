<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'product_id',
        'quantity',
        'unit_price',
        'unit_cost',        // NEW: Cost basis for profit calculation
        'tax_rate',
        'tax_amount',
        'tax_inclusive',    // Store whether tax was inclusive at time of sale
        'tax_name',         // Store the tax name at time of sale
        'discount_amount',
        'line_total'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',     // NEW
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_inclusive' => 'boolean',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Helper: Calculate profit for this item
    public function getProfit()
    {
        if (!$this->unit_cost) {
            return 0;
        }

        return ($this->unit_price - $this->unit_cost) * $this->quantity;
    }

    // Helper: Calculate profit margin percentage
    public function getProfitMarginPercentage()
    {
        if (!$this->unit_cost || $this->unit_price <= 0) {
            return 0;
        }

        return (($this->unit_price - $this->unit_cost) / $this->unit_price) * 100;
    }
}
