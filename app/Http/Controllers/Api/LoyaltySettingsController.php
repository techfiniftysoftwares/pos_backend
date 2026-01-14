<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyProgramSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LoyaltySettingsController extends Controller
{
    /**
     * Get loyalty settings for the current business
     */
    public function show(Request $request)
    {
        try {
            $businessId = $request->input('business_id');

            if (!$businessId) {
                return errorResponse('Business ID is required', 400);
            }

            $settings = LoyaltyProgramSetting::where('business_id', $businessId)->first();

            if (!$settings) {
                return successResponse('No loyalty settings configured', null);
            }

            return successResponse('Loyalty settings retrieved successfully', $settings);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve loyalty settings', $e->getMessage());
        }
    }

    /**
     * Create loyalty settings for a business
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id|unique:loyalty_program_settings,business_id',
            'points_per_currency' => 'required|numeric|min:0.01',
            'currency_per_point' => 'required|numeric|min:0.0001',
            'minimum_redemption_points' => 'required|integer|min:1',
            'point_expiry_months' => 'nullable|integer|min:1',
            'is_active' => 'sometimes|boolean',
            'bonus_multiplier_days' => 'nullable|array',
            'bonus_multiplier_days.*' => 'numeric|min:1|max:10',
            'allow_partial_redemption' => 'sometimes|boolean',
            'maximum_redemption_percentage' => 'sometimes|numeric|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $settings = LoyaltyProgramSetting::create($validator->validated());

            return successResponse('Loyalty settings created successfully', $settings, 201);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to create loyalty settings', $e->getMessage());
        }
    }

    /**
     * Update loyalty settings
     */
    public function update(Request $request, LoyaltyProgramSetting $loyaltyProgramSetting)
    {
        $validator = Validator::make($request->all(), [
            'points_per_currency' => 'sometimes|numeric|min:0.01',
            'currency_per_point' => 'sometimes|numeric|min:0.0001',
            'minimum_redemption_points' => 'sometimes|integer|min:1',
            'point_expiry_months' => 'nullable|integer|min:1',
            'is_active' => 'sometimes|boolean',
            'bonus_multiplier_days' => 'nullable|array',
            'bonus_multiplier_days.*' => 'numeric|min:1|max:10',
            'allow_partial_redemption' => 'sometimes|boolean',
            'maximum_redemption_percentage' => 'sometimes|numeric|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $loyaltyProgramSetting->update($validator->validated());

            return updatedResponse($loyaltyProgramSetting, 'Loyalty settings updated successfully');
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to update loyalty settings', $e->getMessage());
        }
    }
}
