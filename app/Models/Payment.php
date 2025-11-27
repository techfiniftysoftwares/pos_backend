<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'branch_id',
        'payment_method_id',
        'customer_id',
        'reference_number',
        'transaction_id',
        'amount',
        'currency_id', // 🆕 ADDED
        'currency',
        'exchange_rate',
        'amount_in_base_currency',
        'fee_amount',
        'net_amount',
        'status',
        'payment_type',
        'reference_type',
        'reference_id',
        'payment_date',
        'reconciled_at',
        'reconciled_by',
        'failure_reason',
        'notes',
        'metadata',
        'processed_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'amount_in_base_currency' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'reconciled_at' => 'datetime',
        'metadata' => 'array',
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

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    // 🆕 ADDED
    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function reconciledBy()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function refunds()
    {
        return $this->hasMany(Payment::class, 'reference_id')
            ->where('payment_type', 'refund');
    }

    public function originalPayment()
    {
        return $this->belongsTo(Payment::class, 'reference_id');
    }

    // Polymorphic relationship for reference
    public function referenceable()
    {
        return $this->morphTo('reference');
    }

    // Scopes
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeReconciled($query)
    {
        return $query->whereNotNull('reconciled_at');
    }

    public function scopeUnreconciled($query)
    {
        return $query->whereNull('reconciled_at')
            ->where('status', 'completed');
    }

    public function scopeByPaymentType($query, $type)
    {
        return $query->where('payment_type', $type);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeDateRange($query, $from, $to)
    {
        return $query->whereBetween('payment_date', [$from, $to]);
    }

    // Helper Methods

    /**
     * Check if payment is successful
     */
    public function isSuccessful()
    {
        return $this->status === 'completed';
    }

    /**
     * Check if payment is pending
     */
    public function isPending()
    {
        return $this->status === 'pending';
    }

    /**
     * Check if payment is failed
     */
    public function isFailed()
    {
        return $this->status === 'failed';
    }

    /**
     * Check if payment is reconciled
     */
    public function isReconciled()
    {
        return !is_null($this->reconciled_at);
    }

    /**
     * Check if payment can be refunded
     */
    public function canBeRefunded()
    {
        return $this->isSuccessful()
            && $this->payment_type === 'payment'
            && $this->refunds()->sum('amount') < $this->amount;
    }

    /**
     * Get remaining refundable amount
     */
    public function getRefundableAmount()
    {
        $totalRefunded = $this->refunds()->sum('amount');
        return $this->amount - $totalRefunded;
    }

    /**
     * Mark as reconciled
     */
    public function markAsReconciled($userId)
    {
        $this->reconciled_at = now();
        $this->reconciled_by = $userId;
        $this->save();
    }

    /**
     * Mark as failed
     */
    public function markAsFailed($reason)
    {
        $this->status = 'failed';
        $this->failure_reason = $reason;
        $this->save();
    }

    /**
     * Get metadata value
     */
    public function getMetadata($key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Set metadata value
     */
    public function setMetadata($key, $value)
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $value;
        $this->metadata = $metadata;
    }

    // Boot method
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            // Auto-generate reference number if not provided
            if (empty($payment->reference_number)) {
                $payment->reference_number = self::generateReferenceNumber($payment->business_id);
            }

            // Set payment date to now if not provided
            if (empty($payment->payment_date)) {
                $payment->payment_date = now();
            }

            // Calculate net amount if not provided
            if (is_null($payment->net_amount)) {
                $payment->net_amount = $payment->amount - ($payment->fee_amount ?? 0);
            }

            // Set base currency amount if using exchange rate
            if ($payment->exchange_rate && $payment->exchange_rate != 1) {
                $payment->amount_in_base_currency = $payment->amount * $payment->exchange_rate;
            } else {
                $payment->amount_in_base_currency = $payment->amount;
            }
        });
    }

    /**
     * Generate unique reference number
     */
    private static function generateReferenceNumber($businessId)
    {
        $date = now()->format('Ymd');
        $count = self::where('business_id', $businessId)
            ->whereDate('created_at', now())
            ->count();

        return 'PAY-' . $date . '-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);
    }
}
