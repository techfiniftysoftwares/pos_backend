<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


class Submodule extends Model
{
    protected $fillable = ['title', 'path', 'module_id', 'is_active'];

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function permissions()
    {
        return $this->hasMany(Permission::class, 'submodule_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($submodule) {
            // Begin transaction
            DB::beginTransaction();

            try {
                // Get all permission IDs associated with this submodule
                $permissionIds = $submodule->permissions()->pluck('id');

                // Delete associated role permissions
                DB::table('role_permission')
                    ->whereIn('permission_id', $permissionIds)
                    ->delete();

                // Delete the permissions
                $submodule->permissions()->delete();

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        });
    }
}
