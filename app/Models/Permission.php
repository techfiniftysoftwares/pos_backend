<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['module_id', 'submodule_id', 'action'];

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function submodule()
    {
        return $this->belongsTo(Submodule::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
