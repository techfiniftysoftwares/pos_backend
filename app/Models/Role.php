<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    // Role scoping hierarchy:
    // - NULL business_id = global role (applies to ALL businesses)
    // - business_id set, no branches = business-wide role (all branches)
    // - business_id set, 1 branch = branch-specific role
    // - business_id set, 2+ branches = multi-branch role

    protected $fillable = [
        'name',
        'business_id',
    ];

    // Relationships
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_branch_roles')
            ->withPivot('branch_id')
            ->withTimestamps();
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Branches this role applies to (many-to-many via role_branches)
     */
    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'role_branches')
            ->withTimestamps();
    }

    /**
     * Scope to get roles available for a specific business and branch
     * Returns: global roles + business-wide roles + roles that include the branch
     */
    public function scopeAvailableFor($query, $businessId, $branchId = null)
    {
        return $query->where(function ($q) use ($businessId, $branchId) {
            // Global roles (no business_id) - available everywhere
            $q->whereNull('business_id')
                // Business-wide roles (matching business, no branches)
                ->orWhere(function ($q2) use ($businessId) {
                    $q2->where('business_id', $businessId)
                        ->whereDoesntHave('branches');
                });

            // Roles that include the specific branch
            if ($branchId) {
                $q->orWhere(function ($q3) use ($businessId, $branchId) {
                    $q3->where('business_id', $businessId)
                        ->whereHas('branches', function ($bq) use ($branchId) {
                            $bq->where('branches.id', $branchId);
                        });
                });
            }
        });
    }

    /**
     * Scope for business-wide roles only
     */
    public function scopeForBusiness($query, $businessId)
    {
        return $query->where(function ($q) use ($businessId) {
            $q->whereNull('business_id') // Global
                ->orWhere('business_id', $businessId);
        });
    }

    /**
     * Scope for specific branch roles + business-wide + global
     */
    public function scopeForBranch($query, $businessId, $branchId)
    {
        return $query->availableFor($businessId, $branchId);
    }

    /**
     * Scope for global roles only
     */
    public function scopeGlobalOnly($query)
    {
        return $query->whereNull('business_id');
    }

    /**
     * Scope for business-specific roles only
     */
    public function scopeBusinessOnly($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    /**
     * Check if this is a global role (applies to ALL)
     */
    public function isGlobal(): bool
    {
        return is_null($this->business_id);
    }

    /**
     * Check if this is a business-wide role (has ALL branches in the business selected)
     */
    public function isBusinessWide(): bool
    {
        if (is_null($this->business_id))
            return false;

        $roleBranchCount = $this->branches()->count();
        if ($roleBranchCount === 0)
            return false;

        // Get total active branches for this business
        $totalBusinessBranches = Branch::where('business_id', $this->business_id)
            ->where('is_active', true)
            ->count();

        return $roleBranchCount >= $totalBusinessBranches;
    }

    /**
     * Check if this is a single branch-specific role
     */
    public function isBranchSpecific(): bool
    {
        return !is_null($this->business_id) && $this->branches()->count() === 1;
    }

    /**
     * Check if this applies to multiple (but not all) branches
     */
    public function isMultiBranch(): bool
    {
        if (is_null($this->business_id))
            return false;

        $branchCount = $this->branches()->count();
        return $branchCount > 1 && !$this->isBusinessWide();
    }

    /**
     * Get the scope type as a string
     */
    public function getScopeType(): string
    {
        if ($this->isGlobal())
            return 'global';
        if ($this->isBusinessWide())
            return 'business';
        if ($this->isBranchSpecific())
            return 'branch';
        if ($this->isMultiBranch())
            return 'multi_branch';
        return 'unknown';
    }
}

