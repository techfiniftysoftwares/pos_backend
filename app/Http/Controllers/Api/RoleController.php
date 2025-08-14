<?php

namespace App\Http\Controllers\Api;


use App\Events\PermissionsUpdated;
use App\Models\Role;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Notification;
use App\Notifications\PermissionsUpdatedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;

class RoleController extends Controller
{
    public function index()
    {
        try {
            $user = Auth::user();

            // Base query with counts
            $query = Role::withCount(['users', 'permissions']);

            // If user is not super admin (role_id !== 1), exclude super admin role
            if ($user->role_id !== 1) {
                $query->where('id', '!=', 1);
            }

            $roles = $query->get();

            $roles = $roles->map(function ($role) {
                $modules = $role->permissions->pluck('module.id')->unique();
                $submodules = $role->permissions->pluck('submodule.id')->unique();

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'users_count' => $role->users_count,
                    'permissions_count' => $role->permissions_count,
                    'modules_count' => $modules->count(),
                    'submodules_count' => $submodules->count(),
                ];
            });

            return successResponse('Roles retrieved successfully', $roles);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to retrieve roles', $e->getMessage());
        }
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255|unique:roles',
        ]);

        $role = Role::create($validatedData);

        return createdResponse('Role created successfully', 201);
    }

    public function show(Role $role)
    {
        $role->load('permissions.module', 'permissions.submodule');

        $modules = $role->permissions->pluck('module')->unique('id')->values();

        $moduleData = $modules->map(function ($module) use ($role) {
            $submodules = $role->permissions->where('module_id', $module->id)
                ->pluck('submodule')
                ->unique('id')
                ->values();

            $submoduleData = $submodules->map(function ($submodule) use ($role, $module) {
                $permissions = $role->permissions->where('module_id', $module->id)
                    ->where('submodule_id', $submodule->id)
                    ->pluck('action')
                    ->toArray();

                return [
                    'id' => $submodule->id,
                    'title' => $submodule->title,
                    'permissions' => $permissions,
                ];
            });

            return [
                'id' => $module->id,
                'name' => $module->name,
                'submodules' => $submoduleData,
            ];
        });

        $roleData = [
            'id' => $role->id,
            'name' => $role->name,
            'modules' => $moduleData,
        ];

        return successResponse('Role details retrieved successfully', $roleData);
    }

    public function update(Request $request, Role $role)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
        ]);

        $role->update($validatedData);

        return updatedResponse($role, 'Role updated successfully');
    }

    public function destroy(Role $role)
    {
        $role->delete();

        return deleteResponse('Role deleted successfully');
    }

    // public function updatePermissions(Request $request, Role $role)
    // {
    //     $validatedData = $request->validate([
    //         'permissions' => 'required|array',
    //         'permissions.*.module_id' => 'required|exists:modules,id',
    //         'permissions.*.submodule_id' => 'required|exists:submodules,id',
    //         'permissions.*.actions' => 'present|array',
    //         'permissions.*.actions.*' => 'in:create,read,update,delete',
    //     ]);

    //     // Clear existing permissions
    //     $role->permissions()->detach();

    //     // Add new permissions
    //     foreach ($validatedData['permissions'] as $permissionData) {
    //         $module_id = $permissionData['module_id'];
    //         $submodule_id = $permissionData['submodule_id'];
    //         $actions = $permissionData['actions'];

    //         foreach ($actions as $action) {
    //             $permission = Permission::firstOrCreate([
    //                 'module_id' => $module_id,
    //                 'submodule_id' => $submodule_id,
    //                 'action' => $action,
    //             ]);

    //             $role->permissions()->attach($permission);
    //         }
    //     }

    //     return updatedResponse($role->load('permissions'), 'Role permissions updated successfully');
    // }


    public function updatePermissions(Request $request, Role $role)
    {
        try {
            $validatedData = $request->validate([
                'permissions' => 'required|array',
                'permissions.*.module_id' => 'required|exists:modules,id',
                'permissions.*.submodule_id' => 'required|exists:submodules,id',
                'permissions.*.actions' => 'present|array',
                'permissions.*.actions.*' => 'in:create,read,update,delete',
            ]);

            DB::beginTransaction();

            try {
                // Clear existing permissions
                $role->permissions()->detach();

                $attachedPermissions = [];

                // Add new permissions
                foreach ($validatedData['permissions'] as $permissionData) {
                    $module_id = $permissionData['module_id'];
                    $submodule_id = $permissionData['submodule_id'];
                    $actions = $permissionData['actions'];

                    foreach ($actions as $action) {
                        try {
                            $permission = Permission::firstOrCreate([
                                'module_id' => $module_id,
                                'submodule_id' => $submodule_id,
                                'action' => $action,
                            ]);

                            $role->permissions()->attach($permission);
                            $attachedPermissions[] = $permission->id;

                        } catch (\Exception $e) {
                            Log::error('Error creating/attaching permission', [
                                'module_id' => $module_id,
                                'submodule_id' => $submodule_id,
                                'action' => $action,
                                'error' => $e->getMessage()
                            ]);
                            throw $e;
                        }
                    }
                }

                DB::commit();

                // Broadcast the permissions updated event
                // try {
                //     Log::info('Attempting to broadcast permission update', [
                //         'role_id' => $role->id,
                //         'channel' => "role.{$role->id}",
                //         'affected_users' => User::where('role_id', $role->id)
                //             ->pluck('id')
                //             ->toArray()
                //     ]);

                //     broadcast(new PermissionsUpdated($role->id));

                //     Log::info('Broadcast dispatched successfully', [
                //         'role_id' => $role->id
                //     ]);

                // } catch (\Exception $e) {
                //     Log::error('Broadcasting failed', [
                //         'role_id' => $role->id,
                //         'error' => $e->getMessage(),
                //         'trace' => $e->getTraceAsString()
                //     ]);
                //     throw $e;
                // }

                return updatedResponse($role->load('permissions'), 'Role permissions updated successfully');

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Transaction failed in permission update', [
                    'role_id' => $role->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validation failed in permission update', [
                'role_id' => $role->id,
                'errors' => $e->errors()
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error in permission update', [
                'role_id' => $role->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }





}
