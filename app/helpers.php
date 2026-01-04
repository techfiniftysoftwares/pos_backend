<?php
// app/helpers.php (create this file and add to composer.json autoload)

if (!function_exists('successResponse')) {
    function successResponse($message, $data = null, $statusCode = 200)
    {
        return response()->json([
            'success' => true,
            'status' => $statusCode,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }
}

if (!function_exists('queryErrorResponse')) {
    function queryErrorResponse($message = 'Failed to execute query', $error = null, $statusCode = 500)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'error' => $error,
        ], $statusCode);
    }
}

if (!function_exists('serverErrorResponse')) {
    function serverErrorResponse($message = 'An unexpected server error occurred', $error = null, $statusCode = 500)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'error' => $error,
        ], $statusCode);
    }
}

if (!function_exists('validationErrorResponse')) {
    function validationErrorResponse($errors, $message = 'Validation failed', $statusCode = 422)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);
    }
}

if (!function_exists('createdResponse')) {
    function createdResponse($data = null, string $message = 'Resource created successfully', int $code = 201)
    {
        return response()->json([
            'success' => true,
            'status' => $code,
            'message' => $message,
            'data' => $data
        ], $code);
    }
}

if (!function_exists('errorResponse')) {
    function errorResponse($message, $statusCode = 500, $error = null)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'error' => $error,
        ], $statusCode);
    }
}

if (!function_exists('updatedResponse')) {
    function updatedResponse($data, string $message = 'Resource updated successfully', int $code = 200)
    {
        return response()->json([
            'success' => true,
            'status' => $code,
            'message' => $message,
            'data' => $data
        ], $code);
    }
}

if (!function_exists('deleteResponse')) {
    function deleteResponse(string $message = 'Resource deleted successfully', int $code = 200)
    {
        return response()->json([
            'success' => true,
            'status' => $code,
            'message' => $message,
        ], $code);
    }
}

if (!function_exists('notFoundResponse')) {
    function notFoundResponse($message = 'Resource not found', $statusCode = 404)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'data' => []
        ], $statusCode);
    }
}

if (!function_exists('forbiddenResponse')) {
    function forbiddenResponse($message = 'Access forbidden', $statusCode = 403)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
        ], $statusCode);
    }
}

if (!function_exists('paginatedResponse')) {
    function paginatedResponse($data, $message = 'Data retrieved successfully', $statusCode = 200)
    {
        return response()->json([
            'success' => true,
            'status' => $statusCode,
            'message' => $message,
            'data' => $data->items(),
            'pagination' => [
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
                'from' => $data->firstItem(),
                'to' => $data->lastItem(),
                'has_more_pages' => $data->hasMorePages(),
            ]
        ], $statusCode);
    }
}

// =====================================================
// CURRENCY HELPERS
// =====================================================

if (!function_exists('getBaseCurrency')) {
    /**
     * Get the base (primary) currency for the current business
     * 
     * @return \App\Models\Currency|null
     */
    function getBaseCurrency()
    {
        return \App\Models\Currency::where('is_base', true)
            ->where('is_active', true)
            ->first();
    }
}

if (!function_exists('getCurrencyByCode')) {
    /**
     * Get a currency by its code
     * 
     * @param string $code The currency code (e.g., 'USD', 'EUR', 'KES')
     * @return \App\Models\Currency|null
     */
    function getCurrencyByCode(string $code)
    {
        return \App\Models\Currency::where('code', strtoupper($code))
            ->where('is_active', true)
            ->first();
    }
}

if (!function_exists('getCurrencyById')) {
    /**
     * Get a currency by its ID
     * 
     * @param int $id The currency ID
     * @return \App\Models\Currency|null
     */
    function getCurrencyById(int $id)
    {
        return \App\Models\Currency::where('id', $id)
            ->where('is_active', true)
            ->first();
    }
}

if (!function_exists('formatMoney')) {
    /**
     * Format an amount with currency symbol
     * 
     * @param float|int $amount The amount to format
     * @param \App\Models\Currency|string|int|null $currency Currency object, code, ID, or null for base currency
     * @param int $decimals Number of decimal places (default: 2)
     * @param bool $includeCode Whether to include currency code (default: false)
     * @return string Formatted money string (e.g., "$1,234.56" or "KES 1,234.56")
     */
    function formatMoney($amount, $currency = null, int $decimals = 2, bool $includeCode = false): string
    {
        // Get currency object
        if ($currency === null) {
            $currencyObj = getBaseCurrency();
        } elseif ($currency instanceof \App\Models\Currency) {
            $currencyObj = $currency;
        } elseif (is_string($currency)) {
            $currencyObj = getCurrencyByCode($currency);
        } elseif (is_int($currency)) {
            $currencyObj = getCurrencyById($currency);
        } else {
            $currencyObj = getBaseCurrency();
        }

        // Get symbol and code
        $symbol = $currencyObj ? $currencyObj->symbol : '$';
        $code = $currencyObj ? $currencyObj->code : 'USD';

        // Format the number
        $formattedAmount = number_format((float) $amount, $decimals);

        // Build the formatted string
        if ($includeCode) {
            return $code . ' ' . $formattedAmount;
        }

        return $symbol . ' ' . $formattedAmount;
    }
}

if (!function_exists('convertCurrency')) {
    /**
     * Convert amount from one currency to another using exchange rates
     * 
     * @param float|int $amount Amount to convert
     * @param int $fromCurrencyId Source currency ID
     * @param int|null $toCurrencyId Target currency ID (null = base currency)
     * @return float Converted amount
     */
    function convertCurrency($amount, int $fromCurrencyId, ?int $toCurrencyId = null): float
    {
        // If no target specified, use base currency
        if ($toCurrencyId === null) {
            $baseCurrency = getBaseCurrency();
            $toCurrencyId = $baseCurrency ? $baseCurrency->id : $fromCurrencyId;
        }

        // Same currency, no conversion needed
        if ($fromCurrencyId === $toCurrencyId) {
            return (float) $amount;
        }

        // Get the exchange rate
        $rate = \App\Models\ExchangeRate::where('from_currency_id', $fromCurrencyId)
            ->where('to_currency_id', $toCurrencyId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$rate) {
            // Try reverse rate
            $reverseRate = \App\Models\ExchangeRate::where('from_currency_id', $toCurrencyId)
                ->where('to_currency_id', $fromCurrencyId)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($reverseRate && $reverseRate->rate > 0) {
                return (float) $amount / $reverseRate->rate;
            }

            // No rate found, return original amount
            return (float) $amount;
        }

        return (float) $amount * $rate->rate;
    }
}

if (!function_exists('getReportCurrency')) {
    /**
     * Get currency info for PDF reports
     * Returns an associative array with symbol, code, and currency object
     * 
     * @param \Illuminate\Http\Request $request The request object
     * @param \App\Models\User|null $user The authenticated user (optional)
     * @return array ['symbol' => string, 'code' => string, 'currency' => Currency|null]
     */
    function getReportCurrency($request, $user = null): array
    {
        $user = $user ?? $request->user();

        // Priority: request param > business default > USD
        $currencyCode = $request->currency_code
            ?? ($user->business->default_currency ?? null)
            ?? 'USD';

        $currency = getCurrencyByCode($currencyCode);

        return [
            'symbol' => $currency ? $currency->symbol : '$',
            'code' => $currency ? $currency->code : 'USD',
            'currency' => $currency,
        ];
    }
}

