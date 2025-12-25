<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class GiftCardController extends Controller
{
    /**
     * Display all gift cards
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $customerId = $request->input('customer_id');
            $status = $request->input('status');
            $search = $request->input('search');

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');

            // Whitelist of allowed sortable columns
            $allowedSortColumns = [
                'card_number',
                'initial_amount',
                'current_balance',
                'status',
                'issued_at',
                'expires_at',
                'created_at',
                'updated_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'created_at';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            $query = GiftCard::query()
                ->with(['business', 'customer', 'issuedBy', 'branch']);

            // Filter by business
            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            // Filter by customer
            if ($customerId) {
                $query->where('customer_id', $customerId);
            }

            // Filter by status
            if ($status) {
                $query->where('status', $status);
            }

            // Search by card number or customer name
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('card_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($cq) use ($search) {
                            $cq->where('name', 'like', "%{$search}%")
                                ->orWhere('customer_number', 'like', "%{$search}%");
                        });
                });
            }

            $giftCards = $query->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            return successResponse('Gift cards retrieved successfully', $giftCards);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve gift cards', $e->getMessage());
        }
    }

    /**
     * Issue new gift card
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'customer_id' => 'nullable|exists:customers,id',
            'initial_amount' => 'required|numeric|min:0.01',
            'pin' => 'nullable|string|min:4|max:6',
            'expires_at' => 'nullable|date|after:today',
            'branch_id' => 'required|exists:branches,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // Create gift card
            $giftCard = new GiftCard();
            $giftCard->business_id = $request->business_id;
            $giftCard->customer_id = $request->customer_id;
            $giftCard->initial_amount = $request->initial_amount;
            $giftCard->current_balance = $request->initial_amount;
            $giftCard->issued_by = Auth::id();
            $giftCard->issued_at = now();
            $giftCard->expires_at = $request->expires_at;
            $giftCard->branch_id = $request->branch_id;
            $giftCard->status = 'active';

            // Set PIN if provided
            if ($request->pin) {
                $giftCard->setPin($request->pin);
            }

            $giftCard->save();

            // Create issued transaction
            GiftCardTransaction::create([
                'gift_card_id' => $giftCard->id,
                'transaction_type' => 'issued',
                'amount' => $request->initial_amount,
                'previous_balance' => 0,
                'new_balance' => $request->initial_amount,
                'processed_by' => Auth::id(),
                'branch_id' => $request->branch_id,
                'notes' => 'Gift card issued',
            ]);

            DB::commit();

            $giftCard->load(['business', 'customer', 'issuedBy', 'branch']);

            return successResponse('Gift card issued successfully', $giftCard, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to issue gift card', $e->getMessage());
        }
    }

    /**
     * Display specific gift card
     */
    public function show(GiftCard $giftCard)
    {
        try {
            $giftCard->load([
                'business',
                'customer',
                'issuedBy',
                'branch',
                'transactions' => function ($query) {
                    $query->latest()->limit(20);
                }
            ]);

            $giftCardData = [
                'id' => $giftCard->id,
                'card_number' => $giftCard->card_number,
                'initial_amount' => $giftCard->initial_amount,
                'current_balance' => $giftCard->current_balance,
                'status' => $giftCard->status,
                'issued_at' => $giftCard->issued_at->format('Y-m-d H:i:s'),
                'expires_at' => $giftCard->expires_at?->format('Y-m-d H:i:s'),
                'is_expired' => $giftCard->isExpired(),
                'has_pin' => !empty($giftCard->pin),
                'business' => $giftCard->business->only(['id', 'name']),
                'customer' => $giftCard->customer ? $giftCard->customer->only(['id', 'customer_number', 'name', 'phone']) : null,
                'issued_by' => $giftCard->issuedBy->only(['id', 'name']),
                'branch' => $giftCard->branch->only(['id', 'name', 'code']),
                'transactions' => $giftCard->transactions->map(function ($transaction) {
                    return [
                        'id' => $transaction->id,
                        'type' => $transaction->transaction_type,
                        'amount' => $transaction->amount,
                        'balance' => $transaction->new_balance,
                        'reference' => $transaction->reference_number,
                        'date' => $transaction->created_at->format('Y-m-d H:i:s'),
                        'processed_by' => $transaction->processedBy->name,
                    ];
                }),
            ];

            return successResponse('Gift card retrieved successfully', $giftCardData);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve gift card', $e->getMessage());
        }
    }

    /**
     * Update gift card (limited fields)
     */
    public function update(Request $request, GiftCard $giftCard)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'sometimes|nullable|exists:customers,id',
            'status' => 'sometimes|in:active,inactive,expired,depleted',
            'expires_at' => 'sometimes|nullable|date',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $giftCard->update($validator->validated());

            return updatedResponse($giftCard, 'Gift card updated successfully');
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to update gift card', $e->getMessage());
        }
    }

    /**
     * Delete/deactivate gift card
     */
    public function destroy(GiftCard $giftCard)
    {
        try {
            if ($giftCard->current_balance > 0) {
                return errorResponse('Cannot delete gift card with remaining balance', 400);
            }

            $giftCard->update(['status' => 'inactive']);

            return successResponse('Gift card deactivated successfully', $giftCard);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to deactivate gift card', $e->getMessage());
        }
    }

    public function checkBalance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'card_number' => 'required|string|exists:gift_cards,card_number',
            'pin' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $giftCard = GiftCard::where('card_number', $request->card_number)->first();

            // Verify PIN if card has one
            if ($giftCard->pin && $request->pin) {
                if (!$giftCard->verifyPin($request->pin)) {
                    return errorResponse('Invalid PIN', 422); // Changed from 401 to 422
                }
            } elseif ($giftCard->pin && !$request->pin) {
                return errorResponse('PIN required', 422); // Changed from 401 to 422
            }

            return successResponse('Balance retrieved successfully', [
                'card_number' => $giftCard->card_number,
                'current_balance' => $giftCard->current_balance,
                'status' => $giftCard->status,
                'is_expired' => $giftCard->isExpired(),
                'expires_at' => $giftCard->expires_at?->format('Y-m-d'),
            ]);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to check balance', $e->getMessage());
        }
    }
    /**
     * Use gift card (deduct amount)
     */
    public function useCard(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'card_number' => 'required|string|exists:gift_cards,card_number',
            'amount' => 'required|numeric|min:0.01',
            'pin' => 'nullable|string',
            'reference_number' => 'nullable|string',
            'branch_id' => 'required|exists:branches,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $giftCard = GiftCard::where('card_number', $request->card_number)
                ->lockForUpdate()
                ->first();

            // Verify PIN if required
            if ($giftCard->pin && $request->pin) {
                if (!$giftCard->verifyPin($request->pin)) {
                    DB::rollBack();
                    return errorResponse('Invalid PIN', 422); // Changed from 401 to 422
                }
            } elseif ($giftCard->pin && !$request->pin) {
                DB::rollBack();
                return errorResponse('PIN required', 422); // Changed from 401 to 422
            }

            // Check if card is active and has balance
            if (!$giftCard->hasBalance($request->amount)) {
                DB::rollBack();
                return errorResponse('Insufficient balance or card inactive', 400);
            }

            // Check expiration
            if ($giftCard->isExpired()) {
                $giftCard->update(['status' => 'expired']);
                DB::rollBack();
                return errorResponse('Gift card has expired', 400);
            }

            // Create transaction
            GiftCardTransaction::create([
                'gift_card_id' => $giftCard->id,
                'transaction_type' => 'used',
                'amount' => $request->amount,
                'reference_number' => $request->reference_number,
                'processed_by' => Auth::id(),
                'branch_id' => $request->branch_id,
            ]);

            DB::commit();

            $giftCard->refresh();

            return successResponse('Gift card used successfully', [
                'card_number' => $giftCard->card_number,
                'amount_used' => $request->amount,
                'remaining_balance' => $giftCard->current_balance,
                'status' => $giftCard->status,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to use gift card', $e->getMessage());
        }
    }
    /**
     * Refund to gift card
     */
    public function refund(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'card_number' => 'required|string|exists:gift_cards,card_number',
            'amount' => 'required|numeric|min:0.01',
            'reference_number' => 'required|string',
            'branch_id' => 'required|exists:branches,id',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $giftCard = GiftCard::where('card_number', $request->card_number)
                ->lockForUpdate()
                ->first();

            // Reactivate if depleted
            if ($giftCard->status === 'depleted') {
                $giftCard->update(['status' => 'active']);
            }

            // Create refund transaction
            GiftCardTransaction::create([
                'gift_card_id' => $giftCard->id,
                'transaction_type' => 'refunded',
                'amount' => $request->amount,
                'reference_number' => $request->reference_number,
                'processed_by' => Auth::id(),
                'branch_id' => $request->branch_id,
                'notes' => $request->notes,
            ]);

            DB::commit();

            $giftCard->refresh();

            return successResponse('Gift card refunded successfully', [
                'card_number' => $giftCard->card_number,
                'amount_refunded' => $request->amount,
                'new_balance' => $giftCard->current_balance,
                'status' => $giftCard->status,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to refund gift card', $e->getMessage());
        }
    }

    /**
     * Get gift card transactions
     */
    public function transactions(GiftCard $giftCard)
    {
        try {
            $transactions = $giftCard->transactions()
                ->with(['processedBy', 'branch'])
                ->orderBy('created_at', 'desc')
                ->get();

            return successResponse('Gift card transactions retrieved successfully', $transactions);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve transactions', $e->getMessage());
        }
    }
}
