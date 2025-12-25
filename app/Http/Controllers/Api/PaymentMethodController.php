<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PaymentMethodController extends Controller
{
    /**
     * Display all payment methods
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $type = $request->input('type');
            $isActive = $request->input('is_active');
            $search = $request->input('search');

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'sort_order');
            $sortDirection = $request->input('sort_direction', 'asc');

            // Whitelist of allowed sortable columns
            $allowedSortColumns = [
                'name',
                'code',
                'type',
                'transaction_fee_percentage',
                'transaction_fee_fixed',
                'minimum_amount',
                'maximum_amount',
                'requires_reference',
                'is_active',
                'sort_order',
                'created_at',
                'updated_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'sort_order';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            $query = PaymentMethod::query()->with(['business']);

            // Filter by business
            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            // Filter by type
            if ($type) {
                $query->where('type', $type);
            }

            // Filter by active status
            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            // Search filter
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%");
                });
            }

            $paymentMethods = $query->orderBy($sortBy, $sortDirection)->paginate($perPage);

            return successResponse('Payment methods retrieved successfully', $paymentMethods);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve payment methods', $e->getMessage());
        }
    }

    /**
     * Store new payment method
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:50',
            'code' => 'nullable|string|unique:payment_methods,code',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'requires_reference' => 'sometimes|boolean',
            'transaction_fee_percentage' => 'nullable|numeric|min:0|max:100',
            'transaction_fee_fixed' => 'nullable|numeric|min:0',
            'minimum_amount' => 'nullable|numeric|min:0',
            'maximum_amount' => 'nullable|numeric|min:0',
            'supported_currencies' => 'nullable|array',
            'icon' => 'nullable|string|max:255',
            'config' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // If setting as default, unset other defaults
            if ($request->is_default) {
                PaymentMethod::where('business_id', $request->business_id)
                    ->update(['is_default' => false]);
            }

            $paymentMethod = PaymentMethod::create($validator->validated());

            DB::commit();

            return successResponse('Payment method created successfully', $paymentMethod, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to create payment method', $e->getMessage());
        }
    }

    /**
     * Display specific payment method
     */
    public function show(PaymentMethod $paymentMethod)
    {
        try {
            $paymentMethod->load(['business']);

            return successResponse('Payment method retrieved successfully', $paymentMethod);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve payment method', $e->getMessage());
        }
    }

    /**
     * Update payment method
     */
    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|max:50',
            'code' => 'sometimes|string|unique:payment_methods,code,' . $paymentMethod->id,
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'requires_reference' => 'sometimes|boolean',
            'transaction_fee_percentage' => 'sometimes|nullable|numeric|min:0|max:100',
            'transaction_fee_fixed' => 'sometimes|nullable|numeric|min:0',
            'minimum_amount' => 'sometimes|nullable|numeric|min:0',
            'maximum_amount' => 'sometimes|nullable|numeric|min:0',
            'supported_currencies' => 'sometimes|nullable|array',
            'icon' => 'sometimes|nullable|string|max:255',
            'config' => 'sometimes|nullable|array',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // If setting as default, unset other defaults
            if ($request->is_default && !$paymentMethod->is_default) {
                PaymentMethod::where('business_id', $paymentMethod->business_id)
                    ->where('id', '!=', $paymentMethod->id)
                    ->update(['is_default' => false]);
            }

            $paymentMethod->update($validator->validated());

            DB::commit();

            return updatedResponse($paymentMethod, 'Payment method updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to update payment method', $e->getMessage());
        }
    }

    /**
     * Delete payment method
     */
    public function destroy(PaymentMethod $paymentMethod)
    {
        try {
            DB::beginTransaction();

            // Check if used in transactions
            if ($paymentMethod->creditTransactions()->count() > 0 || $paymentMethod->payments()->count() > 0) {
                return errorResponse('Cannot delete payment method that has been used in transactions', 400);
            }

            $paymentMethod->delete();

            DB::commit();

            return deleteResponse('Payment method deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to delete payment method', $e->getMessage());
        }
    }

    /**
     * Toggle payment method status
     */
    public function toggleStatus(PaymentMethod $paymentMethod)
    {
        try {
            $newStatus = !$paymentMethod->is_active;
            $paymentMethod->update(['is_active' => $newStatus]);

            $statusText = $newStatus ? 'activated' : 'deactivated';
            return successResponse("Payment method {$statusText} successfully", $paymentMethod);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to toggle payment method status', $e->getMessage());
        }
    }

    /**
     * Calculate fee for amount
     */
    public function calculateFee(Request $request, PaymentMethod $paymentMethod)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $amount = $request->amount;
            $fee = $paymentMethod->calculateFee($amount);
            $netAmount = $paymentMethod->calculateNetAmount($amount);

            return successResponse('Fee calculated successfully', [
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $netAmount,
                'fee_percentage' => $paymentMethod->transaction_fee_percentage,
                'fee_fixed' => $paymentMethod->transaction_fee_fixed,
            ]);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to calculate fee', $e->getMessage());
        }
    }

    /**
     * Reorder payment methods
     */
    public function reorder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'orders' => 'required|array',
            'orders.*.id' => 'required|exists:payment_methods,id',
            'orders.*.sort_order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            foreach ($request->orders as $order) {
                PaymentMethod::where('id', $order['id'])
                    ->where('business_id', $request->business_id)
                    ->update(['sort_order' => $order['sort_order']]);
            }

            DB::commit();

            return successResponse('Payment methods reordered successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to reorder payment methods', $e->getMessage());
        }
    }
}
