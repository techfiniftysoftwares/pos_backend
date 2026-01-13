<?php

namespace App\Models;

use App\Models\Traits\ScopedByBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RevenueEntry extends Model
{
    use HasFactory, SoftDeletes, ScopedByBusiness;

    protected $fillable = [
        'business_id',
        'branch_id',
        'revenue_stream_id',
        'entry_number',
        'amount',
        'currency_id',
        'exchange_rate',
        'amount_in_base_currency',
        'entry_date',
        'receipt_number',
        'receipt_attachment',
        'notes',
        'status',
        'recorded_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:10',
        'amount_in_base_currency' => 'decimal:2',
        'entry_date' => 'date',
        'approved_at' => 'datetime',
    ];

    /**
     * Relationships
     */
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function revenueStream()
    {
        return $this->belongsTo(RevenueStream::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForStream($query, $streamId)
    {
        return $query->where('revenue_stream_id', $streamId);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('entry_date', [$startDate, $endDate]);
    }

    /**
     * Generate unique entry number for the business
     * Format: REV-YYYYMMDD-XXXXX
     */
    public static function generateEntryNumber($businessId)
    {
        $datePrefix = now()->format('Ymd');
        $prefix = "REV-{$datePrefix}-";

        // Get the last entry number for this business today
        $lastEntry = static::where('business_id', $businessId)
            ->where('entry_number', 'like', $prefix . '%')
            ->orderBy('entry_number', 'desc')
            ->first();

        if ($lastEntry) {
            // Extract the sequence number and increment
            $lastSequence = (int) substr($lastEntry->entry_number, -5);
            $newSequence = $lastSequence + 1;
        } else {
            $newSequence = 1;
        }

        return $prefix . str_pad($newSequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Boot method - auto-generate entry number and calculate base currency amount
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($entry) {
            // Auto-generate entry number
            if (empty($entry->entry_number)) {
                $entry->entry_number = static::generateEntryNumber($entry->business_id);
            }

            // Auto-calculate amount in base currency
            if ($entry->exchange_rate && $entry->amount) {
                $entry->amount_in_base_currency = round($entry->amount * $entry->exchange_rate, 2);
            }
        });

        static::updating(function ($entry) {
            // Recalculate if amount or exchange_rate changed
            if ($entry->isDirty(['amount', 'exchange_rate'])) {
                $entry->amount_in_base_currency = round($entry->amount * $entry->exchange_rate, 2);
            }
        });
    }
}
