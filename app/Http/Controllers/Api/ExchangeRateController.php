<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\Currency;
use App\Models\Sale;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ExchangeRateController extends Controller
{
    /**
     * Display all exchange rates with filtering
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $fromCurrencyId = $request->input('from_currency_id');
            $toCurrencyId = $request->input('to_currency_id');
            $sourceId = $request->input('source_id');
            $isActive = $request->input('is_active');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');

            $query = ExchangeRate::query()
                ->with(['fromCurrency', 'toCurrency', 'source', 'createdBy']);

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if ($fromCurrencyId) {
                $query->where('from_currency_id', $fromCurrencyId);
            }

            if ($toCurrencyId) {
                $query->where('to_currency_id', $toCurrencyId);
            }

            if ($sourceId) {
                $query->where('source_id', $sourceId);
            }

            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            if ($dateFrom) {
                $query->whereDate('effective_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('effective_date', '<=', $dateTo);
            }

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'effective_date');
            $sortDirection = $request->input('sort_direction', 'desc');

            $allowedSortColumns = [
                'rate',
                'effective_date',
                'is_active',
                'created_at',
                'from_currency',
                'to_currency'
            ];

            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'effective_date';
            }

            $sortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

            // Join tables if sorting by currency names
            if ($sortBy === 'from_currency') {
                $query->join('currencies as fc', 'exchange_rates.from_currency_id', '=', 'fc.id')
                    ->select('exchange_rates.*')
                    ->orderBy('fc.code', $sortDirection);
            } elseif ($sortBy === 'to_currency') {
                $query->join('currencies as tc', 'exchange_rates.to_currency_id', '=', 'tc.id')
                    ->select('exchange_rates.*')
                    ->orderBy('tc.code', $sortDirection);
            } else {
                $query->orderBy($sortBy, $sortDirection);
            }

            $exchangeRates = $query->paginate($perPage);

            return successResponse('Exchange rates retrieved successfully', $exchangeRates);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve exchange rates', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve exchange rates', $e->getMessage());
        }
    }

    /**
     * Create new exchange rate
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'from_currency_id' => 'required|exists:currencies,id',
            'to_currency_id' => 'required|exists:currencies,id|different:from_currency_id',
            'source_id' => 'nullable|exists:exchange_rate_sources,id',
            'rate' => 'required|numeric|min:0.000001',
            'effective_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // Deactivate any existing active rate for same currency pair on same or later dates
            ExchangeRate::where('business_id', $request->business_id)
                ->where('from_currency_id', $request->from_currency_id)
                ->where('to_currency_id', $request->to_currency_id)
                ->where('effective_date', '>=', $request->effective_date)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            // Create new exchange rate
            $exchangeRate = ExchangeRate::create([
                'business_id' => $request->business_id,
                'from_currency_id' => $request->from_currency_id,
                'to_currency_id' => $request->to_currency_id,
                'source_id' => $request->source_id,
                'rate' => $request->rate,
                'effective_date' => $request->effective_date,
                'is_active' => true,
                'notes' => $request->notes,
                'created_by' => Auth::id(),
            ]);

            DB::commit();

            $exchangeRate->load(['fromCurrency', 'toCurrency', 'source', 'createdBy']);

            return successResponse('Exchange rate created successfully', $exchangeRate, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create exchange rate', [
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to create exchange rate', $e->getMessage());
        }
    }

    /**
     * Display specific exchange rate
     */
    public function show(ExchangeRate $exchangeRate)
    {
        try {
            $exchangeRate->load(['fromCurrency', 'toCurrency', 'source', 'business', 'createdBy']);

            return successResponse('Exchange rate retrieved successfully', $exchangeRate);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve exchange rate', [
                'rate_id' => $exchangeRate->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve exchange rate', $e->getMessage());
        }
    }

    /**
     * Update exchange rate (creates new rate, deactivates old)
     */
    public function update(Request $request, ExchangeRate $exchangeRate)
    {
        $validator = Validator::make($request->all(), [
            'rate' => 'sometimes|numeric|min:0.000001',
            'effective_date' => 'sometimes|date',
            'source_id' => 'nullable|exists:exchange_rate_sources,id',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // Deactivate old rate
            $exchangeRate->update(['is_active' => false]);

            // Create new rate with updated values
            $newRate = ExchangeRate::create([
                'business_id' => $exchangeRate->business_id,
                'from_currency_id' => $exchangeRate->from_currency_id,
                'to_currency_id' => $exchangeRate->to_currency_id,
                'source_id' => $request->input('source_id', $exchangeRate->source_id),
                'rate' => $request->input('rate', $exchangeRate->rate),
                'effective_date' => $request->input('effective_date', $exchangeRate->effective_date),
                'is_active' => true,
                'notes' => $request->input('notes', $exchangeRate->notes),
                'created_by' => Auth::id(),
            ]);

            DB::commit();

            $newRate->load(['fromCurrency', 'toCurrency', 'source', 'createdBy']);

            return successResponse('Exchange rate updated successfully', $newRate);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update exchange rate', [
                'rate_id' => $exchangeRate->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to update exchange rate', $e->getMessage());
        }
    }

    /**
     * Delete exchange rate (deactivate)
     */
    public function destroy(ExchangeRate $exchangeRate)
    {
        try {
            $exchangeRate->update(['is_active' => false]);

            return successResponse('Exchange rate deactivated successfully', $exchangeRate);
        } catch (\Exception $e) {
            Log::error('Failed to deactivate exchange rate', [
                'rate_id' => $exchangeRate->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to deactivate exchange rate', $e->getMessage());
        }
    }

    /**
     * Get current active rates for all currency pairs
     */
    public function getCurrentRates(Request $request)
    {
        try {
            $businessId = $request->input('business_id');
            $fromCurrencyId = $request->input('from_currency_id');
            $toCurrencyId = $request->input('to_currency_id');

            $query = ExchangeRate::query()
                ->with(['fromCurrency', 'toCurrency', 'source'])
                ->where('is_active', true)
                ->where('effective_date', '<=', now());

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if ($fromCurrencyId && $toCurrencyId) {
                $query->where('from_currency_id', $fromCurrencyId)
                    ->where('to_currency_id', $toCurrencyId);
            }

            $rates = $query->orderBy('effective_date', 'desc')
                ->get()
                ->unique(function ($rate) {
                    return $rate->from_currency_id . '-' . $rate->to_currency_id;
                })
                ->values();

            return successResponse('Current exchange rates retrieved successfully', $rates);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve current rates', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve current rates', $e->getMessage());
        }
    }

    /**
     * Get exchange rate history for currency pair
     */
    public function rateHistory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'from_currency_id' => 'required|exists:currencies,id',
            'to_currency_id' => 'required|exists:currencies,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $query = ExchangeRate::query()
                ->with(['fromCurrency', 'toCurrency', 'source'])
                ->where('business_id', $request->business_id)
                ->where('from_currency_id', $request->from_currency_id)
                ->where('to_currency_id', $request->to_currency_id);

            if ($request->date_from) {
                $query->whereDate('effective_date', '>=', $request->date_from);
            }
            if ($request->date_to) {
                $query->whereDate('effective_date', '<=', $request->date_to);
            }

            $history = $query->orderBy('effective_date', 'desc')->get();

            return successResponse('Rate history retrieved successfully', $history);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve rate history', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve rate history', $e->getMessage());
        }
    }

    /**
     * Convert amount between currencies
     */
    public function convertAmount(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'from_currency_id' => 'required|exists:currencies,id',
            'to_currency_id' => 'required|exists:currencies,id',
            'amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $rate = ExchangeRate::getCurrentRate(
                $request->from_currency_id,
                $request->to_currency_id,
                $request->business_id
            );

            if (!$rate) {
                return notFoundResponse('No exchange rate found for this currency pair');
            }

            $convertedAmount = $request->amount * $rate->rate;

            return successResponse('Amount converted successfully', [
                'original_amount' => $request->amount,
                'converted_amount' => round($convertedAmount, 2),
                'exchange_rate' => $rate->rate,
                'from_currency' => $rate->fromCurrency,
                'to_currency' => $rate->toCurrency,
                'effective_date' => $rate->effective_date,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to convert amount', [
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to convert amount', $e->getMessage());
        }
    }

    /**
     * Get sales breakdown by currency
     */
    public function salesByCurrency(Request $request)
    {
        try {
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');

            $query = Sale::query();

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            if ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            $salesByCurrency = $query->select(
                'currency',
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw('SUM(total_amount) as total_sales'),
                DB::raw('AVG(total_amount) as average_sale'),
                DB::raw('SUM(total_in_base_currency) as total_in_base_currency')
            )
                ->groupBy('currency')
                ->get();

            return successResponse('Sales by currency retrieved successfully', $salesByCurrency);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve sales by currency', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve sales by currency', $e->getMessage());
        }
    }

    /**
     * Get daily currency summary
     */
    public function currencySummary(Request $request)
    {
        try {
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $date = $request->input('date', now()->toDateString());

            $query = Payment::query()
                ->where('status', 'completed');

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            $query->whereDate('payment_date', $date);

            $currencySummary = $query->select(
                'currency',
                DB::raw('COUNT(*) as payment_count'),
                DB::raw('SUM(amount) as total_collected'),
                DB::raw('SUM(amount_in_base_currency) as total_in_base_currency')
            )
                ->groupBy('currency')
                ->get();

            return successResponse('Currency summary retrieved successfully', [
                'date' => $date,
                'summary' => $currencySummary,
                'grand_total_base_currency' => $currencySummary->sum('total_in_base_currency'),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve currency summary', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve currency summary', $e->getMessage());
        }
    }
}
