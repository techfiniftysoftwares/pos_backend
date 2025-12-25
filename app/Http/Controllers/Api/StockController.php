<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Product;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockController extends Controller
{
    /**
     * Get all stock levels with filters
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $branchId = $request->input('branch_id');
            $productId = $request->input('product_id');
            $categoryId = $request->input('category_id');
            $search = $request->input('search');
            $lowStock = $request->input('low_stock'); // boolean
            $outOfStock = $request->input('out_of_stock'); // boolean
            $businessId = $request->user()->business_id;

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');

            // Whitelist of allowed sortable columns
            $allowedSortColumns = [
                'quantity',
                'reserved_quantity',
                'unit_cost',
                'created_at',
                'updated_at',
                'last_restocked_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'created_at';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            $query = Stock::with(['product.category', 'product.unit', 'branch'])
                ->forBusiness($businessId);

            // Branch filter
            if ($branchId) {
                $query->forBranch($branchId);
            }

            // Product filter
            if ($productId) {
                $query->where('product_id', $productId);
            }

            // Category filter (filter by product's category)
            if ($categoryId) {
                $query->whereHas('product', function ($q) use ($categoryId) {
                    $q->where('category_id', $categoryId);
                });
            }

            // Search filter (product name or SKU)
            if ($search) {
                $query->whereHas('product', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            }

            // Low stock filter
            if ($lowStock) {
                $query->lowStock();
            }

            // Out of stock filter
            if ($outOfStock) {
                $query->outOfStock();
            }

            $stocks = $query->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            // Transform the data
            $stocks->getCollection()->transform(function ($stock) {
                return $this->transformStock($stock);
            });

            return successResponse('Stock levels retrieved successfully', $stocks);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve stock levels', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return queryErrorResponse('Failed to retrieve stock levels', $e->getMessage());
        }
    }

    /**
     * Get stock for specific product at branch
     */
    public function show(Stock $stock)
    {
        try {
            // Check business access
            if ($stock->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this stock record', 403);
            }

            $stock->load(['product.category', 'product.unit', 'branch']);

            $stockData = $this->transformStock($stock);

            // Add computed values
            $stockData['is_low_stock'] = $stock->is_low_stock;
            $stockData['is_out_of_stock'] = $stock->is_out_of_stock;
            $stockData['stock_value'] = $stock->stock_value;

            return successResponse('Stock details retrieved successfully', $stockData);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve stock details', [
                'stock_id' => $stock->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve stock details', $e->getMessage());
        }
    }

    /**
     * Get stock by product and branch
     */
    public function getByProductAndBranch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'branch_id' => 'required|exists:branches,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;

            $stock = Stock::with(['product.category', 'product.unit', 'branch'])
                ->forBusiness($businessId)
                ->where('product_id', $request->product_id)
                ->where('branch_id', $request->branch_id)
                ->first();

            if (!$stock) {
                return notFoundResponse('Stock record not found');
            }

            return successResponse('Stock retrieved successfully', $this->transformStock($stock));
        } catch (\Exception $e) {
            Log::error('Failed to retrieve stock', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve stock', $e->getMessage());
        }
    }

    /**
     * Update stock manually (direct update)
     */
    public function update(Request $request, Stock $stock)
    {
        // Check business access
        if ($stock->business_id !== $request->user()->business_id) {
            return errorResponse('Unauthorized access to this stock record', 403);
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'sometimes|numeric|min:0',
            'reserved_quantity' => 'sometimes|numeric|min:0',
            'unit_cost' => 'sometimes|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $previousQuantity = (float) $stock->quantity;

            $updateData = collect($request->only([
                'quantity',
                'reserved_quantity',
                'unit_cost'
            ]))->filter(function ($value) {
                return !is_null($value);
            })->toArray();

            $stock->update($updateData);

            // Create stock movement if quantity changed
            if ($request->has('quantity') && $request->quantity != $previousQuantity) {
                $quantityChange = (float) $request->quantity - $previousQuantity;

                StockMovement::create([
                    'business_id' => $stock->business_id,
                    'branch_id' => $stock->branch_id,
                    'product_id' => $stock->product_id,
                    'user_id' => $request->user()->id,
                    'movement_type' => 'adjustment',
                    'quantity' => $quantityChange,
                    'previous_quantity' => $previousQuantity,
                    'new_quantity' => (float) $request->quantity,
                    'unit_cost' => $stock->unit_cost,
                    'notes' => 'Manual stock update',
                ]);
            }

            DB::commit();

            $stock->load(['product', 'branch']);

            return updatedResponse(
                $this->transformStock($stock),
                'Stock updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update stock', [
                'stock_id' => $stock->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to update stock', $e->getMessage());
        }
    }

    /**
     * Get low stock alerts with pagination, sorting, search, and filters
     */
    public function lowStockAlerts(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $branchId = $request->input('branch_id');
            $urgency = $request->input('urgency');
            $search = $request->input('search');
            $businessId = $request->user()->business_id;

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'quantity');
            $sortDirection = $request->input('sort_direction', 'asc');

            // Whitelist of allowed sortable columns
            $allowedSortColumns = [
                'quantity',
                'created_at',
                'updated_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'quantity';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            $query = Stock::with(['product.category', 'product.unit', 'branch'])
                ->forBusiness($businessId)
                ->lowStock();

            // Branch filter
            if ($branchId) {
                $query->forBranch($branchId);
            }

            // Search filter (product name, SKU, or branch name)
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('product', function ($pq) use ($search) {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    })->orWhereHas('branch', function ($bq) use ($search) {
                        $bq->where('name', 'like', "%{$search}%");
                    });
                });
            }

            // Get paginated results
            $lowStocksQuery = $query->orderBy($sortBy, $sortDirection);
            $lowStocks = $lowStocksQuery->paginate($perPage);

            // Transform the data and filter by urgency if needed
            $transformedData = $lowStocks->getCollection()->map(function ($stock) {
                return [
                    'id' => $stock->id,
                    'product' => [
                        'id' => $stock->product->id,
                        'name' => $stock->product->name,
                        'sku' => $stock->product->sku,
                        'category' => $stock->product->category->name ?? null,
                    ],
                    'branch' => [
                        'id' => $stock->branch->id,
                        'name' => $stock->branch->name,
                    ],
                    'current_quantity' => (float) $stock->quantity,
                    'minimum_level' => $stock->product->minimum_stock_level,
                    'available_quantity' => $stock->available_quantity,
                    'deficit' => $stock->product->minimum_stock_level - (float) $stock->quantity,
                    'urgency' => $this->calculateUrgency($stock),
                ];
            });

            // Filter by urgency after transformation (since it's calculated)
            if ($urgency) {
                $transformedData = $transformedData->filter(function ($item) use ($urgency) {
                    return $item['urgency'] === $urgency;
                })->values();
            }

            // Update the collection
            $lowStocks->setCollection($transformedData);

            return successResponse('Low stock alerts retrieved successfully', $lowStocks);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve low stock alerts', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve low stock alerts', $e->getMessage());
        }
    }

    /**
     * Get stock movements/history for a product
     */
    public function movements(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'branch_id' => 'required|exists:branches,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;
            $perPage = $request->input('per_page', 50);
            $movementType = $request->input('movement_type');

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');

            // Whitelist of allowed sortable columns
            $allowedSortColumns = [
                'quantity',
                'previous_quantity',
                'new_quantity',
                'unit_cost',
                'movement_type',
                'created_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'created_at';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            $query = StockMovement::with(['user', 'product', 'branch'])
                ->forBusiness($businessId)
                ->where('product_id', $request->product_id)
                ->where('branch_id', $request->branch_id);

            if ($movementType) {
                $query->byType($movementType);
            }

            $movements = $query->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            // Transform the data
            $movements->getCollection()->transform(function ($movement) {
                return [
                    'id' => $movement->id,
                    'movement_type' => $movement->movement_type,
                    'quantity' => (float) $movement->quantity,
                    'previous_quantity' => (float) $movement->previous_quantity,
                    'new_quantity' => (float) $movement->new_quantity,
                    'unit_cost' => (float) $movement->unit_cost,
                    'user' => [
                        'id' => $movement->user->id,
                        'name' => $movement->user->name,
                    ],
                    'reason' => $movement->reason,
                    'notes' => $movement->notes,
                    'reference_type' => $movement->reference_type,
                    'reference_id' => $movement->reference_id,
                    'created_at' => $movement->created_at->format('Y-m-d H:i:s'),
                ];
            });

            return successResponse('Stock movements retrieved successfully', $movements);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve stock movements', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve stock movements', $e->getMessage());
        }
    }

    /**
     * Get stock summary by branch
     */
    public function summaryByBranch(Request $request)
    {
        try {
            $businessId = $request->user()->business_id;

            $branches = Branch::forBusiness($businessId)
                ->where('is_active', true)
                ->get();

            $summary = $branches->map(function ($branch) use ($businessId) {
                $totalStock = Stock::forBusiness($businessId)
                    ->forBranch($branch->id)
                    ->count();

                $lowStockCount = Stock::forBusiness($businessId)
                    ->forBranch($branch->id)
                    ->lowStock()
                    ->count();

                $outOfStockCount = Stock::forBusiness($businessId)
                    ->forBranch($branch->id)
                    ->outOfStock()
                    ->count();

                $totalValue = Stock::forBusiness($businessId)
                    ->forBranch($branch->id)
                    ->get()
                    ->sum('stock_value');

                return [
                    'branch_id' => $branch->id,
                    'branch_name' => $branch->name,
                    'total_products' => $totalStock,
                    'low_stock_count' => $lowStockCount,
                    'out_of_stock_count' => $outOfStockCount,
                    'total_stock_value' => (float) $totalValue,
                ];
            });

            return successResponse('Stock summary by branch retrieved successfully', $summary);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve stock summary', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve stock summary', $e->getMessage());
        }
    }

    /**
     * Helper: Transform stock data
     */
    private function transformStock($stock)
    {
        return [
            'id' => $stock->id,
            'product' => [
                'id' => $stock->product->id,
                'name' => $stock->product->name,
                'sku' => $stock->product->sku,
                'barcode' => $stock->product->barcode,
                'category' => $stock->product->category->name ?? null,
                'unit' => $stock->product->unit->symbol ?? null,
                'minimum_stock_level' => $stock->product->minimum_stock_level,
            ],
            'branch' => [
                'id' => $stock->branch->id,
                'name' => $stock->branch->name,
                'code' => $stock->branch->code,
            ],
            'quantity' => (float) $stock->quantity,
            'reserved_quantity' => (float) $stock->reserved_quantity,
            'available_quantity' => $stock->available_quantity,
            'unit_cost' => (float) $stock->unit_cost,
            'last_restocked_at' => $stock->last_restocked_at ? $stock->last_restocked_at->format('Y-m-d H:i:s') : null,
            'created_at' => $stock->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $stock->updated_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Helper: Calculate urgency level
     */
    private function calculateUrgency($stock)
    {
        $deficit = $stock->product->minimum_stock_level - (float) $stock->quantity;
        $percentBelow = ($deficit / $stock->product->minimum_stock_level) * 100;

        if ($percentBelow >= 80) {
            return 'critical';
        } elseif ($percentBelow >= 50) {
            return 'high';
        } elseif ($percentBelow >= 25) {
            return 'medium';
        } else {
            return 'low';
        }
    }
}
