<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReturnTransaction;
use App\Models\ReturnItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ReturnController extends Controller
{
    /**
     * Display return history with filtering
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $status = $request->input('status');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $search = $request->input('search');

            $query = ReturnTransaction::query()
                ->with(['originalSale', 'processedBy', 'items.product']);

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            if ($status) {
                $query->where('status', $status);
            }

            if ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            if ($search) {
                $query->where('return_number', 'like', "%{$search}%");
            }

            $returns = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return successResponse('Returns retrieved successfully', $returns);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve returns', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return queryErrorResponse('Failed to retrieve returns', $e->getMessage());
        }
    }

    /**
     * Search for original sale to process return
     */
    public function searchOriginalSale(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => 'required|string',
            'business_id' => 'required|exists:businesses,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $search = $request->search;

            $query = Sale::with(['items.product', 'customer', 'branch'])
                ->where('business_id', $request->business_id)
                ->where('status', 'completed');

            // Search by sale number, invoice number, or customer
            $query->where(function($q) use ($search) {
                $q->where('sale_number', 'like', "%{$search}%")
                  ->orWhere('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', function($customerQuery) use ($search) {
                      $customerQuery->where('name', 'like', "%{$search}%")
                          ->orWhere('phone', 'like', "%{$search}%")
                          ->orWhere('customer_number', 'like', "%{$search}%");
                  });
            });

            $sales = $query->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            if ($sales->isEmpty()) {
                return notFoundResponse('No sales found matching search criteria');
            }

            return successResponse('Sales found', $sales);
        } catch (\Exception $e) {
            Log::error('Failed to search original sale', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to search sale', $e->getMessage());
        }
    }

    /**
     * Process return/refund
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'branch_id' => 'required|exists:branches,id',
            'original_sale_id' => 'required|exists:sales,id',
            'items' => 'required|array|min:1',
            'items.*.sale_item_id' => 'required|exists:sale_items,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'reason' => 'required|string',
            'refund_method' => 'required|in:cash,original_method,store_credit,customer_credit',
            'payment_method_id' => 'required_if:refund_method,cash|exists:payment_methods,id',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $originalSale = Sale::with('items.product')->findOrFail($request->original_sale_id);

            // Validate sale can be returned
            if ($originalSale->status === 'refunded') {
                return errorResponse('This sale has already been fully refunded', 400);
            }

            // Calculate total return amount
            $totalReturnAmount = 0;
            $returnItemsData = [];

            foreach ($request->items as $item) {
                $saleItem = SaleItem::findOrFail($item['sale_item_id']);

                // Validate sale item belongs to original sale
                if ($saleItem->sale_id != $originalSale->id) {
                    return errorResponse('Sale item does not belong to the original sale', 400);
                }

                // Validate return quantity
                if ($item['quantity'] > $saleItem->quantity) {
                    return errorResponse("Return quantity cannot exceed original quantity for {$saleItem->product->name}", 400);
                }

                // Calculate return amount (proportional to quantity)
                $returnAmount = ($saleItem->line_total / $saleItem->quantity) * $item['quantity'];
                $totalReturnAmount += $returnAmount;

                $returnItemsData[] = [
                    'sale_item' => $saleItem,
                    'return_quantity' => $item['quantity'],
                    'return_amount' => $returnAmount,
                ];
            }

            // Create Return Transaction
            $returnTransaction = ReturnTransaction::create([
                'business_id' => $request->business_id,
                'branch_id' => $request->branch_id,
                'original_sale_id' => $originalSale->id,
                'total_amount' => $totalReturnAmount,
                'reason' => $request->reason,
                'status' => 'completed',
                'processed_by' => Auth::id(),
                'notes' => $request->notes,
            ]);

            // Create Return Items and Restore Stock
            foreach ($returnItemsData as $itemData) {
                $saleItem = $itemData['sale_item'];
                $returnQuantity = $itemData['return_quantity'];
                $returnAmount = $itemData['return_amount'];

                // Create Return Item
                ReturnItem::create([
                    'return_transaction_id' => $returnTransaction->id,
                    'sale_item_id' => $saleItem->id,
                    'product_id' => $saleItem->product_id,
                    'quantity' => $returnQuantity,
                    'amount' => $returnAmount,
                ]);

                // Restore Stock
                $stock = Stock::where('product_id', $saleItem->product_id)
                    ->where('branch_id', $request->branch_id)
                    ->where('business_id', $request->business_id)
                    ->lockForUpdate()
                    ->first();

                if ($stock) {
                    $previousQuantity = $stock->quantity;
                    $newQuantity = $previousQuantity + $returnQuantity;

                    $stock->update(['quantity' => $newQuantity]);

                    // Create Stock Movement
                    StockMovement::create([
                        'business_id' => $request->business_id,
                        'branch_id' => $request->branch_id,
                        'product_id' => $saleItem->product_id,
                        'user_id' => Auth::id(),
                        'movement_type' => 'return',
                        'quantity' => $returnQuantity,
                        'previous_quantity' => $previousQuantity,
                        'new_quantity' => $newQuantity,
                        'unit_cost' => $stock->unit_cost,
                        'reference_type' => 'App\Models\ReturnTransaction',
                        'reference_id' => $returnTransaction->id,
                        'notes' => "Return: {$returnTransaction->return_number}. Reason: {$request->reason}",
                    ]);
                }
            }

            // Process Refund
            if ($request->refund_method === 'customer_credit') {
                // Reduce customer credit balance
                if ($originalSale->customer_id) {
                    CustomerCreditTransaction::create([
                        'customer_id' => $originalSale->customer_id,
                        'transaction_type' => 'payment',
                        'amount' => $totalReturnAmount,
                        'reference_number' => $returnTransaction->return_number,
                        'processed_by' => Auth::id(),
                        'branch_id' => $request->branch_id,
                        'notes' => "Return refund: {$returnTransaction->return_number}",
                    ]);
                }
            } else {
                // Create refund payment
                $payment = Payment::create([
                    'business_id' => $request->business_id,
                    'branch_id' => $request->branch_id,
                    'payment_method_id' => $request->payment_method_id ?? $originalSale->salePayments->first()->payment->payment_method_id,
                    'customer_id' => $originalSale->customer_id,
                    'amount' => $totalReturnAmount,
                    'currency' => $originalSale->currency,
                    'exchange_rate' => $originalSale->exchange_rate,
                    'status' => 'completed',
                    'payment_type' => 'refund',
                    'reference_type' => 'App\Models\ReturnTransaction',
                    'reference_id' => $returnTransaction->id,
                    'payment_date' => now(),
                    'processed_by' => Auth::id(),
                    'notes' => "Refund for return: {$returnTransaction->return_number}",
                ]);
            }

            // Update original sale status
            $originalSale->update([
                'status' => 'partially_refunded',
                'payment_status' => 'refunded',
            ]);

            DB::commit();

            $returnTransaction->load(['originalSale', 'items.product', 'processedBy']);

            return successResponse('Return processed successfully', $returnTransaction, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process return', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to process return', $e->getMessage());
        }
    }

    /**
     * Display specific return
     */
    public function show(ReturnTransaction $returnTransaction)
    {
        try {
            $returnTransaction->load([
                'originalSale.customer',
                'originalSale.items.product',
                'items.product',
                'items.saleItem',
                'processedBy'
            ]);

            return successResponse('Return retrieved successfully', $returnTransaction);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve return', [
                'return_id' => $returnTransaction->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve return', $e->getMessage());
        }
    }
}
