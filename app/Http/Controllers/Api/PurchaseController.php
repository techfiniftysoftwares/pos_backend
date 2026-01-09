<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PurchaseController extends Controller
{
    /**
     * Display all purchase orders with filtering
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $supplierId = $request->input('supplier_id');
            $status = $request->input('status');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $search = $request->input('search');

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');

            // Whitelist of allowed sortable columns
            $allowedSortColumns = [
                'purchase_number',
                'purchase_date',
                'expected_delivery_date',
                'status',
                'total_amount',
                'subtotal',
                'tax_amount',
                'created_at',
                'updated_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'created_at';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            $query = Purchase::query()
                ->with(['supplier', 'branch', 'createdBy', 'items.product', 'currencyModel']);

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            if ($supplierId) {
                $query->where('supplier_id', $supplierId);
            }

            if ($status) {
                $query->where('status', $status);
            }

            if ($dateFrom) {
                $query->whereDate('purchase_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('purchase_date', '<=', $dateTo);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('purchase_number', 'like', "%{$search}%")
                        ->orWhere('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('supplier', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                });
            }

            $purchases = $query->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            return successResponse('Purchases retrieved successfully', $purchases);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve purchases', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve purchases', $e->getMessage());
        }
    }

    /**
     * Create new purchase order
     */
    /**
     * Create new purchase order
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'branch_id' => 'required|exists:branches,id',
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:purchase_date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_id' => 'nullable|exists:taxes,id',
            'currency' => 'nullable|string|size:3',
            'currency_id' => 'nullable|exists:currencies,id', // Added validation
            'exchange_rate' => 'nullable|numeric|min:0',
            'status' => 'sometimes|in:draft,ordered',
            'invoice_number' => 'nullable|string',
            'notes' => 'nullable|string',
            'tax_inclusive' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // Generate unique purchase number
            $dateStr = date('Ymd', strtotime($request->purchase_date));
            $count = Purchase::withTrashed()
                ->where('business_id', $request->business_id)
                ->whereDate('purchase_date', $request->purchase_date)
                ->count();

            do {
                $count++;
                $purchaseNumber = 'PO-' . $dateStr . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
            } while (Purchase::withTrashed()->where('purchase_number', $purchaseNumber)->exists());

            // Calculate totals
            $subtotal = 0;
            $totalTax = 0;

            $isTaxInclusive = $request->tax_inclusive ?? false;

            foreach ($request->items as $item) {
                $quantity = $item['quantity'];
                $unitCost = $item['unit_cost'];
                $taxRate = $item['tax_rate'] ?? 0;

                if ($isTaxInclusive) {
                    // Inclusive: Tax on Gross
                    // Total = Unit Cost * Quantity
                    // Tax = Total * (Rate / 100)
                    // Subtotal = Total - Tax
                    $lineTotal = $unitCost * $quantity;
                    $lineTax = ($lineTotal * $taxRate) / 100;
                    $lineSubtotal = $lineTotal - $lineTax;

                    $subtotal += $lineSubtotal;
                    $totalTax += $lineTax;
                } else {
                    // Exclusive: Standard
                    $lineSubtotal = $unitCost * $quantity;
                    $lineTax = ($lineSubtotal * $taxRate) / 100;

                    $subtotal += $lineSubtotal;
                    $totalTax += $lineTax;
                }
            }

            $grandTotal = $subtotal + $totalTax;

            // Resolve Currency ID and Code
            $currencyCode = $request->currency ?? 'USD';
            $currencyId = $request->currency_id;

            if ($currencyId && (!$request->has('currency') || !$request->currency)) {
                $currencyModel = \App\Models\Currency::find($currencyId);
                if ($currencyModel)
                    $currencyCode = $currencyModel->code;
            } elseif ($currencyCode && !$currencyId) {
                $currencyModel = \App\Models\Currency::where('code', $currencyCode)->first();
                if ($currencyModel)
                    $currencyId = $currencyModel->id;
            }

            $exchangeRate = $request->exchange_rate ?? 1.0;

            // Create Purchase
            $purchase = Purchase::create([
                'business_id' => $request->business_id,
                'branch_id' => $request->branch_id,
                'supplier_id' => $request->supplier_id,
                'purchase_number' => $purchaseNumber,
                'purchase_date' => $request->purchase_date,
                'expected_delivery_date' => $request->expected_delivery_date,
                'subtotal' => $subtotal,
                'tax_amount' => $totalTax,
                'total_amount' => $grandTotal,
                'currency' => $currencyCode,
                'currency_id' => $currencyId,
                'exchange_rate' => $exchangeRate,
                'status' => $request->status ?? 'draft',
                'invoice_number' => $request->invoice_number,
                'notes' => $request->notes,
                'tax_inclusive' => $request->tax_inclusive ?? false,
                'created_by' => Auth::id(),
            ]);

            $isTaxInclusive = $request->tax_inclusive ?? false;

            // Create Purchase Items
            foreach ($request->items as $item) {
                $quantity = $item['quantity'];
                $unitCost = $item['unit_cost'];
                $taxRate = $item['tax_rate'] ?? 0;

                if ($isTaxInclusive) {
                    $lineTotal = $unitCost * $quantity;
                    $lineTax = ($lineTotal * $taxRate) / 100;
                    // For inclusive, unit_cost is the inclusive cost. 
                    // Should we store base unit cost or inclusive unit cost?
                    // Usually systems store the entered unit cost. 
                    // But calculations need to be consistent. 
                    // Let's stick to the logic: line_total is accurate.
                } else {
                    $lineSubtotal = $unitCost * $quantity;
                    $lineTax = ($lineSubtotal * $taxRate) / 100;
                    $lineTotal = $lineSubtotal + $lineTax;
                }

                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $item['product_id'],
                    'quantity_ordered' => $quantity,
                    'unit_cost' => $unitCost,
                    'tax_rate' => $taxRate,
                    'tax_id' => $item['tax_id'] ?? null,
                    'tax_amount' => $lineTax,
                    'line_total' => $lineTotal,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            $purchase->load(['supplier', 'branch', 'items.product', 'createdBy', 'currencyModel']);

            return successResponse('Purchase order created successfully', $purchase, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create purchase', [
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to create purchase', $e->getMessage());
        }
    }

    /**
     * Display specific purchase order
     */
    public function show(Purchase $purchase)
    {
        try {
            $purchase->load([
                'supplier',
                'business',
                'branch',
                'items.product',
                'createdBy',
                'receivedBy',
                'currencyModel'
            ]);

            return successResponse('Purchase retrieved successfully', $purchase);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve purchase', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve purchase', $e->getMessage());
        }
    }

    /**
     * Update purchase order (only if draft)
     */
    public function update(Request $request, Purchase $purchase)
    {
        if ($purchase->status !== 'draft') {
            return errorResponse('Can only update draft purchase orders', 400);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'sometimes|exists:suppliers,id',
            'purchase_date' => 'sometimes|date',
            'expected_delivery_date' => 'nullable|date',
            'items' => 'sometimes|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_id' => 'nullable|exists:taxes,id',
            'status' => 'sometimes|in:draft,ordered',
            'invoice_number' => 'nullable|string',
            'notes' => 'nullable|string',
            'currency' => 'sometimes|string|size:3',
            'currency_id' => 'sometimes|exists:currencies,id',
            'exchange_rate' => 'sometimes|numeric|min:0.0001',
            'tax_inclusive' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $purchase->update($request->only([
                'supplier_id',
                'purchase_date',
                'expected_delivery_date',
                'status',
                'invoice_number',
                'notes',
                'exchange_rate',
                'tax_inclusive'
            ]));

            // Handle Currency Update
            $currencyId = $request->currency_id;
            $currencyCode = $request->currency;

            if ($currencyId && (!$currencyCode)) {
                $currencyModel = \App\Models\Currency::find($currencyId);
                if ($currencyModel)
                    $currencyCode = $currencyModel->code;
            } elseif ($currencyCode && !$currencyId) {
                $currencyModel = \App\Models\Currency::where('code', $currencyCode)->first();
                if ($currencyModel)
                    $currencyId = $currencyModel->id;
            }

            if ($currencyId || $currencyCode) {
                $updates = [];
                if ($currencyId)
                    $updates['currency_id'] = $currencyId;
                if ($currencyCode)
                    $updates['currency'] = $currencyCode;
                $purchase->update($updates);
            }

            if ($request->has('items')) {
                // Delete old items
                $purchase->items()->delete();

                // Recalculate totals
                $subtotal = 0;
                $totalTax = 0;
                $isTaxInclusive = $request->has('tax_inclusive') ? $request->tax_inclusive : ($purchase->tax_inclusive ?? false);

                foreach ($request->items as $item) {
                    $quantity = $item['quantity'];
                    $unitCost = $item['unit_cost'];
                    $taxRate = $item['tax_rate'] ?? 0;

                    if ($isTaxInclusive) {
                        $lineTotal = $unitCost * $quantity;
                        $lineTax = ($lineTotal * $taxRate) / 100;
                        $lineSubtotal = $lineTotal - $lineTax;

                        $subtotal += $lineSubtotal;
                        $totalTax += $lineTax;
                    } else {
                        $lineSubtotal = $unitCost * $quantity;
                        $lineTax = ($lineSubtotal * $taxRate) / 100;
                        $lineTotal = $lineSubtotal + $lineTax;

                        $subtotal += $lineSubtotal;
                        $totalTax += $lineTax;
                    }

                    PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id' => $item['product_id'],
                        'quantity_ordered' => $quantity,
                        'unit_cost' => $unitCost,
                        'tax_rate' => $taxRate,
                        'tax_id' => $item['tax_id'] ?? null,
                        'tax_amount' => $lineTax,
                        'line_total' => $lineTotal,
                        'notes' => $item['notes'] ?? null,
                    ]);
                }

                $purchase->update([
                    'subtotal' => $subtotal,
                    'tax_amount' => $totalTax,
                    'total_amount' => $subtotal + $totalTax,
                ]);
            }

            DB::commit();

            $purchase->load(['supplier', 'items.product']);

            return updatedResponse($purchase, 'Purchase updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update purchase', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to update purchase', $e->getMessage());
        }
    }

    /**
     * Receive stock (CRITICAL FUNCTION - Increases inventory)
     */
    public function receive(Request $request, Purchase $purchase)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.purchase_item_id' => 'required|exists:purchase_items,id',
            'items.*.quantity_received' => 'required|numeric|min:0.01',
            'received_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        if ($purchase->status === 'cancelled') {
            return errorResponse('Cannot receive items from cancelled purchase', 400);
        }

        try {
            DB::beginTransaction();

            $receivedDate = $request->received_date ?? now();
            $allItemsFullyReceived = true;

            foreach ($request->items as $itemData) {
                $purchaseItem = PurchaseItem::findOrFail($itemData['purchase_item_id']);

                // Validate purchase item belongs to this purchase
                if ($purchaseItem->purchase_id != $purchase->id) {
                    return errorResponse('Purchase item does not belong to this purchase', 400);
                }

                $quantityReceived = $itemData['quantity_received'];
                $newTotalReceived = $purchaseItem->quantity_received + $quantityReceived;

                // Calculate Unit Cost in Base Currency
                // Purchase items are stored in purchase currency. Stock must be valued in Base Currency.
                $exchangeRate = $purchase->exchange_rate ?? 1.0;
                $unitCostBase = $purchaseItem->unit_cost * $exchangeRate;

                // Validate not receiving more than ordered
                if ($newTotalReceived > $purchaseItem->quantity_ordered) {
                    $product = Product::find($purchaseItem->product_id);
                    return errorResponse("Cannot receive more than ordered for {$product->name}", 400);
                }

                // Update purchase item
                $purchaseItem->update([
                    'quantity_received' => $newTotalReceived
                ]);

                // Check if fully received
                if ($newTotalReceived < $purchaseItem->quantity_ordered) {
                    $allItemsFullyReceived = false;
                }

                // Find or create Stock record
                $stock = Stock::firstOrCreate(
                    [
                        'business_id' => $purchase->business_id,
                        'branch_id' => $purchase->branch_id,
                        'product_id' => $purchaseItem->product_id,
                    ],
                    [
                        'quantity' => 0,
                        'reserved_quantity' => 0,
                        'unit_cost' => $unitCostBase,
                    ]
                );

                $previousQuantity = $stock->quantity;
                $newQuantity = $previousQuantity + $quantityReceived;

                // === CREATE STOCK BATCH (FIFO) ===
                $stockBatch = StockBatch::create([
                    'business_id' => $purchase->business_id,
                    'branch_id' => $purchase->branch_id,
                    'stock_id' => $stock->id,
                    'product_id' => $purchaseItem->product_id,
                    'purchase_item_id' => $purchaseItem->id,
                    'purchase_reference' => $purchase->purchase_number,
                    'quantity_received' => $quantityReceived,
                    'quantity_remaining' => $quantityReceived,
                    'unit_cost' => $unitCostBase,
                    'received_date' => $receivedDate,
                    'notes' => $request->notes,
                ]);

                // Calculate Weighted Average Cost
                $currentValuation = $previousQuantity * $stock->unit_cost;
                $receivedValuation = $quantityReceived * $unitCostBase;
                $newValuation = $currentValuation + $receivedValuation;
                $newAverageCost = ($newQuantity > 0) ? ($newValuation / $newQuantity) : $stock->unit_cost;

                // Update total stock quantity and unit cost
                $stock->update([
                    'quantity' => $newQuantity,
                    'unit_cost' => $newAverageCost,
                ]);

                // Create Stock Movement
                StockMovement::create([
                    'business_id' => $purchase->business_id,
                    'branch_id' => $purchase->branch_id,
                    'product_id' => $purchaseItem->product_id,
                    'user_id' => Auth::id(),
                    'movement_type' => 'purchase',
                    'quantity' => $quantityReceived,
                    'previous_quantity' => $previousQuantity,
                    'new_quantity' => $newQuantity,
                    'unit_cost' => $unitCostBase,
                    'reference_type' => 'App\Models\Purchase',
                    'reference_id' => $purchase->id,
                    'notes' => "Purchase received: {$purchase->purchase_number}. Batch: {$stockBatch->batch_number}",
                ]);


            }

            // Update purchase status
            if ($allItemsFullyReceived) {
                $purchase->update([
                    'status' => 'received',
                    'received_date' => $receivedDate,
                    'received_by' => Auth::id(),
                ]);
            } else {
                $purchase->update([
                    'status' => 'partially_received',
                ]);
            }

            DB::commit();

            $purchase->load(['items.product', 'supplier', 'receivedBy']);

            return successResponse('Stock received successfully', $purchase);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to receive stock', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to receive stock', $e->getMessage());
        }
    }

    /**
     * Cancel purchase order
     */
    public function cancel(Request $request, Purchase $purchase)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        if ($purchase->status === 'received') {
            return errorResponse('Cannot cancel purchase that has been received', 400);
        }

        try {
            $purchase->update([
                'status' => 'cancelled',
                'notes' => ($purchase->notes ?? '') . "\nCancelled: {$request->reason}",
            ]);

            return successResponse('Purchase cancelled successfully', $purchase);
        } catch (\Exception $e) {
            Log::error('Failed to cancel purchase', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to cancel purchase', $e->getMessage());
        }
    }

    /**
     * Delete purchase order (soft delete)
     */
    public function destroy(Purchase $purchase)
    {
        if ($purchase->status === 'received' || $purchase->status === 'partially_received') {
            return errorResponse('Cannot delete purchase with received stock', 400);
        }

        try {
            $purchase->delete();

            return deleteResponse('Purchase deleted successfully');
        } catch (\Exception $e) {
            Log::error('Failed to delete purchase', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete purchase', $e->getMessage());
        }
    }

    /**
     * Get purchase history for specific supplier
     */
    public function supplierPurchases(Request $request, Supplier $supplier)
    {
        try {
            $perPage = $request->input('per_page', 20);

            $purchases = Purchase::where('supplier_id', $supplier->id)
                ->with(['items.product', 'branch'])
                ->orderBy('purchase_date', 'desc')
                ->paginate($perPage);

            $summary = [
                'total_purchases' => Purchase::where('supplier_id', $supplier->id)->count(),
                'total_spent' => Purchase::where('supplier_id', $supplier->id)
                    ->where('status', '!=', 'cancelled')
                    ->sum('total_amount'),
                'average_order_value' => Purchase::where('supplier_id', $supplier->id)
                    ->where('status', '!=', 'cancelled')
                    ->avg('total_amount'),
            ];

            return successResponse('Supplier purchases retrieved successfully', [
                'supplier' => $supplier,
                'summary' => $summary,
                'purchases' => $purchases,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve supplier purchases', [
                'supplier_id' => $supplier->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve supplier purchases', $e->getMessage());
        }
    }

}