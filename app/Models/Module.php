<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Module extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'is_active'];

    public function submodules()
    {
        return $this->hasMany(Submodule::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($module) {
            // Begin transaction
            DB::beginTransaction();

            try {
                // Get all permissions associated with this module's submodules
                $permissionIds = Permission::whereIn('submodule_id',
                    $module->submodules()->pluck('id')
                )->pluck('id');

                // Delete associated role permissions
                DB::table('role_permission')
                    ->whereIn('permission_id', $permissionIds)
                    ->delete();

                // Delete associated permissions
                Permission::whereIn('id', $permissionIds)->delete();

                // Delete associated submodules
                $module->submodules()->delete();


                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        });

    }
}
