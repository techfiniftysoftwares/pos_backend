<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'branch_id',
        'supplier_id',
        'purchase_number',
        'purchase_date',
        'expected_delivery_date',
        'received_date',
        'subtotal',
        'tax_amount',
        'total_amount',
        'tax_inclusive',
        'currency',
        'currency_id', // Added currency_id
        'exchange_rate',
        'status',
        'payment_status',
        'notes',
        'invoice_number',
        'metadata',
        'created_by',
        'received_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'expected_delivery_date' => 'date',
        'received_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'tax_inclusive' => 'boolean',
        'exchange_rate' => 'decimal:4',
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

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function currencyModel()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    // Scopes
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeOrdered($query)
    {
        return $query->where('status', 'ordered');
    }

    public function scopeReceived($query)
    {
        return $query->where('status', 'received');
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    // Boot
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($purchase) {
            if (empty($purchase->purchase_number)) {
                $purchase->purchase_number = self::generatePurchaseNumber($purchase->business_id);
            }
        });
    }

    private static function generatePurchaseNumber($businessId)
    {
        $date = now()->format('Ymd');
        $count = self::where('business_id', $businessId)
            ->whereDate('created_at', now())
            ->count();

        return 'PO-' . $date . '-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);
    }
}
