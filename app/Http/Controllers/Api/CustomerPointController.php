<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPoint;
use App\Models\LoyaltyProgramSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class CustomerPointController extends Controller
{
    /**
     * Display all point transactions
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

            $query = CustomerPoint::query()
                ->with(['customer', 'processedBy', 'branch']);

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

            $points = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return successResponse('Point transactions retrieved successfully', $points);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve point transactions', $e->getMessage());
        }
    }

    /**
     * Record point transaction (earn/redeem/adjust)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'transaction_type' => 'required|in:earned,redeemed,expired,adjustment',
            'points' => 'required|integer',
            'reference_type' => 'nullable|string|max:50',
            'reference_id' => 'nullable|integer',
            'branch_id' => 'required|exists:branches,id',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $customer = Customer::findOrFail($request->customer_id);
            $currentBalance = $customer->getCurrentPointsBalance();

            // Validate redemption
            if ($request->transaction_type === 'redeemed') {
                $points = abs($request->points) * -1; // Ensure negative for redemption

                if ($currentBalance + $points < 0) {
                    return errorResponse('Insufficient points balance. Available: ' . $currentBalance, 400);
                }

                // Check minimum redemption
                $loyaltySettings = LoyaltyProgramSetting::where('business_id', $customer->business_id)->first();
                if ($loyaltySettings && abs($points) < $loyaltySettings->minimum_redemption_points) {
                    return errorResponse('Minimum redemption is ' . $loyaltySettings->minimum_redemption_points . ' points', 400);
                }
            } else {
                $points = abs($request->points); // Ensure positive for earned/adjustment
            }

            // Set expiration date for earned points
            $expiresAt = null;
            if ($request->transaction_type === 'earned') {
                $loyaltySettings = LoyaltyProgramSetting::where('business_id', $customer->business_id)->first();
                if ($loyaltySettings && $loyaltySettings->point_expiry_months) {
                    $expiresAt = now()->addMonths($loyaltySettings->point_expiry_months);
                }
            }

            // Create point transaction
            $pointTransaction = CustomerPoint::create([
                'customer_id' => $request->customer_id,
                'transaction_type' => $request->transaction_type,
                'points' => $points,
                'reference_type' => $request->reference_type,
                'reference_id' => $request->reference_id,
                'expires_at' => $expiresAt,
                'processed_by' => Auth::id(),
                'branch_id' => $request->branch_id,
                'notes' => $request->notes,
            ]);

            DB::commit();

            $pointTransaction->load(['customer', 'processedBy', 'branch']);

            return successResponse('Point transaction recorded successfully', $pointTransaction, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to record point transaction', $e->getMessage());
        }
    }

    /**
     * Display specific point transaction
     */
    public function show(CustomerPoint $customerPoint)
    {
        try {
            $customerPoint->load(['customer', 'processedBy', 'branch']);

            return successResponse('Point transaction retrieved successfully', $customerPoint);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve point transaction', $e->getMessage());
        }
    }

    /**
     * Update point transaction (limited)
     */
    public function update(Request $request, CustomerPoint $customerPoint)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Only allow updating notes
            $customerPoint->update($validator->validated());

            return updatedResponse($customerPoint, 'Point transaction updated successfully');
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to update point transaction', $e->getMessage());
        }
    }

    /**
     * Delete point transaction
     */
    public function destroy(CustomerPoint $customerPoint)
    {
        return errorResponse('Point transactions cannot be deleted for audit purposes. Use adjustments instead.', 403);
    }

    /**
     * Get customer points summary
     */
    public function customerSummary(Customer $customer)
    {
        try {
            $totalEarned = CustomerPoint::where('customer_id', $customer->id)
                ->where('transaction_type', 'earned')
                ->sum('points');

            $totalRedeemed = CustomerPoint::where('customer_id', $customer->id)
                ->where('transaction_type', 'redeemed')
                ->sum('points');

            $totalExpired = CustomerPoint::where('customer_id', $customer->id)
                ->where('transaction_type', 'expired')
                ->sum('points');

            $currentBalance = $customer->getCurrentPointsBalance();

            $expiringPoints = CustomerPoint::where('customer_id', $customer->id)
                ->where('transaction_type', 'earned')
                ->whereNotNull('expires_at')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->addDays(30))
                ->sum('points');

            $loyaltySettings = LoyaltyProgramSetting::where('business_id', $customer->business_id)->first();
            $cashValue = $loyaltySettings ? $loyaltySettings->calculateCurrencyValue($currentBalance) : 0;

            $summary = [
                'customer' => $customer->only(['id', 'customer_number', 'name', 'email', 'phone']),
                'current_balance' => $currentBalance,
                'cash_value' => $cashValue,
                'total_earned' => $totalEarned,
                'total_redeemed' => abs($totalRedeemed),
                'total_expired' => abs($totalExpired),
                'expiring_soon' => $expiringPoints,
                'expiring_days' => 30,
                'last_transaction' => CustomerPoint::where('customer_id', $customer->id)
                    ->latest()
                    ->first(),
            ];

            return successResponse('Customer points summary retrieved successfully', $summary);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve points summary', $e->getMessage());
        }
    }

    /**
     * Calculate points for purchase amount
     */
    public function calculateEarnedPoints(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'amount' => 'required|numeric|min:0',
            'purchase_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $loyaltySettings = LoyaltyProgramSetting::where('business_id', $request->business_id)
                ->where('is_active', true)
                ->first();

            if (!$loyaltySettings) {
                return errorResponse('Loyalty program not configured for this business', 404);
            }

            $purchaseDate = $request->purchase_date ? Carbon::parse($request->purchase_date) : now();
            $dayOfWeek = $purchaseDate->format('l');

            $points = $loyaltySettings->calculatePointsEarned($request->amount, $dayOfWeek);
            $multiplier = $loyaltySettings->getTodayMultiplier();

            return successResponse('Points calculated successfully', [
                'amount' => $request->amount,
                'points_earned' => $points,
                'base_rate' => $loyaltySettings->points_per_currency,
                'multiplier' => $multiplier,
                'day_of_week' => $dayOfWeek,
            ]);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to calculate points', $e->getMessage());
        }
    }

    /**
     * Calculate redemption value
     */
    public function calculateRedemptionValue(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'points' => 'required|integer|min:1',
            'purchase_amount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $loyaltySettings = LoyaltyProgramSetting::where('business_id', $request->business_id)
                ->where('is_active', true)
                ->first();

            if (!$loyaltySettings) {
                return errorResponse('Loyalty program not configured for this business', 404);
            }

            if (!$loyaltySettings->canRedeemPoints($request->points)) {
                return errorResponse('Points do not meet minimum redemption requirement of ' .
                    $loyaltySettings->minimum_redemption_points, 400);
            }

            $cashValue = $loyaltySettings->calculateCurrencyValue($request->points);

            // If purchase amount provided, check maximum redemption
            $maximumRedeemable = null;
            if ($request->purchase_amount) {
                $maximumRedeemable = $loyaltySettings->getMaximumRedeemableAmount(
                    $request->purchase_amount,
                    $request->points
                );
            }

            return successResponse('Redemption value calculated successfully', [
                'points' => $request->points,
                'cash_value' => $cashValue,
                'redemption_rate' => $loyaltySettings->currency_per_point,
                'minimum_redemption' => $loyaltySettings->minimum_redemption_points,
                'maximum_redeemable' => $maximumRedeemable,
                'can_redeem' => true,
            ]);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to calculate redemption value', $e->getMessage());
        }
    }

    /**
     * Expire old points
     */
    public function expirePoints(Request $request)
    {
        try {
            $businessId = $request->input('business_id');

            DB::beginTransaction();

            $expiredPointsQuery = CustomerPoint::query()
                ->where('transaction_type', 'earned')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now());

            if ($businessId) {
                $expiredPointsQuery->whereHas('customer', function ($query) use ($businessId) {
                    $query->where('business_id', $businessId);
                });
            }

            $expiredPoints = $expiredPointsQuery->get();
            $expiredCount = 0;

            foreach ($expiredPoints as $expiredPoint) {
                // Create expiration transaction
                CustomerPoint::create([
                    'customer_id' => $expiredPoint->customer_id,
                    'transaction_type' => 'expired',
                    'points' => $expiredPoint->points * -1, // Negative to deduct
                    'reference_type' => 'expiration',
                    'reference_id' => $expiredPoint->id,
                    'processed_by' => Auth::id(),
                    'branch_id' => $expiredPoint->branch_id,
                    'notes' => 'Points expired from transaction #' . $expiredPoint->id,
                ]);

                $expiredCount++;
            }

            DB::commit();

            return successResponse('Points expired successfully', [
                'expired_count' => $expiredCount,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to expire points', $e->getMessage());
        }
    }
}
