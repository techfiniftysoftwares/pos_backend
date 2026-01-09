<?php

namespace App\Models;

use App\Models\Traits\ScopedByBusiness;
use Illuminate\Database\Eloquent\Model;

class ReturnTransaction extends Model
{
    use ScopedByBusiness;
    protected $fillable = [
        'business_id',
        'branch_id',
        'original_sale_id',
        'return_number',
        'total_amount',
        'reason',
        'status',
        'processed_by',
        'notes'
    ];

    protected $casts = ['total_amount' => 'decimal:2'];

    public function originalSale()
    {
        return $this->belongsTo(Sale::class, 'original_sale_id');
    }

    public function items()
    {
        return $this->hasMany(ReturnItem::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
