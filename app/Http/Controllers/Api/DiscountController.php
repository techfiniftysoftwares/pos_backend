<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DiscountController extends Controller
{
    /**
     * List all discounts with filtering
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $isActive = $request->input('is_active');
            $type = $request->input('type');
            $search = $request->input('search');

            $query = Discount::query();

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            if ($type) {
                $query->where('type', $type);
            }

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            }

            $discounts = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return successResponse('Discounts retrieved successfully', $discounts);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve discounts', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve discounts', $e->getMessage());
        }
    }

    /**
     * Create new discount
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|unique:discounts,code|max:50',
            'type' => 'required|in:percentage,fixed,bogo,quantity',
            'value' => 'required|numeric|min:0',
            'applies_to' => 'required|in:product,category,cart',
            'target_ids' => 'nullable|array',
            'minimum_amount' => 'nullable|numeric|min:0',
            'maximum_uses' => 'nullable|integer|min:1',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Validate percentage value
            if ($request->type === 'percentage' && $request->value > 100) {
                return errorResponse('Percentage discount cannot exceed 100%', 400);
            }

            // Validate target_ids if applies to product or category
            if (in_array($request->applies_to, ['product', 'category']) && empty($request->target_ids)) {
                return errorResponse('Target IDs are required when applying to products or categories', 400);
            }

            $discount = Discount::create([
                'business_id' => $request->business_id,
                'name' => $request->name,
                'code' => $request->code ? strtoupper($request->code) : null,
                'type' => $request->type,
                'value' => $request->value,
                'applies_to' => $request->applies_to,
                'target_ids' => $request->target_ids,
                'minimum_amount' => $request->minimum_amount,
                'maximum_uses' => $request->maximum_uses,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'is_active' => $request->input('is_active', true),
            ]);

            return successResponse('Discount created successfully', $discount, 201);
        } catch (\Exception $e) {
            Log::error('Failed to create discount', [
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to create discount', $e->getMessage());
        }
    }

    /**
     * Validate discount code at POS
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'business_id' => 'required|exists:businesses,id',
            'cart_total' => 'required|numeric|min:0',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
            'items.*.subtotal' => 'required_with:items|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $discount = Discount::where('code', strtoupper($request->code))
                ->where('business_id', $request->business_id)
                ->first();

            if (!$discount) {
                return errorResponse('Invalid discount code', 404);
            }

            // Check if discount is active
            if (!$discount->is_active) {
                return errorResponse('This discount is no longer active', 400);
            }

            // Check date validity
            $now = now();
            if ($discount->start_date && $now->lt($discount->start_date)) {
                return errorResponse('This discount is not yet valid', 400);
            }
            if ($discount->end_date && $now->gt($discount->end_date)) {
                return errorResponse('This discount has expired', 400);
            }

            // Check maximum uses
            if ($discount->maximum_uses && $discount->uses_count >= $discount->maximum_uses) {
                return errorResponse('This discount has reached its maximum usage limit', 400);
            }

            // Check minimum amount
            if ($discount->minimum_amount && $request->cart_total < $discount->minimum_amount) {
                return errorResponse("Minimum purchase amount of {$discount->minimum_amount} required", 400);
            }

            // Calculate discount amount
            $discountAmount = 0;

            if ($discount->applies_to === 'cart') {
                // Apply to entire cart
                if ($discount->type === 'percentage') {
                    $discountAmount = ($request->cart_total * $discount->value) / 100;
                } elseif ($discount->type === 'fixed') {
                    $discountAmount = min($discount->value, $request->cart_total);
                }
            } elseif ($discount->applies_to === 'product' && $request->items) {
                // Apply to specific products
                foreach ($request->items as $item) {
                    if (in_array($item['product_id'], $discount->target_ids ?? [])) {
                        if ($discount->type === 'percentage') {
                            $discountAmount += ($item['subtotal'] * $discount->value) / 100;
                        } elseif ($discount->type === 'fixed') {
                            $discountAmount += $discount->value * $item['quantity'];
                        }
                    }
                }
            } elseif ($discount->applies_to === 'category' && $request->items) {
                // Apply to products in specific categories
                foreach ($request->items as $item) {
                    $product = Product::find($item['product_id']);
                    if ($product && in_array($product->category_id, $discount->target_ids ?? [])) {
                        if ($discount->type === 'percentage') {
                            $discountAmount += ($item['subtotal'] * $discount->value) / 100;
                        } elseif ($discount->type === 'fixed') {
                            $discountAmount += $discount->value * $item['quantity'];
                        }
                    }
                }
            }

            return successResponse('Discount is valid', [
                'discount' => [
                    'id' => $discount->id,
                    'name' => $discount->name,
                    'code' => $discount->code,
                    'type' => $discount->type,
                    'value' => $discount->value,
                    'applies_to' => $discount->applies_to,
                ],
                'discount_amount' => round($discountAmount, 2),
                'final_total' => round($request->cart_total - $discountAmount, 2),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to validate discount', [
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to validate discount', $e->getMessage());
        }
    }

    /**
     * Display specific discount
     */
    public function show(Discount $discount)
    {
        try {
            return successResponse('Discount retrieved successfully', $discount);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve discount', [
                'discount_id' => $discount->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve discount', $e->getMessage());
        }
    }

    /**
     * Update discount
     */
    public function update(Request $request, Discount $discount)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|unique:discounts,code,' . $discount->id . '|max:50',
            'type' => 'sometimes|in:percentage,fixed,bogo,quantity',
            'value' => 'sometimes|numeric|min:0',
            'applies_to' => 'sometimes|in:product,category,cart',
            'target_ids' => 'nullable|array',
            'minimum_amount' => 'nullable|numeric|min:0',
            'maximum_uses' => 'nullable|integer|min:1',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Validate percentage value
            if ($request->has('type') && $request->type === 'percentage' && $request->value > 100) {
                return errorResponse('Percentage discount cannot exceed 100%', 400);
            }

            $updateData = $validator->validated();

            // Uppercase code if provided
            if (isset($updateData['code'])) {
                $updateData['code'] = strtoupper($updateData['code']);
            }

            $discount->update($updateData);

            return updatedResponse($discount, 'Discount updated successfully');
        } catch (\Exception $e) {
            Log::error('Failed to update discount', [
                'discount_id' => $discount->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to update discount', $e->getMessage());
        }
    }

    /**
     * Delete discount
     */
    public function destroy(Discount $discount)
    {
        try {
            $discount->delete();

            return deleteResponse('Discount deleted successfully');
        } catch (\Exception $e) {
            Log::error('Failed to delete discount', [
                'discount_id' => $discount->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete discount', $e->getMessage());
        }
    }
}
