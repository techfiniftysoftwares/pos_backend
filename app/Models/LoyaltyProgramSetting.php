<?php

namespace App\Models;

use App\Models\Traits\ScopedByBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyProgramSetting extends Model
{
    use HasFactory, ScopedByBusiness;

    protected $fillable = [
        'business_id',
        'points_per_currency',
        'currency_per_point',
        'minimum_redemption_points',
        'point_expiry_months',
        'is_active',
        'bonus_multiplier_days',
        'allow_partial_redemption',
        'maximum_redemption_percentage',
    ];

    protected $casts = [
        'points_per_currency' => 'decimal:2',
        'currency_per_point' => 'decimal:4',
        'minimum_redemption_points' => 'integer',
        'point_expiry_months' => 'integer',
        'is_active' => 'boolean',
        'bonus_multiplier_days' => 'array',
        'allow_partial_redemption' => 'boolean',
        'maximum_redemption_percentage' => 'decimal:2',
    ];

    protected $attributes = [
        'points_per_currency' => 1.00,
        'currency_per_point' => 0.01,
        'minimum_redemption_points' => 100,
        'is_active' => true,
        'allow_partial_redemption' => true,
        'maximum_redemption_percentage' => 100.00,
    ];

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    // Helper Methods

    /**
     * Calculate points earned for a given amount
     */
    public function calculatePointsEarned($amount, $dayOfWeek = null)
    {
        $basePoints = $amount * $this->points_per_currency;

        // Apply day-of-week multiplier if configured
        if ($dayOfWeek && $this->bonus_multiplier_days) {
            $dayName = strtolower($dayOfWeek);
            $multiplier = $this->bonus_multiplier_days[$dayName] ?? 1;
            $basePoints *= $multiplier;
        }

        return (int) floor($basePoints);
    }

    /**
     * Calculate currency value of points
     */
    public function calculateCurrencyValue($points)
    {
        return $points * $this->currency_per_point;
    }

    /**
     * Check if points are sufficient for redemption
     */
    public function canRedeemPoints($points)
    {
        return $this->is_active && $points >= $this->minimum_redemption_points;
    }

    /**
     * Calculate maximum redeemable amount for a purchase
     */
    public function getMaximumRedeemableAmount($purchaseAmount, $availablePoints)
    {
        $maxByPercentage = $purchaseAmount * ($this->maximum_redemption_percentage / 100);
        $maxByPoints = $this->calculateCurrencyValue($availablePoints);

        return min($maxByPercentage, $maxByPoints);
    }

    /**
     * Get point expiration date
     */
    public function getPointExpirationDate()
    {
        if (!$this->point_expiry_months) {
            return null;
        }

        return now()->addMonths($this->point_expiry_months);
    }

    /**
     * Get bonus multiplier for today
     */
    public function getTodayMultiplier()
    {
        if (!$this->bonus_multiplier_days) {
            return 1;
        }

        $today = strtolower(now()->format('l')); // 'monday', 'tuesday', etc.
        return $this->bonus_multiplier_days[$today] ?? 1;
    }

    // Boot method
    protected static function boot()
    {
        parent::boot();

        // Ensure only one loyalty program setting per business
        static::creating(function ($setting) {
            $existing = self::where('business_id', $setting->business_id)->first();
            if ($existing) {
                throw new \Exception('Loyalty program settings already exist for this business. Use update instead.');
            }
        });
    }
}
