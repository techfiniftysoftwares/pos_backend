<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'code',
        'phone',
        'address',
        'is_active',
        'is_main_branch'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_main_branch' => 'boolean'
    ];

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_branches');
    }

    public function primaryUsers()
    {
        return $this->hasMany(User::class, 'primary_branch_id');
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

    public function scopeMainBranches($query)
    {
        return $query->where('is_main_branch', true);
    }

    // Helper methods
    public function isActive(): bool
    {
        return $this->is_active && $this->business->isActive();
    }

    protected static function boot()
    {
        parent::boot();

        // Generate unique branch code if not provided
        static::creating(function ($branch) {
            if (empty($branch->code)) {
                $branch->code = static::generateBranchCode($branch->business_id);
            }
        });

        // Ensure only one main branch per business
        static::saving(function ($branch) {
            if ($branch->is_main_branch) {
                static::where('business_id', $branch->business_id)
                    ->where('id', '!=', $branch->id)
                    ->update(['is_main_branch' => false]);
            }
        });
    }

    private static function generateBranchCode($businessId): string
    {
        $business = Business::find($businessId);
        $businessPrefix = strtoupper(substr($business->name, 0, 3));
        $branchCount = static::where('business_id', $businessId)->count() + 1;

        return $businessPrefix . str_pad($branchCount, 3, '0', STR_PAD_LEFT);
    }
}
