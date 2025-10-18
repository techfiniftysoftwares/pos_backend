<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role_id',
        'business_id',
        'primary_branch_id',
        'pin',
        'employee_id',
        'failed_pin_attempts',
        'pin_locked_until',
        'is_active',
        'last_login_at'
    ];

    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'is_active' => 'boolean',
        'failed_pin_attempts' => 'integer',
        'last_login_at' => 'datetime',
        'pin_locked_until' => 'datetime',
    ];

    // Relationships
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function primaryBranch()
    {
        return $this->belongsTo(Branch::class, 'primary_branch_id');
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'user_branches');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->whereHas('branches', function($q) use ($branchId) {
            $q->where('branches.id', $branchId);
        });
    }

    public function scopePinNotLocked($query)
    {
        return $query->where(function($q) {
            $q->whereNull('pin_locked_until')
              ->orWhere('pin_locked_until', '<=', now());
        });
    }

    // PIN Authentication Methods
    public function setPinAttribute($value)
    {
        if ($value) {
            $this->attributes['pin'] = Hash::make($value);
            $this->attributes['failed_pin_attempts'] = 0;
            $this->attributes['pin_locked_until'] = null;
        }
    }

    public function setPin(string $pin): void
    {
        $this->pin = $pin; // This will trigger the setPinAttribute mutator
    }

    public function verifyPin(string $pin): bool
    {
        if ($this->isPinLocked()) {
            return false;
        }

        if (Hash::check($pin, $this->pin)) {
            $this->resetPinAttempts();
            return true;
        }

        $this->incrementPinAttempts();
        return false;
    }

    public function isPinLocked(): bool
    {
        return $this->pin_locked_until && $this->pin_locked_until->isFuture();
    }

    public function incrementPinAttempts(): void
    {
        $this->increment('failed_pin_attempts');

        // Lock PIN after 3 failed attempts for 15 minutes
        if ($this->failed_pin_attempts >= 3) {
            $this->update(['pin_locked_until' => now()->addMinutes(15)]);
        }
    }

    public function resetPinAttempts(): void
    {
        $this->update([
            'failed_pin_attempts' => 0,
            'pin_locked_until' => null,
            'last_login_at' => now()
        ]);
    }

    public function hasPermission(int $moduleId, int $submoduleId, string $action): bool
    {
        return $this->role->permissions()
            ->where('module_id', $moduleId)
            ->where('submodule_id', $submoduleId)
            ->where('action', $action)
            ->exists();
    }

    public function canAccessBranch(int $branchId): bool
    {
        return $this->primary_branch_id === $branchId ||
               $this->branches()->where('branches.id', $branchId)->exists();
    }

    public function generateEmployeeId(): string
    {
        if ($this->business) {
            $businessPrefix = strtoupper(substr($this->business->name, 0, 2));
            $userCount = User::where('business_id', $this->business_id)->count();
            return $businessPrefix . str_pad($userCount + 1, 4, '0', STR_PAD_LEFT);
        }

        return 'EMP' . str_pad($this->id, 4, '0', STR_PAD_LEFT);
    }
}
