<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'branch_id',
        'customer_id',
        'user_id',
        'sale_number',
        'invoice_number',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'currency_id', // 🆕 ADDED
        'currency',
        'exchange_rate',
        'total_in_base_currency',
        'status',
        'payment_status',
        'payment_type',
        'is_credit_sale',
        'credit_due_date',
        'notes',
        'metadata',
        'completed_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'total_in_base_currency' => 'decimal:2',
        'is_credit_sale' => 'boolean',
        'credit_due_date' => 'date',
        'completed_at' => 'datetime',
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

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // 🆕 ADDED
    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function salePayments()
    {
        return $this->hasMany(SalePayment::class);
    }

    public function payments()
    {
        return $this->hasManyThrough(
            Payment::class,
            SalePayment::class,
            'sale_id',
            'id',
            'id',
            'payment_id'
        );
    }

    public function return()
    {
        return $this->hasOne(ReturnTransaction::class, 'original_sale_id');
    }

    public function discounts()
    {
        return $this->belongsToMany(Discount::class, 'sale_discounts')
            ->withPivot('discount_amount')
            ->withTimestamps();
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCreditSales($query)
    {
        return $query->where('is_credit_sale', true);
    }

    public function scopeOverdueCredit($query)
    {
        return $query->where('is_credit_sale', true)
            ->where('payment_status', '!=', 'paid')
            ->where('credit_due_date', '<', now());
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeDateRange($query, $from, $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    // Helper Methods
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isCreditSale()
    {
        return $this->is_credit_sale === true;
    }

    public function isFullyPaid()
    {
        return $this->payment_status === 'paid';
    }

    // 🆕 UPDATED - Use amount_in_sale_currency instead of amount
    public function getRemainingBalance()
    {
        $totalPaid = $this->salePayments()->sum('amount_in_sale_currency');
        return $this->total_amount - $totalPaid;
    }

    // 🆕 ADDED - Get total paid in sale currency
    public function getTotalPaidInSaleCurrency()
    {
        return $this->salePayments()->sum('amount_in_sale_currency');
    }

    public function canBeReturned()
    {
        return $this->isCompleted() && !$this->return;
    }

    public function canBeCancelled()
    {
        return $this->isPending();
    }

    // Boot method
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sale) {
            // Generate sale number
            if (empty($sale->sale_number)) {
                $sale->sale_number = self::generateSaleNumber($sale->business_id, $sale->branch_id);
            }

            // Calculate base currency amount
            if ($sale->exchange_rate && $sale->exchange_rate != 1) {
                $sale->total_in_base_currency = $sale->total_amount * $sale->exchange_rate;
            } else {
                $sale->total_in_base_currency = $sale->total_amount;
            }

            // Set credit sale flag based on payment type
            if ($sale->payment_type === 'credit') {
                $sale->is_credit_sale = true;
                // Set default due date if not provided (30 days from now)
                if (empty($sale->credit_due_date)) {
                    $sale->credit_due_date = now()->addDays(30);
                }
            }
        });
    }

    private static function generateSaleNumber($businessId, $branchId)
    {
        $date = now()->format('Ymd');
        $count = self::where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->whereDate('created_at', now())
            ->count();

        $branch = Branch::find($branchId);
        $branchCode = $branch?->code ?? 'BR';

        return 'SALE-' . $branchCode . '-' . $date . '-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);
    }
}
