<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRateSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ExchangeRateSourceController extends Controller
{
    /**
     * Display all exchange rate sources
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $isActive = $request->input('is_active');

            $query = ExchangeRateSource::query();

            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            $sources = $query->orderBy('name', 'asc')
                ->paginate($perPage);

            return successResponse('Exchange rate sources retrieved successfully', $sources);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve exchange rate sources', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve exchange rate sources', $e->getMessage());
        }
    }

    /**
     * Create new exchange rate source
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:exchange_rate_sources,code',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $source = ExchangeRateSource::create([
                'name' => $request->name,
                'code' => strtolower(str_replace(' ', '_', $request->code)),
                'description' => $request->description,
                'is_active' => $request->input('is_active', true),
            ]);

            return successResponse('Exchange rate source created successfully', $source, 201);
        } catch (\Exception $e) {
            Log::error('Failed to create exchange rate source', [
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to create exchange rate source', $e->getMessage());
        }
    }

    /**
     * Display specific exchange rate source
     */
    public function show(ExchangeRateSource $exchangeRateSource)
    {
        try {
            return successResponse('Exchange rate source retrieved successfully', $exchangeRateSource);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve exchange rate source', [
                'source_id' => $exchangeRateSource->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve exchange rate source', $e->getMessage());
        }
    }

    /**
     * Update exchange rate source
     */
    public function update(Request $request, ExchangeRateSource $exchangeRateSource)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|unique:exchange_rate_sources,code,' . $exchangeRateSource->id,
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $updateData = $validator->validated();
            if (isset($updateData['code'])) {
                $updateData['code'] = strtolower(str_replace(' ', '_', $updateData['code']));
            }

            $exchangeRateSource->update($updateData);

            return updatedResponse($exchangeRateSource, 'Exchange rate source updated successfully');
        } catch (\Exception $e) {
            Log::error('Failed to update exchange rate source', [
                'source_id' => $exchangeRateSource->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to update exchange rate source', $e->getMessage());
        }
    }

    /**
     * Delete exchange rate source
     */
    public function destroy(ExchangeRateSource $exchangeRateSource)
    {
        try {
            // Check if source has exchange rates
            if ($exchangeRateSource->exchangeRates()->exists()) {
                return errorResponse('Cannot delete source with existing exchange rates', 400);
            }

            $exchangeRateSource->delete();

            return deleteResponse('Exchange rate source deleted successfully');
        } catch (\Exception $e) {
            Log::error('Failed to delete exchange rate source', [
                'source_id' => $exchangeRateSource->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete exchange rate source', $e->getMessage());
        }
    }
}
