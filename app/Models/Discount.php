<?php

namespace App\Models;

use App\Models\Traits\ScopedByBusiness;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use ScopedByBusiness;
    protected $fillable = [
        'business_id',
        'name',
        'code',
        'type',
        'value',
        'applies_to',
        'target_ids',
        'minimum_amount',
        'maximum_uses',
        'uses_count',
        'start_date',
        'end_date',
        'is_active'
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'minimum_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'target_ids' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
    ];
}
