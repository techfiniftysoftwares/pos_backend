<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'payment_id',
        'amount',
        'currency_id', // 🆕 ADDED
        'exchange_rate', // 🆕 ADDED
        'amount_in_sale_currency', // 🆕 ADDED
    ];

   protected $casts = [
    'amount' => 'decimal:2',
    'exchange_rate' => 'decimal:10',  // ✅ Changed from decimal:4 to decimal:10
    'amount_in_sale_currency' => 'decimal:2',
];
    // Relationships
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    // 🆕 ADDED
    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}