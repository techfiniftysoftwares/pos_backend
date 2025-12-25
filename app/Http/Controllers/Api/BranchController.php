<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    /**
     * Get all branches for a business
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $search = $request->input('search');
            $isActive = $request->input('is_active');

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'is_main_branch');
            $sortDirection = $request->input('sort_direction', 'desc');

            // Whitelist of allowed sortable columns
            $allowedSortColumns = [
                'name',
                'code',
                'phone',
                'address',
                'is_active',
                'is_main_branch',
                'created_at',
                'updated_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'is_main_branch';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

            $query = Branch::query()->with(['business']);

            // Filter by business if specified
            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            // Apply search filter
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%");
                });
            }

            // Filter by active status
            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            // Apply sorting
            if ($sortBy === 'is_main_branch') {
                // If sorting by main branch, secondary sort by created_at
                $query->orderBy('is_main_branch', $sortDirection)
                    ->orderBy('created_at', 'desc');
            } else {
                $query->orderBy($sortBy, $sortDirection);
            }

            $branches = $query->paginate($perPage);

            return successResponse('Branches retrieved successfully', $branches);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve branches', $e->getMessage());
        }
    }

    /**
     * Create new branch
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|unique:branches,code',
            'phone' => 'nullable|string|max:20',
            'address' => 'required|string',
            'is_active' => 'sometimes|boolean',
            'is_main_branch' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $branch = Branch::create($validator->validated());

            return successResponse('Branch created successfully', $branch, 201);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to create branch', $e->getMessage());
        }
    }

    /**
     * Get specific branch
     */
    public function show(Branch $branch)
    {
        try {
            $branch->load(['business', 'primaryUsers.role']);

            $branchData = [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'is_active' => $branch->is_active,
                'is_main_branch' => $branch->is_main_branch,
                'created_at' => $branch->created_at->format('Y-m-d H:i:s'),
                'business' => $branch->business->only(['id', 'name', 'status']),
                'users_count' => $branch->primaryUsers->count(),
                'active_users_count' => $branch->primaryUsers->where('is_active', true)->count(),
                'users' => $branch->primaryUsers->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'employee_id' => $user->employee_id,
                        'role' => $user->role?->name,
                        'is_active' => $user->is_active
                    ];
                })
            ];

            return successResponse('Branch retrieved successfully', $branchData);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve branch', $e->getMessage());
        }
    }

    /**
     * Update branch
     */
    public function update(Request $request, Branch $branch)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|unique:branches,code,' . $branch->id,
            'phone' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|string',
            'is_active' => 'sometimes|boolean',
            'is_main_branch' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $branch->update($validator->validated());

            return updatedResponse($branch, 'Branch updated successfully');
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to update branch', $e->getMessage());
        }
    }

    /**
     * Delete branch
     */
    public function destroy(Branch $branch)
    {
        try {
            DB::beginTransaction();

            // Prevent deletion of main branch
            if ($branch->is_main_branch) {
                return errorResponse('Cannot delete main branch', 400);
            }

            // Check if branch has active users
            if ($branch->primaryUsers()->where('is_active', true)->exists()) {
                return errorResponse('Cannot delete branch with active users', 400);
            }

            $branch->delete();

            DB::commit();

            return deleteResponse('Branch deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to delete branch', $e->getMessage());
        }
    }

    /**
     * Toggle branch status
     */
    public function toggleStatus(Branch $branch)
    {
        try {
            // Prevent deactivating main branch
            if ($branch->is_main_branch && $branch->is_active) {
                return errorResponse('Cannot deactivate main branch', 400);
            }

            $newStatus = !$branch->is_active;
            $branch->update(['is_active' => $newStatus]);

            $statusText = $newStatus ? 'activated' : 'deactivated';
            return successResponse("Branch {$statusText} successfully", $branch);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to toggle branch status', $e->getMessage());
        }
    }
}
