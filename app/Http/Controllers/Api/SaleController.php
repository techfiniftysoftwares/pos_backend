<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\Stock;
use App\Models\ExchangeRate;
use App\Models\Business;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Payment;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\LoyaltyProgramSetting;
use App\Models\CustomerPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SaleController extends Controller
{
    /**
     * Display sales history with filtering
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $customerId = $request->input('customer_id');
            $status = $request->input('status');
            $paymentType = $request->input('payment_type');
            $paymentStatus = $request->input('payment_status');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $search = $request->input('search');

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');

            // Whitelist of allowed sortable columns to prevent SQL injection
            $allowedSortColumns = [
                'sale_number',
                'created_at',
                'completed_at',
                'total_amount',
                'subtotal',
                'tax_amount',
                'discount_amount',
                'status',
                'payment_type',
                'payment_status',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'created_at';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

            $query = Sale::query()
                ->with(['customer', 'cashier', 'branch', 'items.product']);

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            if ($customerId) {
                if ($customerId === 'walk-in') {
                    $query->whereNull('customer_id');
                } else {
                    $query->where('customer_id', $customerId);
                }
            }

            if ($status) {
                $query->where('status', $status);
            }

            if ($paymentType) {
                $query->where('payment_type', $paymentType);
            }

            if ($paymentStatus) {
                $query->where('payment_status', $paymentStatus);
            }

            if ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            $cashierId = $request->input('cashier_id');

            // ... (keep existing sorting/pagination setup if needed, but I am replacing the lines before loop)

            // (I will just inject the cashier filter and update search logic.
            // I need to be careful with context. 'dateTo' ends at 102. 'search' usage starts at 105.)

            if ($cashierId) {
                $query->where('cashier_id', $cashierId);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('sale_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('cashier', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                });
            }

            $sales = $query->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            return successResponse('Sales retrieved successfully', $sales);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve sales', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return queryErrorResponse('Failed to retrieve sales', $e->getMessage());
        }
    }

    /**
     * Calculate totals before completing sale (preview)
     */
    public function calculateTotals(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'currency_id' => 'required|exists:currencies,id', // 🆕 Changed from 'currency' string
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // 🆕 Get currency object
            $currency = Currency::findOrFail($request->currency_id);

            $items = $request->items;
            $subtotal = 0;
            $totalTax = 0;
            $totalDiscount = 0;
            $itemsBreakdown = [];

            foreach ($items as $item) {
                $product = Product::with('tax')->findOrFail($item['product_id']);
                $quantity = $item['quantity'];
                $unitPrice = $product->selling_price;
                $itemDiscount = $item['discount_amount'] ?? 0;

                $baseLineTotal = ($unitPrice * $quantity) - $itemDiscount;
                $taxRate = $product->tax ? $product->tax->rate : ($product->tax_rate ?? 0);
                $isTaxInclusive = $product->tax_inclusive;

                if ($isTaxInclusive) {
                    // Tax on Gross (User Requirement: Tax = Total * Rate / 100 for inclusive)
                    // Or standard VAT? Sticking to user's previous "Tax is % of inclusive total"
                    $taxAmount = ($baseLineTotal * $taxRate) / 100;
                    $lineSubtotal = $baseLineTotal - $taxAmount; // Net Amount
                } else {
                    // Exclusive: Price is Net
                    $lineSubtotal = $baseLineTotal;
                    $taxAmount = ($lineSubtotal * $taxRate) / 100;
                    // Gross = Net + Tax
                }

                $subtotal += $lineSubtotal;
                $totalTax += $taxAmount;
                $totalDiscount += $itemDiscount;

                $itemsBreakdown[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => $itemDiscount,
                    'tax_rate' => (float) $taxRate,
                    'tax_amount' => round($taxAmount, 2),
                    'line_total' => round($isTaxInclusive ? $baseLineTotal : ($lineSubtotal + $taxAmount), 2), // Show Gross
                ];
            }

            $grandTotal = $subtotal + $totalTax;

            return successResponse('Totals calculated successfully', [
                'items' => $itemsBreakdown,
                'subtotal' => round($subtotal, 2),
                'tax_amount' => round($totalTax, 2),
                'discount_amount' => round($totalDiscount, 2),
                'total_amount' => round($grandTotal, 2),
                'currency_id' => $currency->id, // 🆕
                'currency_code' => $currency->code, // 🆕
                'currency_symbol' => $currency->symbol, // 🆕
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to calculate totals', [
                'error' => $e->getMessage(),
            ]);
            return serverErrorResponse('Failed to calculate totals', $e->getMessage());
        }
    }
    /**
     * Create new sale (complete transaction with multi-currency support)
     */
    /**
     * Create new sale (complete transaction with multi-currency support)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'branch_id' => 'required|exists:branches,id',
            'customer_id' => 'nullable|exists:customers,id',
            'currency_id' => 'required|exists:currencies,id',
            'sale_to_base_exchange_rate' => 'required|numeric|min:0.0001',
            'total_in_base_currency' => 'required|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'payment_type' => 'required|in:cash,credit,mixed',
            'payments' => 'required_if:payment_type,cash,mixed|array',
            'payments.*.payment_method_id' => 'required_with:payments|exists:payment_methods,id',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.currency_id' => 'required_with:payments|exists:currencies,id',
            'payments.*.exchange_rate' => 'required_with:payments|numeric|min:0.0001',
            'payments.*.transaction_id' => 'nullable|string',
            'notes' => 'nullable|string',
            'loyalty_points_used' => 'nullable|integer|min:0',
            'loyalty_points_discount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // Get business and base currency
            $business = Business::with('baseCurrency')->findOrFail($request->business_id);

            if (!$business->base_currency_id) {
                return errorResponse('Business does not have a base currency configured. Please contact administrator.', 400);
            }

            // Use base currency for reporting
            $baseCurrency = $business->baseCurrency;
            $saleCurrency = Currency::findOrFail($request->currency_id);

            // Check stock availability for all items first
            foreach ($request->items as $item) {
                // ... (stock check logic remains same)
                $stock = Stock::where('product_id', $item['product_id'])
                    ->where('branch_id', $request->branch_id)
                    ->where('business_id', $request->business_id)
                    ->first();

                if (!$stock || $stock->available_quantity < $item['quantity']) {
                    $product = Product::find($item['product_id']);
                    return errorResponse("Insufficient stock for {$product->name}. Available: " . ($stock->available_quantity ?? 0), 400);
                }

                // Validate Exchange Rate existence if currencies differ
                $product = Product::with('currency')->find($item['product_id']);
                $productCurrency = $product->currency ?? $baseCurrency;

                if ($productCurrency->id !== $saleCurrency->id) {
                    $conversionRate = ExchangeRate::getCurrentRate(
                        $productCurrency->id,
                        $saleCurrency->id,
                        $request->business_id
                    );

                    if (!$conversionRate) {
                        return errorResponse(
                            "No exchange rate found for {$productCurrency->code} → {$saleCurrency->code}. Please add an exchange rate first.",
                            400
                        );
                    }
                }
            }

            // Calculate totals in SALE CURRENCY
            $subtotal = 0;
            $totalTax = 0;
            $totalDiscount = 0;

            foreach ($request->items as $item) {
                $product = Product::with(['currency', 'tax'])->findOrFail($item['product_id']);
                $quantity = $item['quantity'];
                $unitPrice = $product->selling_price; // In product currency

                // Determine product currency
                $productCurrency = $product->currency ?? $baseCurrency;

                // Get exchange rate for product currency → sale currency (if needed)
                $productToSaleRate = 1.0;
                if ($productCurrency->id !== $saleCurrency->id) {
                    $conversionRate = ExchangeRate::getCurrentRate(
                        $productCurrency->id,
                        $saleCurrency->id,
                        $request->business_id
                    );
                    // Rate existence validated in previous loop
                    if ($conversionRate) {
                        $productToSaleRate = $conversionRate->rate;
                    }
                }

                // Convert product price to sale currency if needed
                if ($productCurrency->id !== $saleCurrency->id) {
                    $unitPrice = $unitPrice * $productToSaleRate;
                }

                $itemDiscount = $item['discount_amount'] ?? 0;

                $baseLineTotal = ($unitPrice * $quantity) - $itemDiscount;
                $taxRate = $product->tax ? $product->tax->rate : ($product->tax_rate ?? 0);
                $isTaxInclusive = $product->tax_inclusive;

                if ($isTaxInclusive) {
                    $taxAmount = ($baseLineTotal * $taxRate) / 100;
                    $netSubtotal = $baseLineTotal - $taxAmount;
                } else {
                    $netSubtotal = $baseLineTotal;
                    $taxAmount = ($netSubtotal * $taxRate) / 100;
                }

                $subtotal += $netSubtotal;
                $totalTax += $taxAmount;
                $totalDiscount += $itemDiscount;
            }

            // Grand Total (matches the sum of inclusive prices)
            $grandTotal = $subtotal + $totalTax;

            // Validate multi-currency payment amounts
            if ($request->payment_type !== 'credit') {
                $totalPaidInSaleCurrency = 0;

                foreach ($request->payments as $payment) {
                    // Convert payment currency to sale currency
                    $amountInSaleCurrency = $payment['amount'] * $payment['exchange_rate'];
                    $totalPaidInSaleCurrency += $amountInSaleCurrency;
                }

                // Allow change (overpayment), but block underpayment
                // 🆕 Account for loyalty discount
                $loyaltyDiscount = $request->loyalty_points_discount ?? 0;
                $expectedPayment = $grandTotal - $loyaltyDiscount;

                if ($totalPaidInSaleCurrency < ($expectedPayment - 0.01)) {
                    return errorResponse(
                        "Payment mismatch. Expected: " . round($expectedPayment, 2) . " {$saleCurrency->code}, Received: " . round($totalPaidInSaleCurrency, 2) . " {$saleCurrency->code}",
                        400
                    );
                }
            }

            // Calculate change amount
            $totalPaidInSaleCurrency = 0;
            if ($request->payment_type !== 'credit') {
                foreach ($request->payments as $payment) {
                    $totalPaidInSaleCurrency += $payment['amount'] * $payment['exchange_rate'];
                }
            }
            $changeAmount = max(0, $totalPaidInSaleCurrency - ($grandTotal - ($request->loyalty_points_discount ?? 0)));

            // Credit sale validation
            if ($request->payment_type === 'credit') {
                if (!$request->customer_id) {
                    return errorResponse('Customer is required for credit sales', 400);
                }

                $customer = Customer::findOrFail($request->customer_id);

                // Only validate credit limit if customer has a limit set (not unlimited)
                if (!is_null($customer->credit_limit)) {
                    $availableCredit = $customer->credit_limit - $customer->current_credit_balance;

                    if ($grandTotal > $availableCredit) {
                        return errorResponse("Credit limit exceeded. Available credit: {$availableCredit}", 400);
                    }
                }
                // If credit_limit is null, customer has unlimited credit - no validation needed
            }


            // Determine Real Payment Status & Credit Status
            $loyaltyDiscount = $request->loyalty_points_discount ?? 0;
            $remainingDue = $grandTotal - $loyaltyDiscount - $totalPaidInSaleCurrency;
            $isFullyPaid = $remainingDue <= 0.01;

            $finalPaymentStatus = $request->payment_type === 'credit' ? 'unpaid' : 'paid';
            if ($isFullyPaid) {
                $finalPaymentStatus = 'paid';
            } elseif (($totalPaidInSaleCurrency > 0 || $loyaltyDiscount > 0) && $request->payment_type === 'credit') {
                $finalPaymentStatus = 'partial';
            }

            // A sale is only a "credit sale" if there is an unpaid balance AND the user selected credit
            $finalIsCreditSale = ($request->payment_type === 'credit' && !$isFullyPaid);

            // If fully paid but type was credit (e.g. loyalty covered it), switch to 'mixed'
            $finalPaymentType = $request->payment_type;
            if ($isFullyPaid && $finalPaymentType === 'credit') {
                $finalPaymentType = 'mixed';
            }

            // Create Sale
            $sale = Sale::create([
                'business_id' => $request->business_id,
                'branch_id' => $request->branch_id,
                'customer_id' => $request->customer_id,
                'user_id' => Auth::id(),
                'currency_id' => $request->currency_id,
                'currency' => $saleCurrency->code,
                'subtotal' => round($subtotal, 2),
                'tax_amount' => round($totalTax, 2),
                'discount_amount' => round($totalDiscount, 2),
                'total_amount' => round($grandTotal, 2),
                'change_amount' => round($changeAmount, 2),
                'exchange_rate' => $request->sale_to_base_exchange_rate,
                'total_in_base_currency' => round($request->total_in_base_currency, 2),
                'status' => 'completed',
                'payment_type' => $finalPaymentType,
                'payment_status' => $finalPaymentStatus,
                'is_credit_sale' => $finalIsCreditSale,
                'notes' => $request->notes,
                'loyalty_points_used' => $request->loyalty_points_used ?? 0,
                'loyalty_points_discount' => $request->loyalty_points_discount ?? 0,
                'completed_at' => now(),
            ]);

            // Create credit transaction for credit sales (record the debt)
            // Only create if there is actual remaining debt
            if ($finalIsCreditSale && $remainingDue > 0) {
                CustomerCreditTransaction::create([
                    'customer_id' => $request->customer_id,
                    'transaction_type' => 'sale',
                    'amount' => round($remainingDue, 2),
                    'reference_number' => $sale->sale_number,
                    'processed_by' => Auth::id(),
                    'branch_id' => $request->branch_id,
                    'notes' => "Credit sale: {$sale->sale_number}",
                ]);
            }

            // Create Sale Items and Deduct Stock Using FIFO
            foreach ($request->items as $item) {
                $product = Product::with(['currency', 'tax'])->findOrFail($item['product_id']);
                $quantity = $item['quantity'];
                $unitPrice = $product->selling_price; // In product currency (base currency)

                // Determine product currency for THIS item
                $itemProductCurrency = $product->currency ?? $baseCurrency;

                // Get exchange rate for product currency → sale currency (if needed)
                $itemProductToSaleRate = 1.0;
                if ($itemProductCurrency->id !== $saleCurrency->id) {
                    $conversionRate = ExchangeRate::getCurrentRate(
                        $itemProductCurrency->id,
                        $saleCurrency->id,
                        $request->business_id
                    );
                    if ($conversionRate) {
                        $itemProductToSaleRate = $conversionRate->rate;
                    }
                }

                // Convert product price to sale currency if needed
                if ($itemProductCurrency->id !== $saleCurrency->id) {
                    $unitPrice = $unitPrice * $itemProductToSaleRate;
                }

                $itemDiscount = $item['discount_amount'] ?? 0;

                $baseLineTotal = ($unitPrice * $quantity) - $itemDiscount;
                $taxRate = $product->tax ? $product->tax->rate : ($product->tax_rate ?? 0);
                $isTaxInclusive = $product->tax_inclusive;

                if ($isTaxInclusive) {
                    $taxAmount = ($baseLineTotal * $taxRate) / 100;
                    $lineSubtotal = $baseLineTotal - $taxAmount; // Net
                    $lineTotal = $baseLineTotal; // Gross
                } else {
                    $lineSubtotal = $baseLineTotal; // Net
                    $taxAmount = ($lineSubtotal * $taxRate) / 100;
                    $lineTotal = $lineSubtotal + $taxAmount; // Gross
                }

                // === FIFO BATCH DEDUCTION LOGIC ===
                $stock = Stock::where('product_id', $product->id)
                    ->where('branch_id', $request->branch_id)
                    ->where('business_id', $request->business_id)
                    ->lockForUpdate()
                    ->first();

                $previousQuantity = $stock->quantity;
                $remainingToDeduct = $quantity;
                $totalCostUsed = 0;

                // Get available batches in FIFO order (oldest first)
                $batches = StockBatch::where('stock_id', $stock->id)
                    ->where('quantity_remaining', '>', 0)
                    ->orderBy('received_date', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($batches as $batch) {
                    if ($remainingToDeduct <= 0) {
                        break;
                    }

                    $deductFromThisBatch = min($batch->quantity_remaining, $remainingToDeduct);

                    // Update batch remaining quantity
                    $batch->decrement('quantity_remaining', $deductFromThisBatch);

                    // Track cost from this batch
                    $totalCostUsed += $deductFromThisBatch * $batch->unit_cost;
                    $remainingToDeduct -= $deductFromThisBatch;
                }

                // Calculate weighted average cost used for this sale
                $averageUnitCost = $quantity > 0 ? $totalCostUsed / $quantity : 0;

                // Update total stock quantity
                $newQuantity = $previousQuantity - $quantity;
                $stock->update(['quantity' => $newQuantity]);

                // Create SaleItem with cost basis
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => round($unitPrice, 2), // Converted price in sale currency
                    'unit_cost' => $averageUnitCost,
                    'tax_rate' => $taxRate,
                    'tax_amount' => round($taxAmount, 2),
                    'tax_inclusive' => $isTaxInclusive,
                    'tax_name' => $product->tax ? $product->tax->name : null,
                    'discount_amount' => $itemDiscount,
                    'line_total' => round($lineTotal, 2),
                ]);

                // Create Stock Movement
                StockMovement::create([
                    'business_id' => $request->business_id,
                    'branch_id' => $request->branch_id,
                    'product_id' => $product->id,
                    'user_id' => Auth::id(),
                    'movement_type' => 'sale',
                    'quantity' => -$quantity,
                    'previous_quantity' => $previousQuantity,
                    'new_quantity' => $newQuantity,
                    'unit_cost' => $averageUnitCost,
                    'reference_type' => 'App\Models\Sale',
                    'reference_id' => $sale->id,
                    'notes' => "Sale: {$sale->sale_number}",
                ]);
            }

            // Handle Multi-Currency Payments & Credit Logic
            $totalPaidInitial = 0;

            // Process payments if provided (even for credit/partial sales)
            if (!empty($request->payments)) {
                foreach ($request->payments as $paymentData) {
                    // Convert payment amount to sale currency
                    $amountInSaleCurrency = $paymentData['amount'] * $paymentData['exchange_rate'];
                    $totalPaidInitial += $amountInSaleCurrency;

                    // Get payment currency
                    $paymentCurrency = Currency::findOrFail($paymentData['currency_id']);

                    // Convert payment to base currency (for reporting)
                    $paymentToBaseRate = 1.0;
                    $amountInBaseCurrency = $paymentData['amount'];

                    // If payment currency is different from base currency
                    if ($paymentCurrency->id !== $baseCurrency->id) {
                        // First convert to sale currency, then to base currency
                        $amountInSaleCurrency = $paymentData['amount'] * $paymentData['exchange_rate'];
                        $amountInBaseCurrency = $amountInSaleCurrency * $request->sale_to_base_exchange_rate;
                        $paymentToBaseRate = $paymentData['exchange_rate'] * $request->sale_to_base_exchange_rate;
                    } else {
                        // Payment is already in base currency
                        $amountInBaseCurrency = $paymentData['amount'];
                    }

                    // Create Payment record
                    $payment = Payment::create([
                        'business_id' => $request->business_id,
                        'branch_id' => $request->branch_id,
                        'payment_method_id' => $paymentData['payment_method_id'],
                        'customer_id' => $request->customer_id,
                        'amount' => $paymentData['amount'],
                        'currency_id' => $paymentData['currency_id'],
                        'currency' => $paymentCurrency->code,
                        'exchange_rate' => $paymentToBaseRate,
                        'amount_in_base_currency' => round($amountInBaseCurrency, 2),
                        'status' => 'completed',
                        'payment_type' => 'payment',
                        'transaction_id' => $paymentData['transaction_id'] ?? null,
                        'payment_date' => now(),
                        'processed_by' => Auth::id(),
                    ]);

                    // Link Payment to Sale with currency details
                    SalePayment::create([
                        'sale_id' => $sale->id,
                        'payment_id' => $payment->id,
                        'amount' => $paymentData['amount'],
                        'currency_id' => $paymentData['currency_id'],
                        'exchange_rate' => $paymentData['exchange_rate'],
                        'amount_in_sale_currency' => round($amountInSaleCurrency, 2),
                    ]);

                    // If it's a credit sale, this payment reduces the debt we just created
                    if ($request->payment_type === 'credit') {
                        CustomerCreditTransaction::create([
                            'customer_id' => $request->customer_id,
                            'transaction_type' => 'payment',
                            'amount' => $amountInSaleCurrency, // Credit transactions tracked in sale currency? Assumed base/sale alignment or just value.
                            // Ideally credit implies the currency of the customer account.
                            // Assuming single currency logic for Credit Limit usually, or base currency.
                            // But here we use sale currency amount (or base?).
                            // The 'sale' transaction used $grandTotal (Sale Currency).
                            // So 'payment' should use $amountInSaleCurrency.
                            'reference_number' => $payment->reference_number,
                            'processed_by' => Auth::id(),
                            'branch_id' => $request->branch_id,
                            'notes' => "Payment for credit sale: {$sale->sale_number}",
                        ]);
                    }
                }
            }

            // Update status if partial payment
            if ($request->payment_type === 'credit' && $totalPaidInitial > 0) {
                // Determine if fully paid (tolerant to small diffs)
                if ($totalPaidInitial >= ($grandTotal - 0.01)) {
                    $sale->update([
                        'payment_status' => 'paid',
                        'is_credit_sale' => false // Optionally mark as no longer credit? keeping true preserves history.
                    ]);
                } else {
                    $sale->update(['payment_status' => 'partial']);
                }
            }

            // Award loyalty points to customer (based on AMOUNT PAID for consistency)
            $pointsEarned = 0;
            if ($sale->customer_id && $totalPaidInitial > 0) {
                $loyaltySettings = LoyaltyProgramSetting::where('business_id', $request->business_id)
                    ->where('is_active', true)
                    ->first();

                if ($loyaltySettings) {
                    // Award points based on amount actually paid (supports partial payments)
                    // BUT skip if points were redeemed/used in this sale (no double dipping)
                    $pointsRedeemed = $request->loyalty_points_used ?? 0;
                    if ($pointsRedeemed > 0) {
                        $pointsEarned = 0; // No points earned on redemption transactions

                        // Deduct redeemed points
                        CustomerPoint::create([
                            'customer_id' => $sale->customer_id,
                            'transaction_type' => 'redeemed',
                            'points' => -$pointsRedeemed, // Negative for deduction
                            'reference_type' => 'App\\Models\\Sale',
                            'reference_id' => $sale->id,
                            'expires_at' => null, // Redemption has no expiry
                            'processed_by' => Auth::id(),
                            'branch_id' => $request->branch_id,
                            'notes' => "Points redeemed for sale: {$sale->sale_number} (Discount: " . round($request->loyalty_points_discount, 2) . " {$saleCurrency->code})",
                        ]);

                    } else {
                        // Convert paid amount to base currency for consistent point calculation
                        $paidInBaseCurrency = $totalPaidInitial * $request->sale_to_base_exchange_rate;
                        $pointsEarned = $loyaltySettings->calculatePointsEarned($paidInBaseCurrency);
                    }

                    if ($pointsEarned > 0) {
                        CustomerPoint::create([
                            'customer_id' => $sale->customer_id,
                            'transaction_type' => 'earned',
                            'points' => $pointsEarned,
                            'reference_type' => 'App\\Models\\Sale',
                            'reference_id' => $sale->id,
                            'expires_at' => $loyaltySettings->getPointExpirationDate(),
                            'processed_by' => Auth::id(),
                            'branch_id' => $request->branch_id,
                            'notes' => "Points earned from sale: {$sale->sale_number} (paid: " . round($paidInBaseCurrency, 2) . ")",
                        ]);
                    }
                }
            }

            DB::commit();

            $sale->load([
                'items.product',
                'customer',
                'cashier',
                'branch',
                'currency',
                'salePayments.payment.paymentMethod',
                'salePayments.payment.currency',
                'salePayments.currency'
            ]);

            return successResponse('Sale completed successfully', [
                'sale' => $sale,
                'points_earned' => $pointsEarned,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create sale', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to create sale', $e->getMessage());
        }
    }
    /**
     * Display specific sale
     */
    public function show(Sale $sale)
    {
        try {
            $sale->load([
                'business',
                'branch',
                'customer',
                'cashier',
                'items.product.currency',
                'salePayments.payment.paymentMethod',
                'salePayments.payment.currency',
                'salePayments.currency',
                'return'
            ]);

            return successResponse('Sale retrieved successfully', $sale);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve sale', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve sale', $e->getMessage());
        }
    }

    /**
     * Cancel sale with full reversals
     */
    public function cancel(Request $request, Sale $sale)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            if (!$sale->canBeCancelled()) {
                return errorResponse('This sale has already been cancelled', 400);
            }

            DB::beginTransaction();

            // ========== 1. RESTORE STOCK FOR ALL ITEMS ==========
            foreach ($sale->items as $item) {
                $stock = Stock::where('product_id', $item->product_id)
                    ->where('branch_id', $sale->branch_id)
                    ->where('business_id', $sale->business_id)
                    ->lockForUpdate()
                    ->first();

                if ($stock) {
                    $previousQuantity = $stock->quantity;
                    $newQuantity = $previousQuantity + $item->quantity;

                    $stock->update(['quantity' => $newQuantity]);

                    // Create Stock Movement for the reversal
                    StockMovement::create([
                        'business_id' => $sale->business_id,
                        'branch_id' => $sale->branch_id,
                        'product_id' => $item->product_id,
                        'user_id' => Auth::id(),
                        'movement_type' => 'cancellation',
                        'quantity' => $item->quantity,
                        'previous_quantity' => $previousQuantity,
                        'new_quantity' => $newQuantity,
                        'unit_cost' => $item->unit_cost ?? $stock->unit_cost,
                        'reference_type' => 'App\Models\Sale',
                        'reference_id' => $sale->id,
                        'notes' => "Sale cancelled: {$sale->sale_number}. Reason: {$request->reason}",
                    ]);
                }
            }

            // ========== 2. REVERSE PAYMENT RECORDS ==========
            $salePayments = $sale->salePayments()->with('payment')->get();
            foreach ($salePayments as $salePayment) {
                if ($salePayment->payment) {
                    // Mark payment as refunded
                    $salePayment->payment->update([
                        'status' => 'refunded',
                        'notes' => ($salePayment->payment->notes ?? '') . "\nRefunded due to sale cancellation: {$sale->sale_number}",
                    ]);
                }
            }

            // ========== 3. REVERSE CUSTOMER CREDIT TRANSACTIONS (for credit sales) ==========
            if ($sale->is_credit_sale && $sale->customer_id) {
                // Find and reverse credit transactions related to this sale
                $creditTransactions = CustomerCreditTransaction::where('reference_number', $sale->sale_number)
                    ->where('customer_id', $sale->customer_id)
                    ->get();

                foreach ($creditTransactions as $creditTx) {
                    // Create an offsetting transaction
                    $offsetAmount = $creditTx->transaction_type === 'sale'
                        ? -$creditTx->amount  // Reduce debt (negative adjustment)
                        : $creditTx->amount;   // Restore debt if it was a payment

                    CustomerCreditTransaction::create([
                        'customer_id' => $sale->customer_id,
                        'transaction_type' => 'adjustment',
                        'amount' => $offsetAmount,
                        'reference_number' => $sale->sale_number,
                        'processed_by' => Auth::id(),
                        'branch_id' => $sale->branch_id,
                        'notes' => "Reversal due to sale cancellation: {$sale->sale_number}. Reason: {$request->reason}",
                    ]);
                }
            }

            // ========== 4. REVERSE LOYALTY POINTS ==========
            if ($sale->customer_id) {
                // Find all point transactions related to this sale
                $pointTransactions = CustomerPoint::where('reference_type', 'App\Models\Sale')
                    ->where('reference_id', $sale->id)
                    ->where('customer_id', $sale->customer_id)
                    ->get();

                foreach ($pointTransactions as $pointTx) {
                    if ($pointTx->transaction_type === 'earned' && $pointTx->points > 0) {
                        // Reverse earned points - create negative cancellation entry
                        CustomerPoint::create([
                            'customer_id' => $sale->customer_id,
                            'transaction_type' => 'cancelled',
                            'points' => -$pointTx->points, // Negative to deduct
                            'reference_type' => 'App\Models\Sale',
                            'reference_id' => $sale->id,
                            'expires_at' => null,
                            'processed_by' => Auth::id(),
                            'branch_id' => $sale->branch_id,
                            'notes' => "Points reversed due to sale cancellation: {$sale->sale_number}",
                        ]);
                    } elseif ($pointTx->transaction_type === 'redeemed' && $pointTx->points < 0) {
                        // Restore redeemed points - create positive restoration entry
                        CustomerPoint::create([
                            'customer_id' => $sale->customer_id,
                            'transaction_type' => 'restored',
                            'points' => abs($pointTx->points), // Positive to restore
                            'reference_type' => 'App\Models\Sale',
                            'reference_id' => $sale->id,
                            'expires_at' => null, // Restored points don't expire
                            'processed_by' => Auth::id(),
                            'branch_id' => $sale->branch_id,
                            'notes' => "Points restored due to sale cancellation: {$sale->sale_number}",
                        ]);
                    }
                }
            }

            // ========== 5. UPDATE SALE STATUS ==========
            $sale->update([
                'status' => 'cancelled',
                'cancel_reason' => $request->reason,
                'notes' => ($sale->notes ?? '') . "\nCancelled by " . Auth::user()->name . " on " . now()->format('Y-m-d H:i:s') . ": {$request->reason}",
            ]);

            DB::commit();

            // Reload sale with relationships
            $sale->load(['items.product', 'customer', 'salePayments.payment']);

            return successResponse('Sale cancelled successfully. All transactions have been reversed.', $sale);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to cancel sale', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to cancel sale', $e->getMessage());
        }
    }

    /**
     * Hold/park incomplete sale
     */
    public function hold(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'branch_id' => 'required|exists:branches,id',
            'sale_data' => 'required|array',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $holdNumber = 'HOLD-' . date('YmdHis') . '-' . strtoupper(substr(uniqid(), -4));

            $heldSale = HeldSale::create([
                'business_id' => $request->business_id,
                'branch_id' => $request->branch_id,
                'user_id' => Auth::id(),
                'hold_number' => $holdNumber,
                'sale_data' => $request->sale_data,
                'notes' => $request->notes,
            ]);

            return successResponse('Sale held successfully', $heldSale, 201);
        } catch (\Exception $e) {
            Log::error('Failed to hold sale', [
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to hold sale', $e->getMessage());
        }
    }

    /**
     * Get all held sales
     */
    public function getHeldSales(Request $request)
    {
        try {
            $branchId = $request->input('branch_id');
            $businessId = $request->input('business_id');

            $query = HeldSale::with(['user', 'branch']);

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            $heldSales = $query->orderBy('created_at', 'desc')->get();

            return successResponse('Held sales retrieved successfully', $heldSales);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve held sales', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve held sales', $e->getMessage());
        }
    }

    /**
     * Recall/resume held sale
     */
    public function recallHeld(HeldSale $heldSale)
    {
        try {
            $saleData = $heldSale->sale_data;

            $heldSale->delete();

            return successResponse('Held sale recalled successfully', [
                'sale_data' => $saleData,
                'notes' => $heldSale->notes,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to recall held sale', [
                'held_sale_id' => $heldSale->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to recall held sale', $e->getMessage());
        }
    }

    /**
     * Add payment to existing sale
     */
    public function addPayment(Request $request, Sale $sale)
    {
        $validator = Validator::make($request->all(), [
            'payment_type' => 'required|in:cash,credit,mixed',
            'payments' => 'required_if:payment_type,cash,mixed|array',
            'payments.*.payment_method_id' => 'required_with:payments|exists:payment_methods,id',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.currency_id' => 'required_with:payments|exists:currencies,id',
            'payments.*.exchange_rate' => 'required_with:payments|numeric|min:0.0001',
            'payments.*.transaction_id' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            if ($sale->payment_status === 'paid') {
                return errorResponse('Sale is already fully paid', 400);
            }

            DB::beginTransaction();

            // Get business base currency
            $business = Business::with('baseCurrency')->findOrFail($sale->business_id);
            $baseCurrency = $business->baseCurrency;

            if ($request->payment_type !== 'credit') {
                foreach ($request->payments as $paymentData) {
                    // Get payment currency
                    $paymentCurrency = Currency::findOrFail($paymentData['currency_id']);

                    // Calculate Base Currency Amount (Payment -> Base)
                    // We check if a direct rate exists, otherwise default to 1
                    $paymentToBaseRate = 1.0;
                    if ($paymentCurrency->id !== $baseCurrency->id) {
                        $rateObj = ExchangeRate::getCurrentRate($paymentCurrency->id, $baseCurrency->id, $sale->business_id);
                        $paymentToBaseRate = $rateObj ? $rateObj->rate : 1.0;
                    }

                    $amountInBaseCurrency = $paymentData['amount'] * $paymentToBaseRate;

                    // Create Payment
                    $payment = Payment::create([
                        'business_id' => $sale->business_id,
                        'branch_id' => $sale->branch_id,
                        'payment_method_id' => $paymentData['payment_method_id'],
                        'customer_id' => $sale->customer_id,
                        'amount' => $paymentData['amount'],
                        'currency_id' => $paymentData['currency_id'],
                        'currency' => $paymentCurrency->code,
                        'exchange_rate' => $paymentToBaseRate,
                        'amount_in_base_currency' => round($amountInBaseCurrency, 2),
                        'status' => 'completed',
                        'payment_type' => 'payment',
                        'transaction_id' => $paymentData['transaction_id'] ?? null,
                        'payment_date' => now(),
                        'processed_by' => Auth::id(),
                        'reference_type' => 'App\Models\Sale',
                        'reference_id' => $sale->id,
                        'notes' => $request->notes,
                    ]);

                    // Link to Sale
                    $amountInSaleCurrency = $paymentData['amount'] * $paymentData['exchange_rate'];

                    SalePayment::create([
                        'sale_id' => $sale->id,
                        'payment_id' => $payment->id,
                        'amount' => $paymentData['amount'],
                        'currency_id' => $paymentData['currency_id'],
                        'exchange_rate' => $paymentData['exchange_rate'], // Payment -> Sale Rate
                        'amount_in_sale_currency' => round($amountInSaleCurrency, 2),
                    ]);

                    // Create credit transaction for credit sales (reduce the debt)
                    if ($sale->is_credit_sale && $sale->customer_id) {
                        CustomerCreditTransaction::create([
                            'customer_id' => $sale->customer_id,
                            'transaction_type' => 'payment',
                            'amount' => round($amountInSaleCurrency, 2),
                            'payment_method_id' => $paymentData['payment_method_id'],
                            'reference_number' => $payment->id,
                            'processed_by' => Auth::id(),
                            'branch_id' => $sale->branch_id,
                            'notes' => "Payment for credit sale: {$sale->sale_number}",
                        ]);
                    }
                }
            }

            // Recalculate total paid
            $totalPaid = SalePayment::where('sale_id', $sale->id)->sum('amount_in_sale_currency');

            $remainingDue = $sale->total_amount - $totalPaid;

            // Tolerance for floating point
            if ($remainingDue <= 0.01) {
                $sale->payment_status = 'paid';
            } else {
                $sale->payment_status = 'partial';
            }

            $sale->save();

            DB::commit();

            return successResponse('Payment added successfully', $sale->fresh(['salePayments.payment.paymentMethod']));

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add payment', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to add payment', $e->getMessage());
        }
    }

}
