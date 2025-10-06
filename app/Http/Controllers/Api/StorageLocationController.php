<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorageLocation;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StorageLocationController extends Controller
{
    /**
     * Get all storage locations with filters
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $branchId = $request->input('branch_id');
            $locationType = $request->input('location_type');
            $isActive = $request->input('is_active');
            $search = $request->input('search');
            $businessId = $request->user()->business_id;

            $query = StorageLocation::with(['branch'])
                ->forBusiness($businessId);

            if ($branchId) {
                $query->forBranch($branchId);
            }

            if ($locationType) {
                $query->byType($locationType);
            }

            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            }

            $locations = $query->orderBy('name', 'asc')
                ->paginate($perPage);

            $locations->getCollection()->transform(function ($location) {
                return $this->transformLocation($location);
            });

            return successResponse('Storage locations retrieved successfully', $locations);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve storage locations', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return queryErrorResponse('Failed to retrieve storage locations', $e->getMessage());
        }
    }

    /**
     * Create new storage location
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|exists:branches,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:storage_locations,code',
            'location_type' => 'required|in:aisle,shelf,bin,zone,warehouse,cold_storage,dry_storage,other',
            'capacity' => 'nullable|integer|min:1',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;

            $branch = Branch::find($request->branch_id);
            if (!$branch || $branch->business_id !== $businessId) {
                return errorResponse('Invalid branch', 422);
            }

            DB::beginTransaction();

            $location = StorageLocation::create([
                'business_id' => $businessId,
                'branch_id' => $request->branch_id,
                'name' => $request->name,
                'code' => $request->code,
                'location_type' => $request->location_type,
                'capacity' => $request->capacity,
                'description' => $request->description,
                'is_active' => $request->input('is_active', true),
            ]);

            DB::commit();

            $location->load('branch');

            return successResponse(
                'Storage location created successfully',
                $this->transformLocation($location),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create storage location', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return serverErrorResponse('Failed to create storage location', $e->getMessage());
        }
    }

    /**
     * Get specific storage location
     */
    public function show(StorageLocation $storageLocation)
    {
        try {
            if ($storageLocation->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this storage location', 403);
            }

            $storageLocation->load('branch');

            return successResponse(
                'Storage location retrieved successfully',
                $this->transformLocation($storageLocation)
            );
        } catch (\Exception $e) {
            Log::error('Failed to retrieve storage location', [
                'location_id' => $storageLocation->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve storage location', $e->getMessage());
        }
    }

    /**
     * Update storage location
     */
    public function update(Request $request, StorageLocation $storageLocation)
    {
        if ($storageLocation->business_id !== $request->user()->business_id) {
            return errorResponse('Unauthorized access to this storage location', 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:50|unique:storage_locations,code,' . $storageLocation->id,
            'location_type' => 'sometimes|in:aisle,shelf,bin,zone,warehouse,cold_storage,dry_storage,other',
            'capacity' => 'sometimes|nullable|integer|min:1',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $updateData = collect($request->only([
                'name',
                'code',
                'location_type',
                'capacity',
                'description',
                'is_active'
            ]))->filter(function ($value, $key) {
                return !is_null($value) || in_array($key, ['capacity', 'description']);
            })->toArray();

            $storageLocation->update($updateData);

            DB::commit();

            $storageLocation->load('branch');

            return updatedResponse(
                $this->transformLocation($storageLocation),
                'Storage location updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update storage location', [
                'location_id' => $storageLocation->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to update storage location', $e->getMessage());
        }
    }

    /**
     * Delete storage location
     */
    public function destroy(StorageLocation $storageLocation)
    {
        try {
            if ($storageLocation->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this storage location', 403);
            }

            DB::beginTransaction();

            $storageLocation->delete();

            DB::commit();

            return deleteResponse('Storage location deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete storage location', [
                'location_id' => $storageLocation->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete storage location', $e->getMessage());
        }
    }

    /**
     * Toggle location status
     */
    public function toggleStatus(StorageLocation $storageLocation)
    {
        try {
            if ($storageLocation->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this storage location', 403);
            }

            DB::beginTransaction();

            $newStatus = !$storageLocation->is_active;
            $storageLocation->update(['is_active' => $newStatus]);

            DB::commit();

            $statusText = $newStatus ? 'activated' : 'deactivated';
            return successResponse(
                "Storage location {$statusText} successfully",
                $this->transformLocation($storageLocation)
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to toggle storage location status', [
                'location_id' => $storageLocation->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to toggle storage location status', $e->getMessage());
        }
    }

    /**
     * Helper: Transform location data
     */
    private function transformLocation($location)
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'code' => $location->code,
            'full_name' => $location->full_name,
            'location_type' => $location->location_type,
            'capacity' => $location->capacity,
            'description' => $location->description,
            'is_active' => $location->is_active,
            'branch' => [
                'id' => $location->branch->id,
                'name' => $location->branch->name,
            ],
            'created_at' => $location->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $location->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
