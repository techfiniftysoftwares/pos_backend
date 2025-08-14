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
                'last_login_at'
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

            // Apply sorting
            $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');

            // Execute query with pagination and relationships
            $users = $query->with(['role'])
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
            $user->load([
                'role',
                'assignedSupportRequests',
                'supportRequestUpdates'
            ]);

            $userData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : null,
                'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                'role' => $user->role ? $user->role->only(['id', 'name']) : null,
                'assigned_support_requests_count' => $user->assignedSupportRequests->count(),
                'support_request_updates_count' => $user->supportRequestUpdates->count(),
            ];

            return successResponse('User retrieved successfully', $userData);
        } catch (\Exception $e) {
            return queryErrorResponse('An error occurred while retrieving user.', $e->getMessage());
        }
    }

    public function getProfile(Request $request)
    {
        try {
            $user = $request->user()->load(['role']);

            if ($user->role) {
                // Get user permissions through role
                $activePermissions = DB::table('role_permission')
                    ->join('permissions', 'role_permission.permission_id', '=', 'permissions.id')
                    ->join('modules', 'permissions.module_id', '=', 'modules.id')
                    ->join('submodules', 'permissions.submodule_id', '=', 'submodules.id')
                    ->where('role_permission.role_id', $user->role->id)
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
                $filteredPermissions = $activePermissions->map(function($perm) {
                    return [
                        'module' => $perm->module_name,
                        'submodule' => $perm->submodule_title,
                        'action' => $perm->action
                    ];
                });

                // Create user data array
                $userData = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'is_active' => $user->is_active,
                    'last_login_at' => $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : null,
                    'role' => $user->role ? $user->role->only(['id', 'name']) : null,
                    'permissions' => $filteredPermissions
                ];

                return successResponse('User profile retrieved successfully', $userData);
            }

            return successResponse('User profile retrieved successfully', [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : null,
                'role' => $user->role ? $user->role->only(['id', 'name']) : null,
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

    /**
     * Update user specifics including role and status
     */
    public function updateUserSpecifics(Request $request, User $user)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|string|email|unique:users,email,' . $user->id,
                'phone' => 'sometimes|string|max:20',
                'role_id' => 'sometimes|exists:roles,id',
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
                // Handle user deactivation and token deletion
                if (
                    isset($validated['is_active']) &&
                    $validated['is_active'] === false &&
                    $user->is_active !== false
                ) {
                    $user->tokens()->delete();
                }

                // Update user details
                $userUpdateData = collect($validated)
                    ->except(['password', 'password_confirmation'])
                    ->filter()
                    ->toArray();

                if (isset($validated['password'])) {
                    $userUpdateData['password'] = Hash::make($validated['password']);
                }

                $user->update($userUpdateData);

                DB::commit();

                // Reload user with relationships
                $user->load(['role']);

                return updatedResponse($user, 'User details updated successfully');
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return queryErrorResponse('An error occurred while updating user details.', $e->getMessage());
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
