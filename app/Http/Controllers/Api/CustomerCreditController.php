<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class CustomerCreditController extends Controller
{
    /**
     * Display all credit transactions with filtering
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $customerId = $request->input('customer_id');
            $transactionType = $request->input('transaction_type');
            $branchId = $request->input('branch_id');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $search = $request->input('search');

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');

            // Whitelist of allowed sortable columns
            $allowedSortColumns = [
                'transaction_type',
                'amount',
                'previous_balance',
                'new_balance',
                'created_at',
                'updated_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'created_at';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            $query = CustomerCreditTransaction::query()
                ->with(['customer', 'paymentMethod', 'processedBy', 'branch']);

            // Filter by customer
            if ($customerId) {
                $query->where('customer_id', $customerId);
            }

            // Filter by transaction type
            if ($transactionType) {
                $query->where('transaction_type', $transactionType);
            }

            // Filter by branch
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            // Date range filter
            if ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            // Search filter (reference number)
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('reference_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($cq) use ($search) {
                            $cq->where('name', 'like', "%{$search}%")
                                ->orWhere('customer_number', 'like', "%{$search}%");
                        });
                });
            }

            $transactions = $query->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            return successResponse('Credit transactions retrieved successfully', $transactions);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve credit transactions', $e->getMessage());
        }
    }

    /**
     * Record a credit sale
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'transaction_type' => 'required|in:sale,payment,adjustment',
            'amount' => 'required|numeric|min:0.01',
            'payment_method_id' => 'nullable|exists:payment_methods,id',
            'reference_number' => 'nullable|string|max:255',
            'branch_id' => 'required|exists:branches,id',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $customer = Customer::findOrFail($request->customer_id);

            // Check credit limit for sales (skip if credit_limit is null = unlimited)
            if ($request->transaction_type === 'sale' && !is_null($customer->credit_limit)) {
                $newBalance = $customer->current_credit_balance + $request->amount;
                if ($newBalance > $customer->credit_limit) {
                    return errorResponse('Credit limit exceeded. Available credit: ' .
                        ($customer->credit_limit - $customer->current_credit_balance), 400);
                }
            }

            // Create transaction
            $transaction = CustomerCreditTransaction::create([
                'customer_id' => $request->customer_id,
                'transaction_type' => $request->transaction_type,
                'amount' => $request->amount,
                'payment_method_id' => $request->payment_method_id,
                'reference_number' => $request->reference_number,
                'notes' => $request->notes,
                'processed_by' => Auth::id(),
                'branch_id' => $request->branch_id,
            ]);

            DB::commit();

            $transaction->load(['customer', 'paymentMethod', 'processedBy', 'branch']);

            return successResponse('Credit transaction recorded successfully', $transaction, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to record credit transaction', $e->getMessage());
        }
    }

    /**
     * Display specific transaction
     */
    public function show(CustomerCreditTransaction $customerCreditTransaction)
    {
        try {
            $customerCreditTransaction->load([
                'customer',
                'paymentMethod',
                'processedBy',
                'branch'
            ]);

            return successResponse('Credit transaction retrieved successfully', $customerCreditTransaction);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve credit transaction', $e->getMessage());
        }
    }

    /**
     * Update transaction (limited fields)
     */
    public function update(Request $request, CustomerCreditTransaction $customerCreditTransaction)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'sometimes|nullable|string',
            'reference_number' => 'sometimes|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Only allow updating notes and reference number for audit trail
            $customerCreditTransaction->update($validator->validated());

            return updatedResponse($customerCreditTransaction, 'Credit transaction updated successfully');
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to update credit transaction', $e->getMessage());
        }
    }

    /**
     * Delete transaction (soft delete or prevent)
     */
    public function destroy(CustomerCreditTransaction $customerCreditTransaction)
    {
        // For financial transactions, you might want to prevent deletion
        return errorResponse('Credit transactions cannot be deleted for audit purposes. Use adjustments instead.', 403);
    }

    /**
     * Get customer credit summary
     */
    public function customerSummary(Customer $customer)
    {
        try {
            $summary = [
                'customer' => $customer->only(['id', 'customer_number', 'name', 'email', 'phone']),
                'credit_limit' => $customer->credit_limit,
                'current_balance' => $customer->current_credit_balance,
                'available_credit' => $customer->credit_limit - $customer->current_credit_balance,
                'total_sales' => CustomerCreditTransaction::where('customer_id', $customer->id)
                    ->where('transaction_type', 'sale')
                    ->sum('amount'),
                'total_payments' => CustomerCreditTransaction::where('customer_id', $customer->id)
                    ->where('transaction_type', 'payment')
                    ->sum('amount'),
                'transaction_count' => CustomerCreditTransaction::where('customer_id', $customer->id)->count(),
                'last_transaction' => CustomerCreditTransaction::where('customer_id', $customer->id)
                    ->latest()
                    ->first(),
            ];

            return successResponse('Customer credit summary retrieved successfully', $summary);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve customer summary', $e->getMessage());
        }
    }

    /**
     * Get outstanding credit customers
     */
    public function outstandingCredits(Request $request)
    {
        try {
            $businessId = $request->input('business_id');
            $minAmount = $request->input('min_amount', 0);
            $perPage = $request->input('per_page', 20);

            $query = Customer::query()
                ->with(['business'])
                ->where('current_credit_balance', '>', $minAmount);

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            $customers = $query->orderBy('current_credit_balance', 'desc')
                ->paginate($perPage);

            $customers->getCollection()->transform(function ($customer) {
                return [
                    'id' => $customer->id,
                    'customer_number' => $customer->customer_number,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                    'credit_limit' => $customer->credit_limit,
                    'current_balance' => $customer->current_credit_balance,
                    'available_credit' => $customer->credit_limit - $customer->current_credit_balance,
                    'last_payment' => CustomerCreditTransaction::where('customer_id', $customer->id)
                        ->where('transaction_type', 'payment')
                        ->latest()
                        ->first()?->created_at?->format('Y-m-d'),
                ];
            });

            return successResponse('Outstanding credits retrieved successfully', $customers);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve outstanding credits', $e->getMessage());
        }
    }

    /**
     * Get credit aging report
     */
    public function agingReport(Request $request)
    {
        try {
            $businessId = $request->input('business_id');

            $query = Customer::query()
                ->where('current_credit_balance', '>', 0);

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            $customers = $query->get();

            $aging = [
                'current' => [],      // 0-30 days
                'days_31_60' => [],   // 31-60 days
                'days_61_90' => [],   // 61-90 days
                'over_90' => [],      // Over 90 days
            ];

            foreach ($customers as $customer) {
                $lastSale = CustomerCreditTransaction::where('customer_id', $customer->id)
                    ->where('transaction_type', 'sale')
                    ->latest()
                    ->first();

                if ($lastSale) {
                    $daysOld = now()->diffInDays($lastSale->created_at);

                    $customerData = [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'balance' => $customer->current_credit_balance,
                        'days_old' => $daysOld,
                    ];

                    if ($daysOld <= 30) {
                        $aging['current'][] = $customerData;
                    } elseif ($daysOld <= 60) {
                        $aging['days_31_60'][] = $customerData;
                    } elseif ($daysOld <= 90) {
                        $aging['days_61_90'][] = $customerData;
                    } else {
                        $aging['over_90'][] = $customerData;
                    }
                }
            }

            $summary = [
                'current_total' => collect($aging['current'])->sum('balance'),
                'days_31_60_total' => collect($aging['days_31_60'])->sum('balance'),
                'days_61_90_total' => collect($aging['days_61_90'])->sum('balance'),
                'over_90_total' => collect($aging['over_90'])->sum('balance'),
                'total_outstanding' => $customers->sum('current_credit_balance'),
            ];

            return successResponse('Aging report generated successfully', [
                'aging' => $aging,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to generate aging report', $e->getMessage());
        }
    }
}
