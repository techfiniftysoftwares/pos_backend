<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Branch;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockTransferController extends Controller
{
    /**
     * Get all stock transfers with filters
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $status = $request->input('status');
            $fromBranchId = $request->input('from_branch_id');
            $toBranchId = $request->input('to_branch_id');
            $search = $request->input('search');
            $businessId = $request->user()->business_id;

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');

            // Whitelist of allowed sortable columns
            $allowedSortColumns = [
                'transfer_number',
                'transfer_date',
                'expected_delivery_date',
                'status',
                'created_at',
                'updated_at',
                'approved_at',
                'completed_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'created_at';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            $query = StockTransfer::with(['fromBranch', 'toBranch', 'initiatedBy', 'items.product'])
                ->forBusiness($businessId);

            if ($status) {
                $query->byStatus($status);
            }

            if ($fromBranchId) {
                $query->fromBranch($fromBranchId);
            }

            if ($toBranchId) {
                $query->toBranch($toBranchId);
            }

            // Search filter (transfer number, branch names)
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('transfer_number', 'like', "%{$search}%")
                        ->orWhereHas('fromBranch', function ($bq) use ($search) {
                            $bq->where('name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('toBranch', function ($bq) use ($search) {
                            $bq->where('name', 'like', "%{$search}%");
                        });
                });
            }

            $transfers = $query->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            $transfers->getCollection()->transform(function ($transfer) {
                return $this->transformTransfer($transfer);
            });

            return successResponse('Stock transfers retrieved successfully', $transfers);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve stock transfers', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return queryErrorResponse('Failed to retrieve stock transfers', $e->getMessage());
        }
    }

    /**
     * Create new stock transfer
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from_branch_id' => 'required|exists:branches,id',
            'to_branch_id' => 'required|exists:branches,id|different:from_branch_id',
            'transfer_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:transfer_date',
            'transfer_reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_requested' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;
            $userId = $request->user()->id;

            // Validate branches belong to business
            $fromBranch = Branch::find($request->from_branch_id);
            $toBranch = Branch::find($request->to_branch_id);

            if (!$fromBranch || $fromBranch->business_id !== $businessId) {
                return errorResponse('Invalid source branch', 422);
            }

            if (!$toBranch || $toBranch->business_id !== $businessId) {
                return errorResponse('Invalid destination branch', 422);
            }

            DB::beginTransaction();

            // Generate transfer number
            $transferNumber = $this->generateTransferNumber($businessId);

            // Create transfer
            $transfer = StockTransfer::create([
                'transfer_number' => $transferNumber,
                'business_id' => $businessId,
                'from_branch_id' => $request->from_branch_id,
                'to_branch_id' => $request->to_branch_id,
                'initiated_by' => $userId,
                'status' => 'pending',
                'transfer_date' => $request->transfer_date,
                'expected_delivery_date' => $request->expected_delivery_date,
                'transfer_reason' => $request->transfer_reason,
                'notes' => $request->notes,
            ]);

            // Create transfer items and validate stock
            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);

                if (!$product || $product->business_id !== $businessId) {
                    DB::rollBack();
                    return errorResponse("Invalid product ID: {$item['product_id']}", 422);
                }

                // Check stock availability at source branch
                $stock = Stock::where('business_id', $businessId)
                    ->where('branch_id', $request->from_branch_id)
                    ->where('product_id', $item['product_id'])
                    ->first();

                if (!$stock || $stock->available_quantity < $item['quantity_requested']) {
                    DB::rollBack();
                    return errorResponse(
                        "Insufficient stock for {$product->name}. Available: " . ($stock ? $stock->available_quantity : 0),
                        422
                    );
                }

                // Create transfer item
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'quantity_requested' => $item['quantity_requested'],
                    'quantity_sent' => 0,
                    'quantity_received' => 0,
                    'unit_cost' => $stock->unit_cost,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            $transfer->load(['fromBranch', 'toBranch', 'initiatedBy', 'items.product']);

            return successResponse(
                'Stock transfer created successfully',
                $this->transformTransfer($transfer),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create stock transfer', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return serverErrorResponse('Failed to create stock transfer', $e->getMessage());
        }
    }

    /**
     * Get specific transfer
     */
    public function show(StockTransfer $stockTransfer)
    {
        try {
            if ($stockTransfer->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this transfer', 403);
            }

            $stockTransfer->load([
                'fromBranch',
                'toBranch',
                'initiatedBy',
                'approvedBy',
                'receivedBy',
                'items.product.unit'
            ]);

            return successResponse(
                'Stock transfer retrieved successfully',
                $this->transformTransfer($stockTransfer, true)
            );
        } catch (\Exception $e) {
            Log::error('Failed to retrieve stock transfer', [
                'transfer_id' => $stockTransfer->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve stock transfer', $e->getMessage());
        }
    }

    /**
     * Approve transfer
     */
    public function approve(StockTransfer $stockTransfer)
    {
        try {
            if ($stockTransfer->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this transfer', 403);
            }

            if ($stockTransfer->status !== 'pending') {
                return errorResponse('Only pending transfers can be approved', 422);
            }

            DB::beginTransaction();

            $stockTransfer->update([
                'status' => 'approved',
                'approved_by' => request()->user()->id,
                'approved_at' => now(),
            ]);

            DB::commit();

            $stockTransfer->load(['fromBranch', 'toBranch', 'approvedBy']);

            return successResponse(
                'Stock transfer approved successfully',
                $this->transformTransfer($stockTransfer)
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve stock transfer', [
                'transfer_id' => $stockTransfer->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to approve stock transfer', $e->getMessage());
        }
    }

    /**
     * Mark transfer as in-transit (send items)
     */
    public function sendTransfer(StockTransfer $stockTransfer)
    {
        try {
            if ($stockTransfer->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this transfer', 403);
            }

            if (!in_array($stockTransfer->status, ['pending', 'approved'])) {
                return errorResponse('Transfer cannot be sent in current status', 422);
            }

            DB::beginTransaction();

            $userId = request()->user()->id;

            // Process each transfer item
            foreach ($stockTransfer->items as $item) {
                // Get stock at source branch
                $sourceStock = Stock::where('business_id', $stockTransfer->business_id)
                    ->where('branch_id', $stockTransfer->from_branch_id)
                    ->where('product_id', $item->product_id)
                    ->first();

                if (!$sourceStock || $sourceStock->available_quantity < $item->quantity_requested) {
                    DB::rollBack();
                    return errorResponse(
                        "Insufficient stock for product ID {$item->product_id}",
                        422
                    );
                }

                // Decrease source stock
                $previousQty = (float) $sourceStock->quantity;
                $sourceStock->quantity = $previousQty - (float) $item->quantity_requested;
                $sourceStock->save();

                // Create stock movement for source
                StockMovement::create([
                    'business_id' => $stockTransfer->business_id,
                    'branch_id' => $stockTransfer->from_branch_id,
                    'product_id' => $item->product_id,
                    'user_id' => $userId,
                    'movement_type' => 'transfer_out',
                    'quantity' => -(float) $item->quantity_requested,
                    'previous_quantity' => $previousQty,
                    'new_quantity' => (float) $sourceStock->quantity,
                    'unit_cost' => $sourceStock->unit_cost,
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $stockTransfer->id,
                    'notes' => "Transfer to {$stockTransfer->toBranch->name}",
                ]);

                // Update transfer item
                $item->update(['quantity_sent' => $item->quantity_requested]);
            }

            // Update transfer status
            $stockTransfer->update(['status' => 'in_transit']);

            DB::commit();

            $stockTransfer->load(['fromBranch', 'toBranch', 'items.product']);

            return successResponse(
                'Stock transfer marked as in-transit successfully',
                $this->transformTransfer($stockTransfer)
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to send stock transfer', [
                'transfer_id' => $stockTransfer->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to send stock transfer', $e->getMessage());
        }
    }

    /**
     * Receive transfer (complete)
     */
    public function receiveTransfer(Request $request, StockTransfer $stockTransfer)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.item_id' => 'required|exists:stock_transfer_items,id',
            'items.*.quantity_received' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            if ($stockTransfer->business_id !== $request->user()->business_id) {
                return errorResponse('Unauthorized access to this transfer', 403);
            }

            if ($stockTransfer->status !== 'in_transit') {
                return errorResponse('Only in-transit transfers can be received', 422);
            }

            DB::beginTransaction();

            $userId = $request->user()->id;

            foreach ($request->items as $itemData) {
                $item = StockTransferItem::find($itemData['item_id']);

                if (!$item || $item->stock_transfer_id !== $stockTransfer->id) {
                    DB::rollBack();
                    return errorResponse('Invalid transfer item', 422);
                }

                $quantityReceived = (float) $itemData['quantity_received'];

                // Get or create stock at destination branch
                $destStock = Stock::getOrCreate(
                    $stockTransfer->business_id,
                    $stockTransfer->to_branch_id,
                    $item->product_id
                );

                // Increase destination stock
                $previousQty = (float) $destStock->quantity;
                $destStock->quantity = $previousQty + $quantityReceived;
                $destStock->unit_cost = $item->unit_cost;
                $destStock->last_restocked_at = now();
                $destStock->save();

                // Create stock movement for destination
                StockMovement::create([
                    'business_id' => $stockTransfer->business_id,
                    'branch_id' => $stockTransfer->to_branch_id,
                    'product_id' => $item->product_id,
                    'user_id' => $userId,
                    'movement_type' => 'transfer_in',
                    'quantity' => $quantityReceived,
                    'previous_quantity' => $previousQty,
                    'new_quantity' => (float) $destStock->quantity,
                    'unit_cost' => $item->unit_cost,
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $stockTransfer->id,
                    'notes' => "Transfer from {$stockTransfer->fromBranch->name}",
                ]);

                // Update transfer item
                $item->update(['quantity_received' => $quantityReceived]);
            }

            // Update transfer status
            $stockTransfer->update([
                'status' => 'completed',
                'received_by' => $userId,
                'completed_at' => now(),
            ]);

            DB::commit();

            $stockTransfer->load(['fromBranch', 'toBranch', 'receivedBy', 'items.product']);

            return successResponse(
                'Stock transfer received and completed successfully',
                $this->transformTransfer($stockTransfer)
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to receive stock transfer', [
                'transfer_id' => $stockTransfer->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to receive stock transfer', $e->getMessage());
        }
    }

    /**
     * Cancel transfer
     */
    public function cancel(Request $request, StockTransfer $stockTransfer)
    {
        $validator = Validator::make($request->all(), [
            'cancellation_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            if ($stockTransfer->business_id !== $request->user()->business_id) {
                return errorResponse('Unauthorized access to this transfer', 403);
            }

            if ($stockTransfer->status === 'completed') {
                return errorResponse('Cannot cancel completed transfer', 422);
            }

            if ($stockTransfer->status === 'cancelled') {
                return errorResponse('Transfer already cancelled', 422);
            }

            DB::beginTransaction();

            // If transfer was in-transit, reverse the stock movements
            if ($stockTransfer->status === 'in_transit') {
                foreach ($stockTransfer->items as $item) {
                    if ($item->quantity_sent > 0) {
                        // Restore stock at source branch
                        $sourceStock = Stock::where('business_id', $stockTransfer->business_id)
                            ->where('branch_id', $stockTransfer->from_branch_id)
                            ->where('product_id', $item->product_id)
                            ->first();

                        if ($sourceStock) {
                            $previousQty = (float) $sourceStock->quantity;
                            $sourceStock->quantity = $previousQty + (float) $item->quantity_sent;
                            $sourceStock->save();

                            // Create reversal movement
                            StockMovement::create([
                                'business_id' => $stockTransfer->business_id,
                                'branch_id' => $stockTransfer->from_branch_id,
                                'product_id' => $item->product_id,
                                'user_id' => $request->user()->id,
                                'movement_type' => 'adjustment',
                                'quantity' => (float) $item->quantity_sent,
                                'previous_quantity' => $previousQty,
                                'new_quantity' => (float) $sourceStock->quantity,
                                'unit_cost' => $sourceStock->unit_cost,
                                'reference_type' => StockTransfer::class,
                                'reference_id' => $stockTransfer->id,
                                'notes' => 'Transfer cancelled - stock restored',
                            ]);
                        }
                    }
                }
            }

            $stockTransfer->update([
                'status' => 'cancelled',
                'cancellation_reason' => $request->cancellation_reason,
            ]);

            DB::commit();

            $stockTransfer->load(['fromBranch', 'toBranch']);

            return successResponse(
                'Stock transfer cancelled successfully',
                $this->transformTransfer($stockTransfer)
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to cancel stock transfer', [
                'transfer_id' => $stockTransfer->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to cancel stock transfer', $e->getMessage());
        }
    }

    /**
     * Delete transfer (only if pending)
     */
    public function destroy(StockTransfer $stockTransfer)
    {
        try {
            if ($stockTransfer->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this transfer', 403);
            }

            if ($stockTransfer->status !== 'pending') {
                return errorResponse('Only pending transfers can be deleted', 422);
            }

            DB::beginTransaction();

            $stockTransfer->items()->delete();
            $stockTransfer->delete();

            DB::commit();

            return deleteResponse('Stock transfer deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete stock transfer', [
                'transfer_id' => $stockTransfer->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete stock transfer', $e->getMessage());
        }
    }

    /**
     * Generate unique transfer number
     */
    private function generateTransferNumber($businessId)
    {
        $prefix = 'TRF';
        $date = date('Ymd');
        $count = StockTransfer::where('business_id', $businessId)
            ->whereDate('created_at', today())
            ->count();

        return $prefix . '-' . $date . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Helper: Transform transfer data
     */
    private function transformTransfer($transfer, $detailed = false)
    {
        $data = [
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'from_branch' => [
                'id' => $transfer->fromBranch->id,
                'name' => $transfer->fromBranch->name,
            ],
            'to_branch' => [
                'id' => $transfer->toBranch->id,
                'name' => $transfer->toBranch->name,
            ],
            'status' => $transfer->status,
            'transfer_date' => $transfer->transfer_date->format('Y-m-d'),
            'expected_delivery_date' => $transfer->expected_delivery_date ? $transfer->expected_delivery_date->format('Y-m-d') : null,
            'transfer_reason' => $transfer->transfer_reason,
            'notes' => $transfer->notes,
            'initiated_by' => [
                'id' => $transfer->initiatedBy->id,
                'name' => $transfer->initiatedBy->name,
            ],
            'approved_by' => $transfer->approvedBy ? [
                'id' => $transfer->approvedBy->id,
                'name' => $transfer->approvedBy->name,
            ] : null,
            'received_by' => $transfer->receivedBy ? [
                'id' => $transfer->receivedBy->id,
                'name' => $transfer->receivedBy->name,
            ] : null,
            'total_items' => $transfer->items->count(),
            'approved_at' => $transfer->approved_at ? $transfer->approved_at->format('Y-m-d H:i:s') : null,
            'completed_at' => $transfer->completed_at ? $transfer->completed_at->format('Y-m-d H:i:s') : null,
            'created_at' => $transfer->created_at->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $data['items'] = $transfer->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'sku' => $item->product->sku,
                        'unit' => $item->product->unit->symbol ?? null,
                    ],
                    'quantity_requested' => (float) $item->quantity_requested,
                    'quantity_sent' => (float) $item->quantity_sent,
                    'quantity_received' => (float) $item->quantity_received,
                    'unit_cost' => (float) $item->unit_cost,
                    'notes' => $item->notes,
                ];
            });
        }

        return $data;
    }
}
