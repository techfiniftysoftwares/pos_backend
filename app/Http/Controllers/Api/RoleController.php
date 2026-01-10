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
    public function index(Request $request)
    {
        try {
            $user = Auth::user();

            // Base query with counts and branches
            $query = Role::withCount(['users', 'permissions'])->with('branches', 'business');

            // 1. Determine User's Scope Level
            $userRoleIds = DB::table('user_branch_roles')
                ->where('user_id', $user->id)
                ->pluck('role_id')
                ->unique();

            $hasGlobalAccess = $user->hasGlobalAccess();

            // If not explicitly global (null branch), check if they hold an inherently global role
            if (!$hasGlobalAccess && $userRoleIds->isNotEmpty()) {
                $hasGlobalAccess = Role::whereIn('id', $userRoleIds)
                    ->whereNull('business_id')
                    ->exists();
            }

            // 2. Apply Filters
            if ($user->business_id) {
                if ($hasGlobalAccess) {
                    // --- HIGH LEVEL ACCESS (Business Admin / Global) ---
                    // Can see everything suitable for the business
                    if ($request->has('branch_id')) {
                        $query->availableFor($user->business_id, $request->branch_id);
                    } else {
                        $query->forBusiness($user->business_id);
                    }
                } else {
                    // --- RESTRICTED ACCESS (Branch Manager / Staff) ---
                    // Can ONLY see roles attached to their accessible branches
                    // CANNOT see Global or Business-Wide roles

                    $accessibleBranchIds = $user->branches->pluck('id')->toArray();

                    // If request asks for specific branch, verify access
                    if ($request->has('branch_id')) {
                        if (!in_array($request->branch_id, $accessibleBranchIds)) {
                            // If they ask for a branch they can't access, return nothing
                            $query->whereRaw('1 = 0');
                        } else {
                            // Valid branch request: Show roles for this branch
                            // Must exclude Global/Business-Wide (logic: must have branches)
                            $query->where('business_id', $user->business_id)
                                ->whereHas('branches', function ($q) use ($request) {
                                    $q->where('branches.id', $request->branch_id);
                                });
                        }
                    } else {
                        // General list request: Show roles for ALL accessible branches
                        $query->where('business_id', $user->business_id)
                            ->whereHas('branches', function ($q) use ($accessibleBranchIds) {
                                $q->whereIn('branches.id', $accessibleBranchIds);
                            });
                    }
                }
            }

            // If user is not super admin (role_id !== 1), exclude super admin role
            // We check if the user effectively has role 1 in any branch
            $hasSuperAdminRole = in_array(1, $userRoleIds->toArray());

            if (!$hasSuperAdminRole) {
                $query->where('id', '!=', 1);
            }

            $roles = $query->get();

            $roles = $roles->map(function ($role) {
                $modules = $role->permissions->pluck('module.id')->unique();
                $submodules = $role->permissions->pluck('submodule.id')->unique();

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'business_id' => $role->business_id,
                    'business' => $role->business,
                    'branches' => $role->branches,
                    'scope_type' => $role->getScopeType(),
                    'is_global' => $role->isGlobal(),
                    'is_business_wide' => $role->isBusinessWide(),
                    'is_branch_specific' => $role->isBranchSpecific(),
                    'is_multi_branch' => $role->isMultiBranch(),
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
        $user = Auth::user();

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        // Auto-set business_id from authenticated user
        $businessId = $user->business_id;
        $branchIds = $validatedData['branch_ids'] ?? [];

        // Logic Change: Global User + No Branches = System Global Role (NULL business_id)
        // Global User + Selected Branches = Business Role (User's business_id)
        if ($user->hasGlobalAccess() && empty($branchIds)) {
            $businessId = null;
        }

        // Enforce Branch Access for Creation
        $userAccessibleBranchIds = $user->branches->pluck('id')->toArray();
        if ($user->hasGlobalAccess()) {
            // Global users have access to all branches, strict check not needed for IDs
        } else {
            // Restricted user: Must provide specific branches, and all must be accessible
            if (empty($branchIds)) {
                return errorResponse('Please select at least one branch.', 422);
            }

            $diff = array_diff($branchIds, $userAccessibleBranchIds);
            if (!empty($diff)) {
                return errorResponse('You cannot assign a role to branches you do not have access to.', 403);
            }
        }

        // Check for permission to create System Global roles (null business_id)
        if (is_null($businessId) && !$user->hasGlobalAccess()) {
            return errorResponse('Only Global Admins can create System Global roles.', 403);
        }

        // Check for overlap with existing roles with the same name
        $existingRoles = Role::where('business_id', $businessId)
            ->where('name', $validatedData['name'])
            ->with('branches')
            ->get();

        foreach ($existingRoles as $existingRole) {
            $existingBranchIds = $existingRole->branches->pluck('id')->toArray();

            // If existing role is business-wide (no branches) and we're creating any role with same name
            if (empty($existingBranchIds)) {
                return errorResponse('A business-wide role with this name already exists', 422);
            }

            // If new role is business-wide (no branches) and any role with same name exists
            if (empty($branchIds)) {
                return errorResponse('Roles with this name already exist. Cannot create a business-wide role with the same name.', 422);
            }

            // Check for branch overlap
            $overlap = array_intersect($branchIds, $existingBranchIds);
            if (!empty($overlap)) {
                return errorResponse('A role with this name already exists for some of the selected branches', 422);
            }
        }

        $role = Role::create([
            'name' => $validatedData['name'],
            'business_id' => $businessId,
        ]);

        // Sync branches if provided
        if (!empty($branchIds)) {
            $role->branches()->sync($branchIds);
        }

        $role->load('branches');

        return successResponse('Role created successfully', [
            'id' => $role->id,
            'name' => $role->name,
            'business_id' => $role->business_id,
            'branches' => $role->branches,
            'scope_type' => $role->getScopeType(),
        ]);
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
            'name' => 'required|string|max:255',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $branchIds = $validatedData['branch_ids'] ?? [];

        // Enforce Branch Access for Update
        if ($request->user()->hasGlobalAccess()) {
            // Global users can edit everything
        } else {
            // 0. Protect System Global Roles and "Global" drafts
            if (is_null($role->business_id) || $role->branches->isEmpty()) {
                return errorResponse('You do not have permission to manage this role.', 403);
            }

            $userAccessibleBranchIds = $request->user()->branches->pluck('id')->toArray();

            // 1. Check if user is allowed to touch the role's CURRENT branches
            // If the role is assigned to a branch the user doesn't own, they can't edit the role at all (integrity)
            $currentRoleBranchIds = $role->branches->pluck('id')->toArray();
            $diffCurrent = array_diff($currentRoleBranchIds, $userAccessibleBranchIds);

            if (!empty($diffCurrent)) {
                return errorResponse('You cannot edit this role because it belongs to branches you do not have access to.', 403);
            }

            // 2. Check if user is allowed to assign the NEW branches
            if (empty($branchIds)) {
                return errorResponse('Please select at least one branch.', 422);
            }

            $diffNew = array_diff($branchIds, $userAccessibleBranchIds);
            if (!empty($diffNew)) {
                return errorResponse('You cannot assign a role to branches you do not have access to.', 403);
            }
        }

        // Check for overlap with existing roles with the same name (excluding current role)
        $existingRoles = Role::where('business_id', $role->business_id)
            ->where('name', $validatedData['name'])
            ->where('id', '!=', $role->id)
            ->with('branches')
            ->get();

        foreach ($existingRoles as $existingRole) {
            $existingBranchIds = $existingRole->branches->pluck('id')->toArray();

            // If existing role is business-wide (no branches)
            if (empty($existingBranchIds)) {
                return errorResponse('A business-wide role with this name already exists', 422);
            }

            // If updated role is business-wide (no branches) and any role with same name exists
            if (empty($branchIds)) {
                return errorResponse('Roles with this name already exist. Cannot make this a business-wide role.', 422);
            }

            // Check for branch overlap
            $overlap = array_intersect($branchIds, $existingBranchIds);
            if (!empty($overlap)) {
                return errorResponse('A role with this name already exists for some of the selected branches', 422);
            }
        }

        $role->update(['name' => $validatedData['name']]);

        // Sync branches
        $role->branches()->sync($branchIds);

        $role->load('branches', 'business');

        return updatedResponse([
            'id' => $role->id,
            'name' => $role->name,
            'business_id' => $role->business_id,
            'branches' => $role->branches,
            'scope_type' => $role->getScopeType(),
        ], 'Role updated successfully');
    }

    public function destroy(Role $role)
    {
        $role->delete();

        return deleteResponse('Role deleted successfully');
    }

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
                //         // 'channel' => "role.{$role->id}",
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
