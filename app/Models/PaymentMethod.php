<?php

namespace App\Models;

use App\Models\Traits\ScopedByBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends Model
{
    use HasFactory, SoftDeletes, ScopedByBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'type',
        'code',
        'description',
        'is_active',
        'is_default',
        'requires_reference',
        'transaction_fee_percentage',
        'transaction_fee_fixed',
        'minimum_amount',
        'maximum_amount',
        'supported_currencies',
        'icon',
        'sort_order',
        'config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'requires_reference' => 'boolean',
        'transaction_fee_percentage' => 'decimal:2',
        'transaction_fee_fixed' => 'decimal:2',
        'minimum_amount' => 'decimal:2',
        'maximum_amount' => 'decimal:2',
        'supported_currencies' => 'array',
        'config' => 'array',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
        'requires_reference' => false,
        'transaction_fee_percentage' => 0,
        'transaction_fee_fixed' => 0,
        'sort_order' => 0,
    ];

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function creditTransactions()
    {
        return $this->hasMany(CustomerCreditTransaction::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function cashReconciliations()
    {
        return $this->hasMany(CashReconciliation::class);
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

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // Helper Methods

    /**
     * Calculate transaction fee for a given amount
     */
    public function calculateFee($amount)
    {
        $percentageFee = $amount * ($this->transaction_fee_percentage / 100);
        $totalFee = $percentageFee + $this->transaction_fee_fixed;

        return round($totalFee, 2);
    }

    /**
     * Calculate net amount after fees
     */
    public function calculateNetAmount($amount)
    {
        return $amount - $this->calculateFee($amount);
    }

    /**
     * Check if amount is within limits
     */
    public function isAmountValid($amount)
    {
        if ($this->minimum_amount && $amount < $this->minimum_amount) {
            return false;
        }

        if ($this->maximum_amount && $amount > $this->maximum_amount) {
            return false;
        }

        return true;
    }

    /**
     * Check if currency is supported
     */
    public function supportsCurrency($currency)
    {
        if (empty($this->supported_currencies)) {
            return true; // No restrictions
        }

        return in_array($currency, $this->supported_currencies);
    }

    /**
     * Check if payment method is cash type
     */
    public function isCash()
    {
        return $this->type === 'cash';
    }

    /**
     * Check if payment method is card type
     */
    public function isCard()
    {
        return in_array($this->type, ['card', 'credit_card', 'debit_card']);
    }

    /**
     * Check if payment method is mobile money
     */
    public function isMobileMoney()
    {
        return $this->type === 'mobile_money';
    }

    /**
     * Get configuration value
     */
    public function getConfig($key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set configuration value
     */
    public function setConfig($key, $value)
    {
        $config = $this->config ?? [];
        $config[$key] = $value;
        $this->config = $config;
    }

    // Boot method
    protected static function boot()
    {
        parent::boot();

        // Auto-generate code if not provided
        static::creating(function ($paymentMethod) {
            if (empty($paymentMethod->code)) {
                $paymentMethod->code = strtoupper(str_replace(' ', '_', $paymentMethod->name));
            }

            // Set sort order to last if not specified
            if ($paymentMethod->sort_order === 0) {
                $maxOrder = self::where('business_id', $paymentMethod->business_id)->max('sort_order');
                $paymentMethod->sort_order = ($maxOrder ?? 0) + 1;
            }
        });

        // Prevent deleting if used in transactions
        static::deleting(function ($paymentMethod) {
            if ($paymentMethod->creditTransactions()->count() > 0 || $paymentMethod->payments()->count() > 0) {
                throw new \Exception('Cannot delete payment method that has been used in transactions');
            }
        });
    }
}
