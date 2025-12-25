<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockAdjustment;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Product;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockAdjustmentController extends Controller
{
    /**
     * Get all stock adjustments with filters
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $branchId = $request->input('branch_id');
            $productId = $request->input('product_id');
            $adjustmentType = $request->input('adjustment_type');
            $reason = $request->input('reason');
            $approved = $request->input('approved');
            $search = $request->input('search');
            $businessId = $request->user()->business_id;

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');

            // Whitelist of allowed sortable columns
            $allowedSortColumns = [
                'quantity_adjusted',
                'before_quantity',
                'after_quantity',
                'cost_impact',
                'created_at',
                'updated_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'created_at';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            $query = StockAdjustment::with(['product', 'branch', 'adjustedBy', 'approvedBy'])
                ->forBusiness($businessId);

            if ($branchId) {
                $query->forBranch($branchId);
            }

            if ($productId) {
                $query->where('product_id', $productId);
            }

            if ($adjustmentType) {
                $query->where('adjustment_type', $adjustmentType);
            }

            if ($reason) {
                $query->byReason($reason);
            }

            if (isset($approved)) {
                if ($approved) {
                    $query->approved();
                } else {
                    $query->pending();
                }
            }

            // Search filter (product name or SKU)
            if ($search) {
                $query->whereHas('product', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            }

            $adjustments = $query->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            $adjustments->getCollection()->transform(function ($adjustment) {
                return $this->transformAdjustment($adjustment);
            });

            return successResponse('Stock adjustments retrieved successfully', $adjustments);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve stock adjustments', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return queryErrorResponse('Failed to retrieve stock adjustments', $e->getMessage());
        }
    }

    /**
     * Create new stock adjustment
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|exists:branches,id',
            'product_id' => 'required|exists:products,id',
            'adjustment_type' => 'required|in:increase,decrease',
            'quantity_adjusted' => 'required|numeric|min:0.01',
            'reason' => 'required|in:damaged,expired,theft,count_error,lost,found,other',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;
            $userId = $request->user()->id;

            // Validate branch belongs to business
            $branch = Branch::find($request->branch_id);
            if (!$branch || $branch->business_id !== $businessId) {
                return errorResponse('Invalid branch', 422);
            }

            // Validate product belongs to business
            $product = Product::find($request->product_id);
            if (!$product || $product->business_id !== $businessId) {
                return errorResponse('Invalid product', 422);
            }

            DB::beginTransaction();

            // Get or create stock record
            $stock = Stock::getOrCreate($businessId, $request->branch_id, $request->product_id);

            $beforeQuantity = (float) $stock->quantity;
            $quantityAdjusted = (float) $request->quantity_adjusted;

            // Calculate after quantity based on adjustment type
            if ($request->adjustment_type === 'increase') {
                $afterQuantity = $beforeQuantity + $quantityAdjusted;
            } else {
                // Check if decrease would result in negative stock
                if (!$product->allow_negative_stock && ($beforeQuantity - $quantityAdjusted) < 0) {
                    return errorResponse(
                        "Insufficient stock. Current: {$beforeQuantity}, Requested decrease: {$quantityAdjusted}",
                        422
                    );
                }
                $afterQuantity = $beforeQuantity - $quantityAdjusted;
            }

            // Calculate cost impact
            $costImpact = $quantityAdjusted * (float) $stock->unit_cost;
            if ($request->adjustment_type === 'decrease') {
                $costImpact = -$costImpact;
            }

            // Create adjustment record
            $adjustment = StockAdjustment::create([
                'business_id' => $businessId,
                'branch_id' => $request->branch_id,
                'product_id' => $request->product_id,
                'adjusted_by' => $userId,
                'adjustment_type' => $request->adjustment_type,
                'quantity_adjusted' => $quantityAdjusted,
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $afterQuantity,
                'reason' => $request->reason,
                'cost_impact' => $costImpact,
                'notes' => $request->notes,
            ]);

            // Update stock quantity
            $stock->quantity = $afterQuantity;
            if ($request->adjustment_type === 'increase') {
                $stock->last_restocked_at = now();
            }
            $stock->save();

            // Create stock movement record
            $movementQuantity = $request->adjustment_type === 'increase'
                ? $quantityAdjusted
                : -$quantityAdjusted;

            StockMovement::create([
                'business_id' => $businessId,
                'branch_id' => $request->branch_id,
                'product_id' => $request->product_id,
                'user_id' => $userId,
                'movement_type' => 'adjustment',
                'quantity' => $movementQuantity,
                'previous_quantity' => $beforeQuantity,
                'new_quantity' => $afterQuantity,
                'unit_cost' => $stock->unit_cost,
                'reference_type' => StockAdjustment::class,
                'reference_id' => $adjustment->id,
                'reason' => $request->reason,
                'notes' => $request->notes,
            ]);

            DB::commit();

            $adjustment->load(['product', 'branch', 'adjustedBy']);

            return successResponse(
                'Stock adjustment created successfully',
                $this->transformAdjustment($adjustment),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create stock adjustment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return serverErrorResponse('Failed to create stock adjustment', $e->getMessage());
        }
    }

    /**
     * Get specific adjustment
     */
    public function show(StockAdjustment $stockAdjustment)
    {
        try {
            // Check business access
            if ($stockAdjustment->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this adjustment', 403);
            }

            $stockAdjustment->load(['product', 'branch', 'adjustedBy', 'approvedBy']);

            return successResponse(
                'Stock adjustment retrieved successfully',
                $this->transformAdjustment($stockAdjustment)
            );
        } catch (\Exception $e) {
            Log::error('Failed to retrieve stock adjustment', [
                'adjustment_id' => $stockAdjustment->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve stock adjustment', $e->getMessage());
        }
    }

    /**
     * Update adjustment (only if not approved)
     */
    public function update(Request $request, StockAdjustment $stockAdjustment)
    {
        // Check business access
        if ($stockAdjustment->business_id !== $request->user()->business_id) {
            return errorResponse('Unauthorized access to this adjustment', 403);
        }

        // Prevent updating approved adjustments
        if ($stockAdjustment->is_approved) {
            return errorResponse('Cannot update approved adjustment', 422);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // Only allow updating notes for now
            if ($request->has('notes')) {
                $stockAdjustment->update(['notes' => $request->notes]);
            }

            DB::commit();

            $stockAdjustment->load(['product', 'branch', 'adjustedBy']);

            return updatedResponse(
                $this->transformAdjustment($stockAdjustment),
                'Stock adjustment updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update stock adjustment', [
                'adjustment_id' => $stockAdjustment->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to update stock adjustment', $e->getMessage());
        }
    }

    /**
     * Approve adjustment
     */
    public function approve(StockAdjustment $stockAdjustment)
    {
        try {
            // Check business access
            if ($stockAdjustment->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this adjustment', 403);
            }

            // Check if already approved
            if ($stockAdjustment->is_approved) {
                return errorResponse('Adjustment already approved', 422);
            }

            DB::beginTransaction();

            $stockAdjustment->update([
                'approved_by' => request()->user()->id,
                'approved_at' => now(),
            ]);

            DB::commit();

            $stockAdjustment->load(['product', 'branch', 'adjustedBy', 'approvedBy']);

            return successResponse(
                'Stock adjustment approved successfully',
                $this->transformAdjustment($stockAdjustment)
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve stock adjustment', [
                'adjustment_id' => $stockAdjustment->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to approve stock adjustment', $e->getMessage());
        }
    }

    /**
     * Delete adjustment (reverse the stock change)
     */
    public function destroy(StockAdjustment $stockAdjustment)
    {
        try {
            // Check business access
            if ($stockAdjustment->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this adjustment', 403);
            }

            // Prevent deleting approved adjustments
            if ($stockAdjustment->is_approved) {
                return errorResponse('Cannot delete approved adjustment. Please create a reversal adjustment instead.', 422);
            }

            DB::beginTransaction();

            // Reverse the stock change
            $stock = Stock::where('business_id', $stockAdjustment->business_id)
                ->where('branch_id', $stockAdjustment->branch_id)
                ->where('product_id', $stockAdjustment->product_id)
                ->first();

            if ($stock) {
                // Reverse the adjustment
                if ($stockAdjustment->adjustment_type === 'increase') {
                    $stock->quantity = (float) $stock->quantity - (float) $stockAdjustment->quantity_adjusted;
                } else {
                    $stock->quantity = (float) $stock->quantity + (float) $stockAdjustment->quantity_adjusted;
                }
                $stock->save();

                // Delete related stock movement
                StockMovement::where('reference_type', StockAdjustment::class)
                    ->where('reference_id', $stockAdjustment->id)
                    ->delete();
            }

            // Delete adjustment
            $stockAdjustment->delete();

            DB::commit();

            return deleteResponse('Stock adjustment deleted and stock reversed successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete stock adjustment', [
                'adjustment_id' => $stockAdjustment->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete stock adjustment', $e->getMessage());
        }
    }

    /**
     * Helper: Transform adjustment data
     */
    private function transformAdjustment($adjustment)
    {
        return [
            'id' => $adjustment->id,
            'product' => [
                'id' => $adjustment->product->id,
                'name' => $adjustment->product->name,
                'sku' => $adjustment->product->sku,
            ],
            'branch' => [
                'id' => $adjustment->branch->id,
                'name' => $adjustment->branch->name,
            ],
            'adjustment_type' => $adjustment->adjustment_type,
            'quantity_adjusted' => (float) $adjustment->quantity_adjusted,
            'before_quantity' => (float) $adjustment->before_quantity,
            'after_quantity' => (float) $adjustment->after_quantity,
            'reason' => $adjustment->reason,
            'cost_impact' => (float) $adjustment->cost_impact,
            'notes' => $adjustment->notes,
            'adjusted_by' => [
                'id' => $adjustment->adjustedBy->id,
                'name' => $adjustment->adjustedBy->name,
            ],
            'approved_by' => $adjustment->approvedBy ? [
                'id' => $adjustment->approvedBy->id,
                'name' => $adjustment->approvedBy->name,
            ] : null,
            'is_approved' => $adjustment->is_approved,
            'approved_at' => $adjustment->approved_at ? $adjustment->approved_at->format('Y-m-d H:i:s') : null,
            'created_at' => $adjustment->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $adjustment->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
