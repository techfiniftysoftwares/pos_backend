<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    /**
     * Display payment history with filtering
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $customerId = $request->input('customer_id');
            $paymentMethodId = $request->input('payment_method_id');
            $status = $request->input('status');
            $paymentType = $request->input('payment_type');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $search = $request->input('search');

            $query = Payment::query()
                ->with(['business', 'branch', 'paymentMethod', 'customer', 'processedBy']);

            // Filter by business
            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            // Filter by branch
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            // Filter by customer
            if ($customerId) {
                $query->where('customer_id', $customerId);
            }

            // Filter by payment method
            if ($paymentMethodId) {
                $query->where('payment_method_id', $paymentMethodId);
            }

            // Filter by status
            if ($status) {
                $query->where('status', $status);
            }

            // Filter by payment type
            if ($paymentType) {
                $query->where('payment_type', $paymentType);
            }

            // Date range filter
            if ($dateFrom) {
                $query->whereDate('payment_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('payment_date', '<=', $dateTo);
            }

            // Search by reference number or transaction ID
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('reference_number', 'like', "%{$search}%")
                        ->orWhere('transaction_id', 'like', "%{$search}%");
                });
            }

            $payments = $query->orderBy('payment_date', 'desc')
                ->paginate($perPage);

            return successResponse('Payments retrieved successfully', $payments);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve payments', $e->getMessage());
        }
    }

    /**
     * Record new payment
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'branch_id' => 'required|exists:branches,id',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'customer_id' => 'nullable|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'transaction_id' => 'nullable|string|max:255',
            'reference_type' => 'nullable|string|max:50',
            'reference_id' => 'nullable|integer',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $paymentMethod = PaymentMethod::findOrFail($request->payment_method_id);

            // Validate amount limits
            if (!$paymentMethod->isAmountValid($request->amount)) {
                $min = $paymentMethod->minimum_amount;
                $max = $paymentMethod->maximum_amount;
                return errorResponse("Amount must be between {$min} and {$max}", 400);
            }

            // Calculate fees
            $feeAmount = $paymentMethod->calculateFee($request->amount);
            $netAmount = $request->amount - $feeAmount;

            // Create payment
            $payment = Payment::create([
                'business_id' => $request->business_id,
                'branch_id' => $request->branch_id,
                'payment_method_id' => $request->payment_method_id,
                'customer_id' => $request->customer_id,
                'amount' => $request->amount,
                'currency' => $request->currency ?? 'USD',
                'exchange_rate' => $request->exchange_rate ?? 1.0,
                'fee_amount' => $feeAmount,
                'net_amount' => $netAmount,
                'transaction_id' => $request->transaction_id,
                'status' => 'completed',
                'payment_type' => 'payment',
                'reference_type' => $request->reference_type,
                'reference_id' => $request->reference_id,
                'payment_date' => $request->payment_date ?? now(),
                'notes' => $request->notes,
                'metadata' => $request->metadata,
                'processed_by' => Auth::id(),
            ]);

            DB::commit();

            $payment->load(['business', 'branch', 'paymentMethod', 'customer', 'processedBy']);

            return successResponse('Payment recorded successfully', $payment, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to record payment', $e->getMessage());
        }
    }

    /**
     * Display specific payment
     */
    public function show(Payment $payment)
    {
        try {
            $payment->load([
                'business',
                'branch',
                'paymentMethod',
                'customer',
                'processedBy',
                'reconciledBy',
                'refunds',
                'originalPayment'
            ]);

            $paymentData = [
                'id' => $payment->id,
                'reference_number' => $payment->reference_number,
                'transaction_id' => $payment->transaction_id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'fee_amount' => $payment->fee_amount,
                'net_amount' => $payment->net_amount,
                'status' => $payment->status,
                'payment_type' => $payment->payment_type,
                'payment_date' => $payment->payment_date->format('Y-m-d H:i:s'),
                'reconciled_at' => $payment->reconciled_at?->format('Y-m-d H:i:s'),
                'notes' => $payment->notes,
                'metadata' => $payment->metadata,
                'business' => $payment->business->only(['id', 'name']),
                'branch' => $payment->branch->only(['id', 'name', 'code']),
                'payment_method' => $payment->paymentMethod->only(['id', 'name', 'type']),
                'customer' => $payment->customer ? $payment->customer->only(['id', 'customer_number', 'name']) : null,
                'processed_by' => $payment->processedBy->only(['id', 'name']),
                'reconciled_by' => $payment->reconciledBy ? $payment->reconciledBy->only(['id', 'name']) : null,
                'refunds' => $payment->refunds->map(function ($refund) {
                    return [
                        'id' => $refund->id,
                        'reference_number' => $refund->reference_number,
                        'amount' => $refund->amount,
                        'date' => $refund->payment_date->format('Y-m-d'),
                    ];
                }),
                'refundable_amount' => $payment->canBeRefunded() ? $payment->getRefundableAmount() : 0,
            ];

            return successResponse('Payment retrieved successfully', $paymentData);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve payment', $e->getMessage());
        }
    }

    /**
     * Update payment (limited fields)
     */
    public function update(Request $request, Payment $payment)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'sometimes|nullable|string',
            'metadata' => 'sometimes|nullable|array',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $payment->update($validator->validated());

            return updatedResponse($payment, 'Payment updated successfully');
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to update payment', $e->getMessage());
        }
    }

    /**
     * Delete payment (soft delete)
     */
    public function destroy(Payment $payment)
    {
        try {
            if ($payment->status === 'completed' && $payment->reconciled_at) {
                return errorResponse('Cannot delete reconciled payment', 400);
            }

            $payment->delete();

            return deleteResponse('Payment deleted successfully');
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to delete payment', $e->getMessage());
        }
    }

    /**
     * Process refund
     */
    public function refund(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|exists:payments,id',
            'amount' => 'required|numeric|min:0.01',
            'branch_id' => 'required|exists:branches,id',
            'reason' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $originalPayment = Payment::findOrFail($request->payment_id);

            // Validate refund
            if (!$originalPayment->canBeRefunded()) {
                return errorResponse('Payment cannot be refunded', 400);
            }

            if ($request->amount > $originalPayment->getRefundableAmount()) {
                return errorResponse('Refund amount exceeds refundable amount', 400);
            }

            // Create refund payment
            $refund = Payment::create([
                'business_id' => $originalPayment->business_id,
                'branch_id' => $request->branch_id,
                'payment_method_id' => $originalPayment->payment_method_id,
                'customer_id' => $originalPayment->customer_id,
                'amount' => $request->amount,
                'currency' => $originalPayment->currency,
                'exchange_rate' => $originalPayment->exchange_rate,
                'fee_amount' => 0,
                'net_amount' => $request->amount,
                'status' => 'completed',
                'payment_type' => 'refund',
                'reference_type' => 'App\Models\Payment',
                'reference_id' => $originalPayment->id,
                'payment_date' => now(),
                'notes' => 'Refund: ' . $request->reason . ($request->notes ? "\n" . $request->notes : ''),
                'processed_by' => Auth::id(),
            ]);

            // Update original payment status if fully refunded
            if ($originalPayment->getRefundableAmount() == 0) {
                $originalPayment->update(['status' => 'refunded']);
            }

            DB::commit();

            $refund->load(['paymentMethod', 'processedBy']);

            return successResponse('Refund processed successfully', $refund, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to process refund', $e->getMessage());
        }
    }

    /**
     * Mark payment as reconciled
     */
    public function reconcile(Request $request, Payment $payment)
    {
        $validator = Validator::make($request->all(), [
            'reconciled_by' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            if ($payment->isReconciled()) {
                return errorResponse('Payment already reconciled', 400);
            }

            $payment->reconciled_at = now();
            $payment->reconciled_by = $request->reconciled_by;
            $payment->save();

            return successResponse('Payment marked as reconciled', $payment);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to reconcile payment', $e->getMessage());
        }
    }

    /**
     * Mark payment as failed
     */
    public function markAsFailed(Request $request, Payment $payment)
    {
        $validator = Validator::make($request->all(), [
            'failure_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $payment->status = 'failed';
            $payment->failure_reason = $request->failure_reason;
            $payment->save();

            return successResponse('Payment marked as failed', $payment);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to mark payment as failed', $e->getMessage());
        }
    }

    /**
     * Get unreconciled payments
     */
    public function unreconciled(Request $request)
    {
        try {
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $perPage = $request->input('per_page', 20);

            $query = Payment::query()
                ->with(['paymentMethod', 'customer', 'branch'])
                ->unreconciled();

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            $payments = $query->orderBy('payment_date', 'desc')
                ->paginate($perPage);

            return successResponse('Unreconciled payments retrieved successfully', $payments);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve unreconciled payments', $e->getMessage());
        }
    }

    /**
     * Get failed payments
     */
    public function failed(Request $request)
    {
        try {
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $perPage = $request->input('per_page', 20);

            $query = Payment::query()
                ->with(['paymentMethod', 'customer', 'branch', 'processedBy'])
                ->failed();

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            $payments = $query->orderBy('payment_date', 'desc')
                ->paginate($perPage);

            return successResponse('Failed payments retrieved successfully', $payments);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve failed payments', $e->getMessage());
        }
    }
}
