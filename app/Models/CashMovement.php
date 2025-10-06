<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_reconciliation_id',
        'business_id',
        'branch_id',
        'movement_type',
        'amount',
        'currency',
        'reason',
        'reference_number',
        'notes',
        'processed_by',
        'movement_time',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'movement_time' => 'datetime',
    ];

    // Relationships
    public function cashReconciliation()
    {
        return $this->belongsTo(CashReconciliation::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // Scopes
    public function scopeCashIn($query)
    {
        return $query->where('movement_type', 'cash_in');
    }

    public function scopeCashOut($query)
    {
        return $query->where('movement_type', 'cash_out');
    }

    public function scopeCashDrop($query)
    {
        return $query->where('movement_type', 'cash_drop');
    }

    // Boot
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($movement) {
            if (empty($movement->movement_time)) {
                $movement->movement_time = now();
            }
        });
    }
}
