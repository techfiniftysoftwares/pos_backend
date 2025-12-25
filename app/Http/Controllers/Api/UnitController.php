<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UnitController extends Controller
{
    /**
     * Get all units with optional filters
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $search = $request->input('search');
            $isActive = $request->input('is_active');
            $businessId = $request->user()->business_id;

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'name');
            $sortDirection = $request->input('sort_direction', 'asc');

            // Whitelist of allowed sortable columns to prevent SQL injection
            $allowedSortColumns = [
                'name',
                'symbol',
                'conversion_factor',
                'is_active',
                'products_count',
                'created_at',
                'updated_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'name';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            $query = Unit::with(['baseUnit'])
                ->withCount('products')
                ->forBusiness($businessId);

            // Search filter
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('symbol', 'like', "%{$search}%");
                });
            }

            // Active status filter
            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            $units = $query->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            // Transform the data
            $units->getCollection()->transform(function ($unit) {
                return $this->transformUnit($unit);
            });

            return successResponse('Units retrieved successfully', $units);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve units', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return queryErrorResponse('Failed to retrieve units', $e->getMessage());
        }
    }

    /**
     * Create new unit
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'symbol' => 'required|string|max:20',
            'base_unit_id' => 'nullable|exists:units,id',
            'conversion_factor' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;

            // Check for duplicate unit name
            $exists = Unit::forBusiness($businessId)
                ->where('name', $request->name)
                ->exists();

            if ($exists) {
                return errorResponse('Unit with this name already exists', 422);
            }

            // Validate base_unit_id belongs to same business
            if ($request->base_unit_id) {
                $baseUnit = Unit::find($request->base_unit_id);
                if (!$baseUnit || $baseUnit->business_id !== $businessId) {
                    return errorResponse('Invalid base unit', 422);
                }

                // Require conversion_factor if base_unit_id is provided
                if (!$request->conversion_factor) {
                    return errorResponse('Conversion factor is required when base unit is specified', 422);
                }
            }

            DB::beginTransaction();

            $unit = Unit::create([
                'business_id' => $businessId,
                'name' => $request->name,
                'symbol' => $request->symbol,
                'base_unit_id' => $request->base_unit_id,
                'conversion_factor' => $request->conversion_factor,
                'is_active' => $request->input('is_active', true),
            ]);

            DB::commit();

            $unit->load('baseUnit');

            return successResponse(
                'Unit created successfully',
                $this->transformUnit($unit),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create unit', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return serverErrorResponse('Failed to create unit', $e->getMessage());
        }
    }

    /**
     * Get specific unit
     */
    public function show(Unit $unit)
    {
        try {
            // Check business access
            if ($unit->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this unit', 403);
            }

            $unit->load(['baseUnit', 'derivedUnits', 'products']);

            $unitData = $this->transformUnit($unit);

            // Add additional stats
            $unitData['products_count'] = $unit->products()->count();
            $unitData['active_products_count'] = $unit->products()->where('is_active', true)->count();
            $unitData['derived_units_count'] = $unit->derivedUnits()->count();

            return successResponse('Unit retrieved successfully', $unitData);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve unit', [
                'unit_id' => $unit->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve unit', $e->getMessage());
        }
    }

    /**
     * Update unit
     */
    public function update(Request $request, Unit $unit)
    {
        // Check business access
        if ($unit->business_id !== $request->user()->business_id) {
            return errorResponse('Unauthorized access to this unit', 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'symbol' => 'sometimes|string|max:20',
            'base_unit_id' => 'nullable|exists:units,id',
            'conversion_factor' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Prevent circular reference (unit can't be its own base)
            if ($request->base_unit_id && $request->base_unit_id == $unit->id) {
                return errorResponse('Unit cannot be its own base unit', 422);
            }

            // Check for duplicate name (excluding current unit)
            if ($request->has('name')) {
                $exists = Unit::forBusiness($unit->business_id)
                    ->where('name', $request->name)
                    ->where('id', '!=', $unit->id)
                    ->exists();

                if ($exists) {
                    return errorResponse('Unit with this name already exists', 422);
                }
            }

            // Validate base_unit_id belongs to same business
            if ($request->has('base_unit_id') && $request->base_unit_id) {
                $baseUnit = Unit::find($request->base_unit_id);
                if (!$baseUnit || $baseUnit->business_id !== $unit->business_id) {
                    return errorResponse('Invalid base unit', 422);
                }
            }

            DB::beginTransaction();

            $updateData = collect($request->only([
                'name',
                'symbol',
                'base_unit_id',
                'conversion_factor',
                'is_active'
            ]))->filter(function ($value) {
                return !is_null($value);
            })->toArray();

            $unit->update($updateData);

            DB::commit();

            $unit->load('baseUnit');

            return updatedResponse(
                $this->transformUnit($unit),
                'Unit updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update unit', [
                'unit_id' => $unit->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to update unit', $e->getMessage());
        }
    }

    /**
     * Delete unit
     */
    public function destroy(Unit $unit)
    {
        try {
            // Check business access
            if ($unit->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this unit', 403);
            }

            DB::beginTransaction();

            // Check if unit has products
            if ($unit->products()->exists()) {
                return errorResponse(
                    'Cannot delete unit with existing products. Please reassign products first.',
                    400
                );
            }

            // Check if unit has derived units
            if ($unit->derivedUnits()->exists()) {
                return errorResponse(
                    'Cannot delete unit that is used as a base unit for other units.',
                    400
                );
            }

            $unit->delete();

            DB::commit();

            return deleteResponse('Unit deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete unit', [
                'unit_id' => $unit->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete unit', $e->getMessage());
        }
    }

    /**
     * Toggle unit status
     */
    public function toggleStatus(Unit $unit)
    {
        try {
            // Check business access
            if ($unit->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this unit', 403);
            }

            DB::beginTransaction();

            $newStatus = !$unit->is_active;
            $unit->update(['is_active' => $newStatus]);

            DB::commit();

            $statusText = $newStatus ? 'activated' : 'deactivated';
            return successResponse(
                "Unit {$statusText} successfully",
                $this->transformUnit($unit)
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to toggle unit status', [
                'unit_id' => $unit->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to toggle unit status', $e->getMessage());
        }
    }

    /**
     * Helper: Transform unit data
     */
    private function transformUnit($unit)
    {
        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'symbol' => $unit->symbol,
            'conversion_factor' => $unit->conversion_factor,
            'is_active' => $unit->is_active,
            'base_unit_id' => $unit->base_unit_id,
            'base_unit' => $unit->baseUnit ? [
                'id' => $unit->baseUnit->id,
                'name' => $unit->baseUnit->name,
                'symbol' => $unit->baseUnit->symbol
            ] : null,
            'products_count' => $unit->products_count ?? $unit->products()->count(),
            'created_at' => $unit->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $unit->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
