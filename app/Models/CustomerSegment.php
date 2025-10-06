<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerSegment extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'criteria',
        'description',
        'is_active',
    ];

    protected $casts = [
        'criteria' => 'array',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function customers()
    {
        return $this->belongsToMany(Customer::class, 'customer_segment')
                    ->withPivot('assigned_at', 'assigned_by')
                    ->withTimestamps();
    }

    // Helper Methods
    public function assignCustomer($customerId, $assignedBy = null)
    {
        return $this->customers()->attach($customerId, [
            'assigned_at' => now(),
            'assigned_by' => $assignedBy,
        ]);
    }

    public function removeCustomer($customerId)
    {
        return $this->customers()->detach($customerId);
    }

    public function hasCustomer($customerId)
    {
        return $this->customers()->where('customer_id', $customerId)->exists();
    }

    /**
     * Evaluate criteria and return matching customers
     * Example criteria structure:
     * {
     *   "total_purchases": {"operator": ">=", "value": 10000},
     *   "customer_type": {"operator": "=", "value": "vip"},
     *   "last_purchase_days": {"operator": "<=", "value": 30}
     * }
     */
    public function evaluateCriteria()
    {
        if (empty($this->criteria)) {
            return collect();
        }

        $query = Customer::where('business_id', $this->business_id);

        foreach ($this->criteria as $field => $condition) {
            $operator = $condition['operator'] ?? '=';
            $value = $condition['value'] ?? null;

            switch ($field) {
                case 'customer_type':
                    $query->where('customer_type', $operator, $value);
                    break;
                case 'credit_balance':
                    $query->where('current_credit_balance', $operator, $value);
                    break;
                // Add more criteria fields as needed
            }
        }

        return $query->get();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
