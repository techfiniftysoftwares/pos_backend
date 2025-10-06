<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeldSale extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'user_id', 'hold_number', 'sale_data', 'notes'
    ];

    protected $casts = ['sale_data' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
