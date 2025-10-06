<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashReconciliation extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'branch_id',
        'user_id',
        'reconciliation_date',
        'shift_type',
        'opening_float',
        'expected_cash',
        'actual_cash',
        'variance',
        'cash_sales',
        'cash_payments_received',
        'cash_refunds',
        'cash_expenses',
        'cash_drops',
        'currency',
        'currency_breakdown',
        'status',
        'notes',
        'reconciled_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'reconciliation_date' => 'datetime',
        'opening_float' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'actual_cash' => 'decimal:2',
        'variance' => 'decimal:2',
        'cash_sales' => 'decimal:2',
        'cash_payments_received' => 'decimal:2',
        'cash_refunds' => 'decimal:2',
        'cash_expenses' => 'decimal:2',
        'cash_drops' => 'decimal:2',
        'currency_breakdown' => 'array',
        'approved_at' => 'datetime',
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reconciledBy()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cashMovements()
    {
        return $this->hasMany(CashMovement::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeDisputed($query)
    {
        return $query->where('status', 'disputed');
    }

    // Boot method
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($reconciliation) {
            if (empty($reconciliation->reconciliation_date)) {
                $reconciliation->reconciliation_date = now()->toDateString();
            }

            if (empty($reconciliation->status)) {
                $reconciliation->status = 'pending';
            }

            if (empty($reconciliation->shift_type)) {
                $reconciliation->shift_type = 'full_day';
            }
        });

        static::saving(function ($reconciliation) {
            // Auto-calculate expected cash
            $reconciliation->expected_cash = $reconciliation->opening_float
                + $reconciliation->cash_sales
                + $reconciliation->cash_payments_received
                - $reconciliation->cash_refunds
                - $reconciliation->cash_expenses
                - $reconciliation->cash_drops;

            // Auto-calculate variance
            $reconciliation->variance = $reconciliation->actual_cash - $reconciliation->expected_cash;
        });

        static::deleting(function ($reconciliation) {
            $reconciliation->cashMovements()->delete();
        });
    }
}
