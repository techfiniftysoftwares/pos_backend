<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Resources\UserProfileResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function index(Request $request)
    {
        try {
            // Get pagination and sorting parameters
            $perPage = $request->input('per_page', 20);
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');

            // Validate sort field to prevent SQL injection
            $allowedSortFields = [
                'name',
                'email',
                'phone',
                'created_at',
                'is_active',
                'last_login_at',
                'role_id',
                'role'
            ];

            if (!in_array($sortField, $allowedSortFields)) {
                $sortField = 'created_at';
            }

            // Get filter parameters
            $filters = $this->getSearchFilters($request);

            $query = User::query();

            // Apply role-based access control if needed
            // $query = $this->scopeByUserAccess($query);

            // Apply all filters
            $this->applyFilters($query, $filters);

            // Handle sorting by role (join with roles table)
            if ($sortField === 'role_id' || $sortField === 'role') {
                $query->leftJoin('roles', 'users.role_id', '=', 'roles.id')
                    ->orderBy('roles.name', $sortDirection === 'asc' ? 'asc' : 'desc')
                    ->select('users.*');
            } else {
                // Apply regular sorting
                $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
            }

            // Execute query with pagination and relationships
            $users = $query->with(['business', 'primaryBranch', 'branches'])
                ->paginate($perPage)
                ->through(function ($user) {
                    return $this->transformUser($user);
                });

            if ($users->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'status' => 200,
                    'message' => 'No matching records found.',
                    'data' => [
                        'current_page' => 1,
                        'data' => [],
                        'first_page_url' => $request->url() . '?page=1',
                        'from' => null,
                        'last_page' => 1,
                        'last_page_url' => $request->url() . '?page=1',
                        'links' => [
                            [
                                'url' => null,
                                'label' => '&laquo; Previous',
                                'active' => false
                            ],
                            [
                                'url' => $request->url() . '?page=1',
                                'label' => '1',
                                'active' => true
                            ],
                            [
                                'url' => null,
                                'label' => 'Next &raquo;',
                                'active' => false
                            ]
                        ],
                        'next_page_url' => null,
                        'path' => $request->url(),
                        'per_page' => $perPage,
                        'prev_page_url' => null,
                        'to' => null,
                        'total' => 0
                    ]
                ]);
            }

            return successResponse('Users retrieved successfully', $users);
        } catch (\Exception $e) {
            return queryErrorResponse('An error occurred while retrieving users.', $e->getMessage());
        }
    }

    private function getSearchFilters(Request $request): array
    {
        return [
            'search' => $request->input('search'),
            'role_id' => $request->input('role_id'),
            'is_active' => $request->input('is_active'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];
    }

    private function applyFilters($query, array $filters): void
    {
        // General search
        if (!empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query->where(function ($query) use ($searchTerm) {
                $query->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('email', 'like', "%{$searchTerm}%")
                    ->orWhere('phone', 'like', "%{$searchTerm}%");
            });
        }

        // Role filter
        if (!empty($filters['role_id'])) {
            $query->where('role_id', $filters['role_id']);
        }

        // Active status filter
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        // Date range filter
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
    }

    private function transformUser($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : null,
            'created_at' => $user->created_at ? $user->created_at->format('Y-m-d H:i:s') : null,
            'role' => $user->role ? $user->role->only(['id', 'name']) : null,
        ];
    }


    public function show(User $user)
    {
        try {
            // Load necessary relationships that exist in the User model
            $user->load([
                'business',
                'primaryBranch',
                'branches'
            ]);

            $primaryRole = $user->getPrimaryRole();

            $userData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'employee_id' => $user->employee_id,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : null,
                'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $user->updated_at->format('Y-m-d H:i:s'),
                'role' => $primaryRole ? [
                    'id' => $primaryRole->id,
                    'name' => $primaryRole->name
                ] : null,
                'business' => $user->business ? [
                    'id' => $user->business->id,
                    'name' => $user->business->name
                ] : null,
                'primary_branch' => $user->primaryBranch ? [
                    'id' => $user->primaryBranch->id,
                    'name' => $user->primaryBranch->name,
                    'code' => $user->primaryBranch->code
                ] : null,
                'accessible_branches' => $user->branches->map(function ($branch) use ($user) {
                    $branchRole = $user->getRoleForBranch($branch->id);
                    return [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'code' => $branch->code,
                        'role' => $branchRole ? $branchRole->only(['id', 'name']) : null,
                    ];
                }),
                'pin_locked' => $user->isPinLocked(),
                'failed_pin_attempts' => $user->failed_pin_attempts,
                'pin_locked_until' => $user->pin_locked_until ? $user->pin_locked_until->format('Y-m-d H:i:s') : null,
            ];

            return successResponse('User retrieved successfully', $userData);
        } catch (\Exception $e) {
            return queryErrorResponse('An error occurred while retrieving user.', $e->getMessage());
        }
    }
    /**
     * Get user details for editing with available branches
     */
    public function edit(User $user)
    {
        try {
            // Load user relationships
            $user->load(['business', 'primaryBranch', 'branches']);

            // Get all branches for the user's business
            $availableBranches = \App\Models\Branch::where('business_id', $user->business_id)
                ->where('is_active', true)
                ->get()
                ->map(function ($branch) use ($user) {
                    return [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'code' => $branch->code,
                        'is_assigned' => $user->branches->contains('id', $branch->id),
                        'is_primary' => $user->primary_branch_id === $branch->id
                    ];
                });

            $primaryRole = $user->getPrimaryRole();

            $userData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'employee_id' => $user->employee_id,
                'is_active' => $user->is_active,
                'business_id' => $user->business_id,
                'primary_branch_id' => $user->primary_branch_id,
                'role' => $primaryRole ? [
                    'id' => $primaryRole->id,
                    'name' => $primaryRole->name
                ] : null,
                'business' => $user->business ? [
                    'id' => $user->business->id,
                    'name' => $user->business->name
                ] : null,
                'primary_branch' => $user->primaryBranch ? [
                    'id' => $user->primaryBranch->id,
                    'name' => $user->primaryBranch->name,
                    'code' => $user->primaryBranch->code
                ] : null,
                'assigned_branch_ids' => $user->branches->pluck('id')->toArray(),
                'available_branches' => $availableBranches,
                'accessible_branches' => $user->branches->map(function ($branch) use ($user) {
                    $branchRole = $user->getRoleForBranch($branch->id);
                    return [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'code' => $branch->code,
                        'role' => $branchRole ? $branchRole->only(['id', 'name']) : null,
                    ];
                }),
            ];

            return successResponse('User details retrieved successfully', $userData);
        } catch (\Exception $e) {
            return queryErrorResponse('An error occurred while retrieving user details.', $e->getMessage());
        }
    }
    /**
     * Update user's primary branch
     * POST /user/update-primary-branch
     *
     * Request body:
     * {
     *     "branch_id": "2"
     * }
     */
    public function updatePrimaryBranch(Request $request)
    {
        try {
            $user = $request->user();

            $validator = Validator::make($request->all(), [
                'branch_id' => 'required|exists:branches,id'
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $branchId = $request->input('branch_id');

            // Check if the branch belongs to the user's business
            $branch = \App\Models\Branch::find($branchId);

            if (!$branch) {
                return errorResponse('Branch not found', 404);
            }

            if ($branch->business_id !== $user->business_id) {
                return errorResponse('You can only set branches from your business as primary branch', 403);
            }

            // Check if the branch is in user's accessible branches
            $hasAccess = $user->branches()->where('branches.id', $branchId)->exists();

            if (!$hasAccess) {
                return errorResponse('You can only set accessible branches as primary branch', 403);
            }

            // Update primary branch
            $user->update(['primary_branch_id' => $branchId]);

            // Reload user with relationships
            $user->load(['business', 'primaryBranch', 'branches']);

            return successResponse('Primary branch updated successfully', [
                'id' => $user->id,
                'name' => $user->name,
                'primary_branch' => [
                    'id' => $user->primaryBranch->id,
                    'name' => $user->primaryBranch->name,
                    'code' => $user->primaryBranch->code
                ],
                'accessible_branches' => $user->branches->map(function ($branch) {
                    return [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'code' => $branch->code
                    ];
                })
            ]);

        } catch (\Exception $e) {
            return queryErrorResponse('An error occurred while updating primary branch.', $e->getMessage());
        }
    }
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|unique:users',
                'phone' => 'required|string|max:20',
                'password' => 'required|string|min:8|confirmed',
                'business_id' => 'required|exists:businesses,id',
                'primary_branch_id' => 'required|exists:branches,id',
                'employee_id' => 'sometimes|string|max:50',
                'branch_roles' => 'required|array|min:1',
                'branch_roles.*.branch_id' => 'required|exists:branches,id',
                'branch_roles.*.role_id' => 'required|exists:roles,id',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            // Validate primary_branch_id is included in branch_roles
            $branchIds = collect($request->branch_roles)->pluck('branch_id')->toArray();
            if (!in_array($request->primary_branch_id, $branchIds)) {
                return errorResponse('Primary branch must be included in branch roles', 400);
            }

            DB::beginTransaction();

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'business_id' => $request->business_id,
                'primary_branch_id' => $request->primary_branch_id,
                'employee_id' => $request->employee_id,
                'is_active' => true,
            ]);

            // Sync user_branches (accessible branches)
            $user->branches()->sync($branchIds);

            // Sync user_branch_roles (role per branch)
            foreach ($request->branch_roles as $branchRole) {
                DB::table('user_branch_roles')->insert([
                    'user_id' => $user->id,
                    'branch_id' => $branchRole['branch_id'],
                    'role_id' => $branchRole['role_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return createdResponse($user, 'User created successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return queryErrorResponse('An error occurred while creating user.', $e->getMessage());
        }
    }
    public function update(Request $request, User $user)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|string|email|unique:users,email,' . $user->id,
                'phone' => 'sometimes|string|max:20',
                'employee_id' => 'sometimes|string|max:50',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $user->update($request->only(['name', 'email', 'phone', 'employee_id']));

            return updatedResponse($user, 'User updated successfully');

        } catch (\Exception $e) {
            return queryErrorResponse('An error occurred while updating user.', $e->getMessage());
        }
    }
    /**
     * Update user specifics including roles per branch, status, and branch assignments
     */
    public function updateUserSpecifics(Request $request, User $user)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|string|email|unique:users,email,' . $user->id,
                'phone' => 'sometimes|string|max:20',
                'primary_branch_id' => 'sometimes|exists:branches,id',
                'branch_roles' => 'sometimes|array',
                'branch_roles.*.branch_id' => 'required|exists:branches,id',
                'branch_roles.*.role_id' => 'required|exists:roles,id',
                'is_active' => 'sometimes|boolean',
                'password' => 'sometimes|string|min:8',
                'password_confirmation' => 'sometimes|required_with:password|same:password',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $validated = $validator->validated();

            DB::beginTransaction();

            try {
                // Validate primary branch belongs to user's business
                if (isset($validated['primary_branch_id'])) {
                    $primaryBranch = \App\Models\Branch::find($validated['primary_branch_id']);
                    if ($primaryBranch->business_id !== $user->business_id) {
                        return errorResponse('Primary branch must belong to user\'s business', 400);
                    }
                }

                // Validate all branch_roles belong to user's business
                if (isset($validated['branch_roles'])) {
                    $branchIds = collect($validated['branch_roles'])->pluck('branch_id')->toArray();
                    $branches = \App\Models\Branch::whereIn('id', $branchIds)->get();

                    foreach ($branches as $branch) {
                        if ($branch->business_id !== $user->business_id) {
                            return errorResponse('All branches must belong to user\'s business', 400);
                        }
                    }

                    // Validate primary branch is in branch_roles
                    $primaryBranchId = $validated['primary_branch_id'] ?? $user->primary_branch_id;
                    if (!in_array($primaryBranchId, $branchIds)) {
                        return errorResponse('Primary branch must be included in branch roles', 400);
                    }
                }

                // Handle user deactivation and token deletion
                if (
                    isset($validated['is_active']) &&
                    $validated['is_active'] === false &&
                    $user->is_active !== false
                ) {
                    $user->tokens()->delete();
                }

                // Update user details (exclude branch_roles and password from direct update)
                $userUpdateData = collect($validated)
                    ->except(['password', 'password_confirmation', 'branch_roles'])
                    ->filter()
                    ->toArray();

                if (isset($validated['password'])) {
                    $userUpdateData['password'] = Hash::make($validated['password']);
                }

                $user->update($userUpdateData);

                // Update branch roles
                if (isset($validated['branch_roles'])) {
                    $branchIds = collect($validated['branch_roles'])->pluck('branch_id')->toArray();

                    // Sync user_branches (accessible branches)
                    $user->branches()->sync($branchIds);

                    // Replace user_branch_roles
                    DB::table('user_branch_roles')->where('user_id', $user->id)->delete();

                    foreach ($validated['branch_roles'] as $branchRole) {
                        DB::table('user_branch_roles')->insert([
                            'user_id' => $user->id,
                            'branch_id' => $branchRole['branch_id'],
                            'role_id' => $branchRole['role_id'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                DB::commit();

                // Reload user with relationships
                $user->load(['business', 'primaryBranch', 'branches', 'branchRoles']);
                $primaryRole = $user->getPrimaryRole();

                return updatedResponse([
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'employee_id' => $user->employee_id,
                    'is_active' => $user->is_active,
                    'role' => $primaryRole ? $primaryRole->only(['id', 'name']) : null,
                    'business' => $user->business ? $user->business->only(['id', 'name']) : null,
                    'primary_branch' => $user->primaryBranch ? [
                        'id' => $user->primaryBranch->id,
                        'name' => $user->primaryBranch->name,
                        'code' => $user->primaryBranch->code
                    ] : null,
                    'accessible_branches' => $user->branches->map(function ($branch) use ($user) {
                        $branchRole = $user->getRoleForBranch($branch->id);
                        return [
                            'id' => $branch->id,
                            'name' => $branch->name,
                            'code' => $branch->code,
                            'role' => $branchRole ? $branchRole->only(['id', 'name']) : null,
                        ];
                    })
                ], 'User details updated successfully');
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Update user transaction error: ' . $e->getMessage());
                Log::error($e->getTraceAsString());
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Update user outer error: ' . $e->getMessage());
            return queryErrorResponse('An error occurred while updating user details.', $e->getMessage());
        }
    }

    public function getProfile(Request $request)
    {
        try {
            $user = $request->user()->load(['business', 'primaryBranch', 'branches']);

            // Get role for primary branch
            $primaryRole = $user->getPrimaryRole();

            if ($primaryRole) {
                // Get user permissions through role
                $activePermissions = DB::table('role_permission')
                    ->join('permissions', 'role_permission.permission_id', '=', 'permissions.id')
                    ->join('modules', 'permissions.module_id', '=', 'modules.id')
                    ->join('submodules', 'permissions.submodule_id', '=', 'submodules.id')
                    ->where('role_permission.role_id', $primaryRole->id)
                    ->where('modules.is_active', 1)
                    ->where('submodules.is_active', 1)
                    ->select([
                        'permissions.id',
                        'permissions.module_id',
                        'permissions.submodule_id',
                        'permissions.action',
                        'modules.name as module_name',
                        'submodules.title as submodule_title'
                    ])
                    ->get();

                // Transform to the expected format
                $filteredPermissions = $activePermissions->map(function ($perm) {
                    return [
                        'module' => $perm->module_name,
                        'submodule' => $perm->submodule_title,
                        'action' => $perm->action
                    ];
                });

                // Create user data array with role per branch
                $userData = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'employee_id' => $user->employee_id,
                    'is_active' => $user->is_active,
                    'last_login_at' => $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : null,
                    'role' => $primaryRole ? $primaryRole->only(['id', 'name']) : null,
                    'business' => $user->business ? $user->business->only(['id', 'name']) : null,
                    'primary_branch' => $user->primaryBranch ? [
                        'id' => $user->primaryBranch->id,
                        'name' => $user->primaryBranch->name,
                        'code' => $user->primaryBranch->code
                    ] : null,
                    'accessible_branches' => $user->branches->map(function ($branch) use ($user) {
                        $branchRole = $user->getRoleForBranch($branch->id);
                        return [
                            'id' => $branch->id,
                            'name' => $branch->name,
                            'code' => $branch->code,
                            'role' => $branchRole ? $branchRole->only(['id', 'name']) : null,
                        ];
                    }),
                    'permissions' => $filteredPermissions
                ];

                return successResponse('User profile retrieved successfully', $userData);
            }

            return successResponse('User profile retrieved successfully', [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'employee_id' => $user->employee_id,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : null,
                'role' => null,
                'business' => $user->business ? $user->business->only(['id', 'name']) : null,
                'primary_branch' => $user->primaryBranch ? [
                    'id' => $user->primaryBranch->id,
                    'name' => $user->primaryBranch->name,
                    'code' => $user->primaryBranch->code
                ] : null,
                'accessible_branches' => $user->branches->map(function ($branch) use ($user) {
                    $branchRole = $user->getRoleForBranch($branch->id);
                    return [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'code' => $branch->code,
                        'role' => $branchRole ? $branchRole->only(['id', 'name']) : null,
                    ];
                }),
            ]);

        } catch (\Exception $e) {
            return queryErrorResponse(
                'An error occurred while retrieving user profile.',
                $e->getMessage()
            );
        }
    }
    public function updateProfile(Request $request)
    {
        try {
            $user = $request->user();
            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|string|email|unique:users,email,' . $user->id,
                'phone' => 'sometimes|string|max:20',
                'password' => 'sometimes|string|min:8',
                'password_confirmation' => 'sometimes|required_with:password|same:password',
            ]);

            // Update user data
            $updateData = collect($validatedData)
                ->except(['password', 'password_confirmation'])
                ->filter()
                ->toArray();

            if (isset($validatedData['password'])) {
                $updateData['password'] = Hash::make($validatedData['password']);
            }

            $user->update($updateData);

            return successResponse('User profile updated successfully', $user);
        } catch (\Exception $e) {
            return queryErrorResponse('An error occurred while updating user profile.', $e->getMessage());
        }
    }
    public function destroy(User $user)
    {
        try {
            // Check if user is trying to delete themselves
            if ($user->id === Auth::id()) {
                return errorResponse('Cannot delete your own account', 403);
            }

            DB::beginTransaction();

            try {
                // Handle related records if needed
                // For example, you might want to reassign support requests
                // $user->assignedSupportRequests()->update(['assigned_to' => null]);

                // Delete the user
                $user->delete();

                DB::commit();

                return deleteResponse('User deleted successfully');
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to delete user', $e->getMessage());
        }
    }

    /**
     * Toggle user active status
     */
    public function toggleStatus(User $user)
    {
        try {
            // Check if user is trying to deactivate themselves
            if ($user->id === Auth::id()) {
                return errorResponse('Cannot deactivate your own account', 403);
            }

            DB::beginTransaction();

            try {
                $newStatus = !$user->is_active;

                // If deactivating, revoke all tokens
                if (!$newStatus) {
                    $user->tokens()->delete();
                }

                $user->update(['is_active' => $newStatus]);

                DB::commit();

                $statusText = $newStatus ? 'activated' : 'deactivated';
                return successResponse("User {$statusText} successfully", $user);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to toggle user status', $e->getMessage());
        }
    }
}
