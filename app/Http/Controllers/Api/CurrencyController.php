<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CurrencyController extends Controller
{
    /**
     * Display all currencies
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $isActive = $request->input('is_active');

            $query = Currency::query();

            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            $currencies = $query->orderBy('name', 'asc')
                ->paginate($perPage);

            return successResponse('Currencies retrieved successfully', $currencies);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve currencies', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve currencies', $e->getMessage());
        }
    }

    /**
     * Create new currency
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:3|unique:currencies,code',
            'name' => 'required|string|max:255',
            'symbol' => 'required|string|max:10',
            'is_base' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // If setting as base currency, unset any existing base
            if ($request->input('is_base', false)) {
                Currency::where('is_base', true)->update(['is_base' => false]);
            }

            $currency = Currency::create([
                'code' => strtoupper($request->code),
                'name' => $request->name,
                'symbol' => $request->symbol,
                'is_base' => $request->input('is_base', false),
                'is_active' => $request->input('is_active', true),
            ]);

            DB::commit();

            return successResponse('Currency created successfully', $currency, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create currency', [
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to create currency', $e->getMessage());
        }
    }

    /**
     * Display specific currency
     */
    public function show(Currency $currency)
    {
        try {
            return successResponse('Currency retrieved successfully', $currency);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve currency', [
                'currency_id' => $currency->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve currency', $e->getMessage());
        }
    }

    /**
     * Update currency
     */
    public function update(Request $request, Currency $currency)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|string|size:3|unique:currencies,code,' . $currency->id,
            'name' => 'sometimes|string|max:255',
            'symbol' => 'sometimes|string|max:10',
            'is_base' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // If setting as base currency, unset any existing base
            if ($request->has('is_base') && $request->is_base) {
                Currency::where('id', '!=', $currency->id)
                    ->where('is_base', true)
                    ->update(['is_base' => false]);
            }

            $updateData = $validator->validated();
            if (isset($updateData['code'])) {
                $updateData['code'] = strtoupper($updateData['code']);
            }

            $currency->update($updateData);

            DB::commit();

            return updatedResponse($currency, 'Currency updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update currency', [
                'currency_id' => $currency->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to update currency', $e->getMessage());
        }
    }

    /**
     * Delete currency
     */
    public function destroy(Currency $currency)
    {
        try {
            // Check if currency is being used
            if ($currency->is_base) {
                return errorResponse('Cannot delete base currency', 400);
            }

            // Check if currency has exchange rates
            if ($currency->exchangeRatesFrom()->exists() || $currency->exchangeRatesTo()->exists()) {
                return errorResponse('Cannot delete currency with existing exchange rates', 400);
            }

            $currency->delete();

            return deleteResponse('Currency deleted successfully');
        } catch (\Exception $e) {
            Log::error('Failed to delete currency', [
                'currency_id' => $currency->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete currency', $e->getMessage());
        }
    }

    /**
     * Toggle currency status
     */
    public function toggleStatus(Currency $currency)
    {
        try {
            if ($currency->is_base) {
                return errorResponse('Cannot deactivate base currency', 400);
            }

            $newStatus = !$currency->is_active;
            $currency->update(['is_active' => $newStatus]);

            $statusText = $newStatus ? 'activated' : 'deactivated';
            return successResponse("Currency {$statusText} successfully", $currency);
        } catch (\Exception $e) {
            Log::error('Failed to toggle currency status', [
                'currency_id' => $currency->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to toggle currency status', $e->getMessage());
        }
    }

    /**
     * Get base currency
     */
    public function getBaseCurrency()
    {
        try {
            $baseCurrency = Currency::getBaseCurrency();

            if (!$baseCurrency) {
                return notFoundResponse('No base currency set');
            }

            return successResponse('Base currency retrieved successfully', $baseCurrency);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve base currency', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve base currency', $e->getMessage());
        }
    }
}
