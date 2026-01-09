<?php

namespace App\Models;

use App\Models\Traits\ScopedByBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Supplier extends Model
{
    use HasFactory, SoftDeletes, ScopedByBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'email',
        'phone',
        'address',
        'contact_person',
        'tax_number',
        'payment_terms',
        'credit_limit',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credit_limit' => 'decimal:2',
    ];

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    // Will add later when Purchase model exists
    // public function purchases()
    // {
    //     return $this->hasMany(Purchase::class);
    // }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    // Accessors
    public function getProductsCountAttribute()
    {
        return $this->products()->count();
    }
}
