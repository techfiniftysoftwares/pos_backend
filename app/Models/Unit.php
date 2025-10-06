<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Unit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'name',
        'symbol',
        'base_unit_id',
        'conversion_factor',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'conversion_factor' => 'decimal:4',
    ];

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function baseUnit()
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function derivedUnits()
    {
        return $this->hasMany(Unit::class, 'base_unit_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
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

    // Helper method for conversion
    public function convertTo($quantity, Unit $targetUnit)
    {
        if ($this->id === $targetUnit->id) {
            return $quantity;
        }

        if ($this->base_unit_id === $targetUnit->id) {
            return $quantity * $this->conversion_factor;
        }

        if ($targetUnit->base_unit_id === $this->id) {
            return $quantity / $targetUnit->conversion_factor;
        }

        return $quantity; // No conversion available
    }
}
