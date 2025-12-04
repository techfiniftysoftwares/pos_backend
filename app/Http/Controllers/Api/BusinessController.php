<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class BusinessController extends Controller
{
    /**
     * Get all businesses
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $search = $request->input('search');
            $status = $request->input('status');

            $query = Business::query()->with(['mainBranch']);

            // Apply filters
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($status) {
                $query->where('status', $status);
            }

            $businesses = $query->orderBy('created_at', 'desc')
                               ->paginate($perPage);

            return successResponse('Businesses retrieved successfully', $businesses);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve businesses', $e->getMessage());
        }
    }

    /**
     * Create new business
     */
    public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:businesses,email',
        'phone' => 'nullable|string|max:20',
        'address' => 'nullable|string',
        'status' => 'sometimes|in:active,inactive,suspended',
        'base_currency_id' => 'required|exists:currencies,id',
    ]);

    if ($validator->fails()) {
        return validationErrorResponse($validator->errors());
    }

    try {
        DB::beginTransaction();

        $business = Business::create($validator->validated());

        // Create main branch automatically
        $mainBranch = Branch::create([
            'business_id' => $business->id,
            'name' => $business->name . ' - Main Branch',
            'code' => 'MAIN001',
            'phone' => $business->phone,
            'address' => $business->address ?? 'Main Office',
            'is_main_branch' => true,
            'is_active' => true
        ]);

        DB::commit();

        // Load currency relationship
        $business->load('baseCurrency');

        return successResponse('Business created successfully', [
            'business' => $business,
            'main_branch' => $mainBranch
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        return serverErrorResponse('Failed to create business', $e->getMessage());
    }
}
    /**
     * Get specific business
     */
    public function show(Business $business)
    {
        try {
            $business->load(['branches', 'users.role']);

            $businessData = [
                'id' => $business->id,
                'name' => $business->name,
                'email' => $business->email,
                'phone' => $business->phone,
                'address' => $business->address,
                'status' => $business->status,
                'created_at' => $business->created_at->format('Y-m-d H:i:s'),
                'branches_count' => $business->branches->count(),
                'active_branches_count' => $business->activeBranches->count(),
                'users_count' => $business->users->count(),
                'branches' => $business->branches->map(function($branch) {
                    return [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'code' => $branch->code,
                        'is_main_branch' => $branch->is_main_branch,
                        'is_active' => $branch->is_active,
                        'users_count' => $branch->primaryUsers->count()
                    ];
                })
            ];

            return successResponse('Business retrieved successfully', $businessData);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve business', $e->getMessage());
        }
    }

    /**
     * Update business
     */
    public function update(Request $request, Business $business)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:businesses,email,' . $business->id,
            'phone' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string',
            'status' => 'sometimes|in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $business->update($validator->validated());

            return updatedResponse($business, 'Business updated successfully');
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to update business', $e->getMessage());
        }
    }

    /**
     * Delete business
     */
    public function destroy(Business $business)
    {
        try {
            DB::beginTransaction();

            // Check if business has active users
            if ($business->users()->where('is_active', true)->exists()) {
                return errorResponse('Cannot delete business with active users', 400);
            }

            $business->delete();

            DB::commit();

            return deleteResponse('Business deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to delete business', $e->getMessage());
        }
    }
}