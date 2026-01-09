<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Trait to automatically scope queries to the authenticated user's business.
 * 
 * Apply this trait to any model that has a business_id column and should
 * be automatically filtered by the current user's business.
 * 
 * This provides an additional layer of security for multi-tenancy by ensuring
 * queries only return data from the user's own business, even if controllers
 * don't explicitly filter by business_id.
 */
trait ScopedByBusiness
{
    /**
     * Boot the trait and add global scope.
     */
    protected static function bootScopedByBusiness(): void
    {
        static::addGlobalScope('business', function (Builder $builder) {
            // Only apply scope if there's an authenticated user with a business
            if ($user = auth()->user()) {
                if ($user->business_id) {
                    $builder->where($builder->getModel()->getTable() . '.business_id', $user->business_id);
                }
            }
        });

        // Auto-set business_id when creating new records
        static::creating(function ($model) {
            if ($user = auth()->user()) {
                if ($user->business_id && empty($model->business_id)) {
                    $model->business_id = $user->business_id;
                }
            }
        });
    }
}
