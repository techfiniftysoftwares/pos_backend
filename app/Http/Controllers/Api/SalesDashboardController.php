<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SalesDashboardController extends Controller
{
    /**
     * TAB 1: SALES OVERVIEW
     */
public function getSalesSummary(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());
        $status = $request->input('status');

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // Default: exclude cancelled sales
        $query = Sale::whereIn('status', ['completed', 'pending']);

        // If specific status requested, filter by it
        if ($status) {
            $query = Sale::where('status', $status);
        }

        // ALWAYS filter by user's business
        $query->where('business_id', $businessId);

        // Filter by branch (user's primary branch by default)
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        // Use completed_at for date filtering
        $query->whereDate('completed_at', '>=', $dateFrom)
              ->whereDate('completed_at', '<=', $dateTo);

        $totalSales = $query->sum('total_amount');
        $totalTransactions = $query->count();
        $averageOrderValue = $totalTransactions > 0 ? $totalSales / $totalTransactions : 0;

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->whereIn('status', ['completed', 'pending'])
            ->where('business_id', $businessId)
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        // Add status breakdown
        $statusBreakdown = Sale::select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as total'))
            ->where('business_id', $businessId)
            ->when($branchId, function($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            })
            ->whereDate('completed_at', '>=', $dateFrom)
            ->whereDate('completed_at', '<=', $dateTo)
            ->groupBy('status')
            ->get();

        $data = [
            'total_sales' => (float) $totalSales,
            'total_transactions' => $totalTransactions,
            'average_order_value' => round($averageOrderValue, 2),
            'currency' => $currency,
            'status_breakdown' => $statusBreakdown->map(function($item) {
                return [
                    'status' => $item->status,
                    'count' => $item->count,
                    'total' => (float) $item->total
                ];
            }),
            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name
            ]
        ];

        return successResponse('Sales summary retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get sales summary', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get sales summary', $e->getMessage());
    }
}
public function getSalesComparison(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // Calculate previous period dates
        $currentStart = Carbon::parse($dateFrom);
        $currentEnd = Carbon::parse($dateTo);
        $daysDiff = $currentStart->diffInDays($currentEnd) + 1;

        $previousStart = $currentStart->copy()->subDays($daysDiff);
        $previousEnd = $currentEnd->copy()->subDays($daysDiff);

        // Current period sales
        $currentQuery = Sale::whereIn('status', ['completed', 'pending'])
            ->where('business_id', $businessId);

        if ($branchId) {
            $currentQuery->where('branch_id', $branchId);
        }

        $currentQuery->whereDate('completed_at', '>=', $dateFrom)
                     ->whereDate('completed_at', '<=', $dateTo);

        $currentSales = $currentQuery->sum('total_amount');
        $currentTransactions = $currentQuery->count();

        // Previous period sales
        $previousQuery = Sale::whereIn('status', ['completed', 'pending'])
            ->where('business_id', $businessId);

        if ($branchId) {
            $previousQuery->where('branch_id', $branchId);
        }

        $previousQuery->whereDate('completed_at', '>=', $previousStart->toDateString())
                      ->whereDate('completed_at', '<=', $previousEnd->toDateString());

        $previousSales = $previousQuery->sum('total_amount');
        $previousTransactions = $previousQuery->count();

        // Calculate growth
        $growthAmount = $currentSales - $previousSales;
        $growthPercentage = $previousSales > 0
            ? (($currentSales - $previousSales) / $previousSales) * 100
            : ($currentSales > 0 ? 100 : 0);

        $trend = $growthAmount >= 0 ? 'up' : 'down';

        $data = [
            'current_period' => [
                'total_sales' => (float) $currentSales,
                'transaction_count' => $currentTransactions,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ],
            'previous_period' => [
                'total_sales' => (float) $previousSales,
                'transaction_count' => $previousTransactions,
                'date_from' => $previousStart->toDateString(),
                'date_to' => $previousEnd->toDateString()
            ],
            'growth_amount' => (float) $growthAmount,
            'growth_percentage' => round($growthPercentage, 2),
            'trend' => $trend,
            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId
            ]
        ];

        return successResponse('Sales comparison retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get sales comparison', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get sales comparison', $e->getMessage());
    }
}
public function getTopSellingProducts(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());
        $limit = $request->input('limit', 5);

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        $query = SaleItem::select(
            'sale_items.product_id',
            'products.name as product_name',
            'products.sku',
            DB::raw('SUM(sale_items.quantity) as quantity_sold'),
            DB::raw('SUM(sale_items.line_total) as total_revenue'),
            DB::raw('COUNT(DISTINCT sales.id) as order_count')
        )
        ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
        ->join('products', 'sale_items.product_id', '=', 'products.id')
        ->whereIn('sales.status', ['completed', 'pending'])
        ->where('sales.business_id', $businessId);

        if ($branchId) {
            $query->where('sales.branch_id', $branchId);
        }

        $query->whereDate('sales.completed_at', '>=', $dateFrom)
              ->whereDate('sales.completed_at', '<=', $dateTo)
              ->groupBy('sale_items.product_id', 'products.name', 'products.sku')
              ->orderByDesc('total_revenue')
              ->limit($limit);

        $products = $query->get()->map(function ($item) {
            return [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'quantity_sold' => (float) $item->quantity_sold,
                'total_revenue' => (float) $item->total_revenue,
                'order_count' => $item->order_count,
                'average_per_order' => $item->order_count > 0
                    ? round($item->quantity_sold / $item->order_count, 2)
                    : 0
            ];
        });

        $data = [
            'products' => $products,
            'total_products' => $products->count(),
            'total_revenue' => (float) $products->sum('total_revenue'),
            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId
            ]
        ];

        return successResponse('Top selling products retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get top selling products', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get top selling products', $e->getMessage());
    }
}

public function getSalesByPaymentMethod(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 CRITICAL FIX: Use amount_in_sale_currency instead of amount
        $query = DB::table('sales')
            ->join('sale_payments', 'sales.id', '=', 'sale_payments.sale_id')
            ->join('payments', 'sale_payments.payment_id', '=', 'payments.id')
            ->join('payment_methods', 'payments.payment_method_id', '=', 'payment_methods.id')
            ->select(
                'payment_methods.name as payment_method',
                'payment_methods.type as payment_type',
                DB::raw('COUNT(DISTINCT sales.id) as transaction_count'),
                DB::raw('SUM(sale_payments.amount_in_sale_currency) as total_amount'), // 🆕 FIXED
                DB::raw('SUM(payments.fee_amount) as total_fees')
            )
            ->whereIn('sales.status', ['completed', 'pending'])
            ->where('payments.status', 'completed')
            ->where('sales.business_id', $businessId);

        if ($branchId) {
            $query->where('sales.branch_id', $branchId);
        }

        $query->whereDate('sales.completed_at', '>=', $dateFrom)
              ->whereDate('sales.completed_at', '<=', $dateTo)
              ->groupBy('payment_methods.name', 'payment_methods.type')
              ->orderByDesc('total_amount');

        $paymentMethods = $query->get();

        $totalAmount = $paymentMethods->sum(function($item) {
            return $item->total_amount;
        });

        $data = [
            'payment_methods' => collect($paymentMethods)->map(function ($item) use ($totalAmount) {
                return [
                    'payment_method' => $item->payment_method,
                    'payment_type' => $item->payment_type,
                    'transaction_count' => $item->transaction_count,
                    'total_amount' => (float) $item->total_amount,
                    'total_fees' => (float) $item->total_fees,
                    'net_amount' => (float) ($item->total_amount - $item->total_fees),
                    'percentage' => $totalAmount > 0
                        ? round(($item->total_amount / $totalAmount) * 100, 2)
                        : 0
                ];
            }),
            'summary' => [
                'total_amount' => (float) $totalAmount,
                'total_fees' => (float) $paymentMethods->sum('total_fees'),
                'net_amount' => (float) ($totalAmount - $paymentMethods->sum('total_fees'))
            ],
            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId
            ]
        ];

        return successResponse('Sales by payment method retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get sales by payment method', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get sales by payment method', $e->getMessage());
    }
}
public function getSalesGrowthTrend(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $days = $request->input('days', 30);

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        $endDate = Carbon::today();
        $startDate = Carbon::today()->subDays($days - 1);

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        $query = Sale::select(
            DB::raw('DATE(completed_at) as date'),
            DB::raw('COUNT(*) as transaction_count'),
            DB::raw('SUM(total_amount) as total_sales'),
            DB::raw('AVG(total_amount) as average_order_value')
        )
        ->whereIn('status', ['completed', 'pending'])
        ->where('business_id', $businessId)
        ->whereDate('completed_at', '>=', $startDate->toDateString())
        ->whereDate('completed_at', '<=', $endDate->toDateString());

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $salesData = $query->groupBy(DB::raw('DATE(completed_at)'))
            ->orderBy('date', 'asc')
            ->get();

        // Fill in missing dates
        $allDates = [];
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dateString = $currentDate->toDateString();
            $existingData = $salesData->firstWhere('date', $dateString);

            $allDates[] = [
                'date' => $dateString,
                'day_name' => $currentDate->format('D'),
                'transaction_count' => $existingData ? $existingData->transaction_count : 0,
                'total_sales' => $existingData ? (float) $existingData->total_sales : 0,
                'average_order_value' => $existingData ? round($existingData->average_order_value, 2) : 0
            ];

            $currentDate->addDay();
        }

        $totalSales = collect($allDates)->sum('total_sales');
        $totalTransactions = collect($allDates)->sum('transaction_count');
        $avgDailySales = $totalSales / $days;
        $avgDailyTransactions = $totalTransactions / $days;

        $data = [
            'daily_data' => $allDates,

            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'days' => $days
            ],

            'summary' => [
                'total_sales' => (float) $totalSales,
                'total_transactions' => $totalTransactions,
                'avg_daily_sales' => round($avgDailySales, 2),
                'avg_daily_transactions' => round($avgDailyTransactions, 2),
                'currency' => $currency
            ],

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'days' => $days
            ],

            'chart_config' => [
                'type' => 'line',
                'title' => 'Sales Growth Trend - Last ' . $days . ' Days',
                'x_axis' => 'date',
                'x_axis_label' => 'Date',
                'y_axis_label' => 'Sales Amount (' . $currency . ')',
                'lines' => [
                    [
                        'name' => 'Total Sales',
                        'data_key' => 'total_sales',
                        'color' => '#3B82F6',
                        'line_style' => 'solid',
                        'show_points' => true
                    ],
                    [
                        'name' => 'Avg Daily Sales',
                        'constant_value' => round($avgDailySales, 2),
                        'color' => '#10B981',
                        'line_style' => 'dashed',
                        'show_points' => false
                    ]
                ],
                'show_grid' => true,
                'show_legend' => true,
                'tooltip_format' => $currency . ' {value}'
            ]
        ];

        return successResponse('Sales growth trend retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get sales growth trend', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get sales growth trend', $e->getMessage());
    }
}
public function getPeakSalesHours(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        $query = Sale::select(
            DB::raw('HOUR(completed_at) as hour'),
            DB::raw('COUNT(*) as transaction_count'),
            DB::raw('SUM(total_amount) as total_sales'),
            DB::raw('AVG(total_amount) as average_order_value')
        )
        ->whereIn('status', ['completed', 'pending'])
        ->where('business_id', $businessId)
        ->whereDate('completed_at', '>=', $dateFrom)
        ->whereDate('completed_at', '<=', $dateTo);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $hourlyData = $query->groupBy(DB::raw('HOUR(completed_at)'))
            ->orderBy('hour', 'asc')
            ->get();

        // Fill in all 24 hours
        $allHours = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $existingData = $hourlyData->firstWhere('hour', $hour);

            $allHours[] = [
                'hour' => $hour,
                'time_label' => Carbon::createFromTime($hour, 0)->format('g A'),
                'transaction_count' => $existingData ? $existingData->transaction_count : 0,
                'total_sales' => $existingData ? (float) $existingData->total_sales : 0,
                'average_order_value' => $existingData ? round($existingData->average_order_value, 2) : 0
            ];
        }

        $sortedByTransactions = collect($allHours)->sortByDesc('transaction_count')->values();
        $sortedByRevenue = collect($allHours)->sortByDesc('total_sales')->values();

        $data = [
            'hourly_data' => $allHours,

            'peak_hours' => [
                'by_transactions' => [
                    'hour' => $sortedByTransactions->first()['hour'],
                    'time_label' => $sortedByTransactions->first()['time_label'],
                    'transaction_count' => $sortedByTransactions->first()['transaction_count']
                ],
                'by_revenue' => [
                    'hour' => $sortedByRevenue->first()['hour'],
                    'time_label' => $sortedByRevenue->first()['time_label'],
                    'total_sales' => $sortedByRevenue->first()['total_sales']
                ]
            ],

            'business_hours_summary' => [
                'morning' => $this->getTimeRangeSummary($allHours, 6, 11),
                'afternoon' => $this->getTimeRangeSummary($allHours, 12, 17),
                'evening' => $this->getTimeRangeSummary($allHours, 18, 23)
            ],

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ],

            'chart_config' => [
                'type' => 'bar',
                'title' => 'Peak Sales Hours',
                'x_axis' => 'time_label',
                'x_axis_label' => 'Hour of Day',
                'y_axis_label' => 'Transaction Count',
                'bars' => [
                    [
                        'name' => 'Transactions',
                        'data_key' => 'transaction_count',
                        'color' => '#3B82F6',
                        'show_values' => true
                    ]
                ],
                'show_grid' => true,
                'show_legend' => false,
                'tooltip_format' => '{count} transactions, ' . $currency . ' {sales}',
                'alternative_chart' => [
                    'type' => 'heatmap',
                    'title' => 'Sales Heatmap'
                ]
            ],

            'currency' => $currency
        ];

        return successResponse('Peak sales hours retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get peak sales hours', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get peak sales hours', $e->getMessage());
    }
}
/**
 * Helper: Get time range summary
 */
private function getTimeRangeSummary($hourlyData, $startHour, $endHour)
{
    $rangeData = collect($hourlyData)->filter(function ($item) use ($startHour, $endHour) {
        return $item['hour'] >= $startHour && $item['hour'] <= $endHour;
    });

    return [
        'transaction_count' => $rangeData->sum('transaction_count'),
        'total_sales' => (float) $rangeData->sum('total_sales'),
        'average_order_value' => $rangeData->sum('transaction_count') > 0
            ? round($rangeData->sum('total_sales') / $rangeData->sum('transaction_count'), 2)
            : 0
    ];
}
  /**
 * Widget 3 (Tab 2): Cashier Performance
 * Endpoint: GET /api/sales-dashboard/cashier-performance
 */
public function getCashierPerformance(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());
        $limit = $request->input('limit', 10);

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        $query = Sale::select(
            'sales.user_id',
            'users.name as cashier_name',
            DB::raw('COUNT(sales.id) as transaction_count'),
            DB::raw('SUM(sales.total_amount) as total_sales'),
            DB::raw('AVG(sales.total_amount) as average_order_value'),
            DB::raw('SUM(sales.discount_amount) as total_discounts_given')
        )
        ->join('users', 'sales.user_id', '=', 'users.id')
        ->whereIn('sales.status', ['completed', 'pending'])
        ->where('sales.business_id', $businessId)
        ->whereDate('sales.completed_at', '>=', $dateFrom)
        ->whereDate('sales.completed_at', '<=', $dateTo);

        if ($branchId) {
            $query->where('sales.branch_id', $branchId);
        }

        $cashiers = $query->groupBy('sales.user_id', 'users.name')
            ->orderByDesc('total_sales')
            ->limit($limit)
            ->get();

        $totalSales = $cashiers->sum('total_sales');

        $rankedCashiers = $cashiers->map(function ($cashier, $index) use ($totalSales) {
            return [
                'rank' => $index + 1,
                'user_id' => $cashier->user_id,
                'cashier_name' => $cashier->cashier_name,
                'transaction_count' => $cashier->transaction_count,
                'total_sales' => (float) $cashier->total_sales,
                'average_order_value' => round($cashier->average_order_value, 2),
                'total_discounts_given' => (float) $cashier->total_discounts_given,
                'sales_percentage' => $totalSales > 0
                    ? round(($cashier->total_sales / $totalSales) * 100, 2)
                    : 0
            ];
        });

        $data = [
            'cashiers' => $rankedCashiers,

            'summary' => [
                'total_cashiers' => $cashiers->count(),
                'total_sales' => (float) $totalSales,
                'currency' => $currency
            ],

            'top_performer' => $rankedCashiers->first(),

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit
            ],

            'chart_config' => [
                'type' => 'horizontal_bar',
                'title' => 'Cashier Performance Rankings',
                'x_axis' => 'total_sales',
                'x_axis_label' => 'Total Sales (' . $currency . ')',
                'y_axis' => 'cashier_name',
                'y_axis_label' => 'Cashier',
                'bars' => [
                    [
                        'name' => 'Sales',
                        'data_key' => 'total_sales',
                        'color' => '#3B82F6',
                        'show_values' => true,
                        'value_format' => $currency . ' {value}'
                    ]
                ],
                'show_grid' => true,
                'show_legend' => false,
                'show_rank' => true,
                'show_percentage' => true
            ]
        ];

        return successResponse('Cashier performance retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get cashier performance', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get cashier performance', $e->getMessage());
    }
}
/**
 * Widget 4 (Tab 2): Sales By Category
 * Endpoint: GET /api/sales-dashboard/sales-by-category
 */
public function getSalesByCategory(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        $query = SaleItem::select(
            'categories.id as category_id',
            'categories.name as category_name',
            DB::raw('COUNT(DISTINCT sales.id) as order_count'),
            DB::raw('SUM(sale_items.quantity) as total_quantity'),
            DB::raw('SUM(sale_items.line_total) as total_revenue'),
            DB::raw('SUM(sale_items.unit_cost * sale_items.quantity) as total_cost'),
            DB::raw('SUM(sale_items.tax_amount) as total_tax')
        )
        ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
        ->join('products', 'sale_items.product_id', '=', 'products.id')
        ->join('categories', 'products.category_id', '=', 'categories.id')
        ->whereIn('sales.status', ['completed', 'pending'])
        ->where('sales.business_id', $businessId)
        ->whereDate('sales.completed_at', '>=', $dateFrom)
        ->whereDate('sales.completed_at', '<=', $dateTo);

        if ($branchId) {
            $query->where('sales.branch_id', $branchId);
        }

        $categories = $query->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_revenue')
            ->get();

        $totalRevenue = $categories->sum('total_revenue');

        // Category colors
        $categoryColors = [
            '#3B82F6', '#10B981', '#F59E0B', '#8B5CF6', '#EF4444',
            '#06B6D4', '#EC4899', '#F97316', '#14B8A6', '#A855F7'
        ];

        $categoryData = $categories->map(function ($category, $index) use ($totalRevenue, $categoryColors) {
            $profit = $category->total_revenue - $category->total_cost;
            $profitMargin = $category->total_revenue > 0
                ? (($profit / $category->total_revenue) * 100)
                : 0;

            return [
                'category_id' => $category->category_id,
                'category_name' => $category->category_name,
                'order_count' => $category->order_count,
                'total_quantity' => (float) $category->total_quantity,
                'total_revenue' => (float) $category->total_revenue,
                'total_cost' => (float) $category->total_cost,
                'total_profit' => (float) $profit,
                'profit_margin' => round($profitMargin, 2),
                'total_tax' => (float) $category->total_tax,
                'revenue_percentage' => $totalRevenue > 0
                    ? round(($category->total_revenue / $totalRevenue) * 100, 2)
                    : 0,
                'color' => $categoryColors[$index % count($categoryColors)]
            ];
        });

        $data = [
            'categories' => $categoryData,

            'summary' => [
                'total_revenue' => (float) $totalRevenue,
                'total_cost' => (float) $categories->sum('total_cost'),
                'total_profit' => (float) ($totalRevenue - $categories->sum('total_cost')),
                'overall_margin' => $totalRevenue > 0
                    ? round((($totalRevenue - $categories->sum('total_cost')) / $totalRevenue) * 100, 2)
                    : 0,
                'total_categories' => $categories->count(),
                'currency' => $currency
            ],

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ],

            'chart_config' => [
                'type' => 'donut',
                'title' => 'Revenue by Category',
                'data_key' => 'total_revenue',
                'label_key' => 'category_name',
                'color_key' => 'color',
                'show_legend' => true,
                'show_percentages' => true,
                'show_values' => true,
                'center_text' => [
                    'top' => 'Total Revenue',
                    'bottom' => $currency . ' ' . number_format($totalRevenue, 2)
                ],
                'inner_radius' => '60%',
                'tooltip_format' => $currency . ' {value} ({percentage}%)',
                'alternative_chart' => [
                    'type' => 'pie',
                    'title' => 'Category Distribution'
                ]
            ]
        ];

        return successResponse('Sales by category retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get sales by category', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get sales by category', $e->getMessage());
    }
}

/**
 * ============================================
 * TAB 3: PRODUCT ANALYTICS 🛍️
 * ============================================
 */

/**
 * Widget 1 (Tab 3): Product Analytics Summary Cards
 * Endpoint: GET /api/sales-dashboard/product-summary
 */
public function getProductSummary(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        // Base query for sale items
        $saleItemsQuery = SaleItem::join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->whereIn('sales.status', ['completed', 'pending'])
            ->where('sales.business_id', $businessId)
            ->whereDate('sales.completed_at', '>=', $dateFrom)
            ->whereDate('sales.completed_at', '<=', $dateTo);

        if ($branchId) {
            $saleItemsQuery->where('sales.branch_id', $branchId);
        }

        // Card 1: Total Products Sold
        $totalProductsSold = (clone $saleItemsQuery)
            ->distinct('sale_items.product_id')
            ->count('sale_items.product_id');

        // Card 2: Total Quantity Sold
        $totalQuantitySold = (clone $saleItemsQuery)
            ->sum('sale_items.quantity');

        // Card 3: Total Revenue from Products
        $totalRevenue = (clone $saleItemsQuery)
            ->sum('sale_items.line_total');

        // Card 4: Average Product Price
        $averagePrice = (clone $saleItemsQuery)
            ->avg('sale_items.unit_price');

        // Card 5: Low Stock Products
        $lowStockQuery = Stock::where('business_id', $businessId);

        if ($branchId) {
            $lowStockQuery->where('branch_id', $branchId);
        }

        $lowStockCount = $lowStockQuery->whereHas('product', function($q) {
            $q->where('track_inventory', true)
              ->whereColumn('stocks.quantity', '<', 'products.minimum_stock_level');
        })->count();

        // Card 6: Out of Stock Products
        $outOfStockQuery = Stock::where('business_id', $businessId);

        if ($branchId) {
            $outOfStockQuery->where('branch_id', $branchId);
        }

        $outOfStockCount = $outOfStockQuery->where('quantity', '<=', 0)->count();

        // Calculate trends
        $daysDiff = Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo)) + 1;
        $previousDateFrom = Carbon::parse($dateFrom)->subDays($daysDiff)->toDateString();
        $previousDateTo = Carbon::parse($dateFrom)->subDay()->toDateString();

        $previousRevenue = SaleItem::join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->whereIn('sales.status', ['completed', 'pending'])
            ->whereDate('sales.completed_at', '>=', $previousDateFrom)
            ->whereDate('sales.completed_at', '<=', $previousDateTo)
            ->where('sales.business_id', $businessId)
            ->when($branchId, function($q) use ($branchId) {
                $q->where('sales.branch_id', $branchId);
            })
            ->sum('sale_items.line_total');

        $revenueTrend = 0;
        $trendDirection = 'stable';
        if ($previousRevenue > 0) {
            $revenueTrend = round((($totalRevenue - $previousRevenue) / $previousRevenue) * 100, 1);
            $trendDirection = $revenueTrend > 0 ? 'up' : ($revenueTrend < 0 ? 'down' : 'stable');
        }

        $data = [
            'summary_cards' => [
                [
                    'id' => 'total_products_sold',
                    'title' => 'Total Products Sold',
                    'value' => $totalProductsSold,
                    'subtitle' => 'Unique products',
                    'icon' => 'package',
                    'color' => '#3B82F6'
                ],
                [
                    'id' => 'total_quantity',
                    'title' => 'Total Quantity Sold',
                    'value' => (int) $totalQuantitySold,
                    'subtitle' => 'Units sold',
                    'icon' => 'shopping-cart',
                    'color' => '#10B981'
                ],
                [
                    'id' => 'total_revenue',
                    'title' => 'Total Revenue',
                    'value' => (float) $totalRevenue,
                    'subtitle' => 'From product sales',
                    'icon' => 'dollar-sign',
                    'color' => '#8B5CF6',
                    'trend' => [
                        'value' => abs($revenueTrend),
                        'direction' => $trendDirection,
                        'label' => abs($revenueTrend) . '% vs previous period'
                    ]
                ],
                [
                    'id' => 'average_price',
                    'title' => 'Average Product Price',
                    'value' => round($averagePrice, 2),
                    'subtitle' => 'Per unit',
                    'icon' => 'tag',
                    'color' => '#F59E0B'
                ],
                [
                    'id' => 'low_stock',
                    'title' => 'Low Stock Products',
                    'value' => $lowStockCount,
                    'subtitle' => 'Below minimum level',
                    'icon' => 'alert-triangle',
                    'color' => $lowStockCount > 0 ? '#EF4444' : '#6B7280'
                ],
                [
                    'id' => 'out_of_stock',
                    'title' => 'Out of Stock',
                    'value' => $outOfStockCount,
                    'subtitle' => 'Need restock',
                    'icon' => 'x-circle',
                    'color' => $outOfStockCount > 0 ? '#EF4444' : '#6B7280'
                ]
            ],

            'summary' => [
                'total_products_sold' => $totalProductsSold,
                'total_quantity_sold' => (int) $totalQuantitySold,
                'total_revenue' => (float) $totalRevenue,
                'average_price' => round($averagePrice, 2),
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
                'currency' => $currency
            ],

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ]
        ];

        return successResponse('Product summary retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get product summary', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get product summary', $e->getMessage());
    }
}
/**
 * Widget 2 (Tab 3): Product Performance
 * Endpoint: GET /api/sales-dashboard/product-performance
 */
public function getProductPerformance(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());
        $limit = $request->input('limit', 20);

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        $query = SaleItem::select(
            'products.id as product_id',
            'products.name as product_name',
            'products.sku',
            'categories.name as category_name',
            DB::raw('SUM(sale_items.quantity) as total_quantity'),
            DB::raw('SUM(sale_items.line_total) as total_revenue'),
            DB::raw('AVG(sale_items.unit_price) as average_price'),
            DB::raw('SUM(sale_items.unit_cost * sale_items.quantity) as total_cost'),
            DB::raw('COUNT(DISTINCT sale_items.sale_id) as order_count')
        )
        ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
        ->join('products', 'sale_items.product_id', '=', 'products.id')
        ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
        ->whereIn('sales.status', ['completed', 'pending'])
        ->where('sales.business_id', $businessId)
        ->whereDate('sales.completed_at', '>=', $dateFrom)
        ->whereDate('sales.completed_at', '<=', $dateTo);

        if ($branchId) {
            $query->where('sales.branch_id', $branchId);
        }

        $products = $query->groupBy('products.id', 'products.name', 'products.sku', 'categories.name')
            ->orderByDesc('total_revenue')
            ->limit($limit)
            ->get();

        $totalRevenue = $products->sum('total_revenue');

        $productData = $products->map(function ($product, $index) use ($totalRevenue) {
            $profit = $product->total_revenue - $product->total_cost;
            $profitMargin = $product->total_revenue > 0
                ? (($profit / $product->total_revenue) * 100)
                : 0;

            return [
                'rank' => $index + 1,
                'product_id' => $product->product_id,
                'product_name' => $product->product_name,
                'sku' => $product->sku,
                'category_name' => $product->category_name ?? 'Uncategorized',
                'total_quantity' => (int) $product->total_quantity,
                'total_revenue' => (float) $product->total_revenue,
                'average_price' => round($product->average_price, 2),
                'total_cost' => (float) $product->total_cost,
                'total_profit' => (float) $profit,
                'profit_margin' => round($profitMargin, 2),
                'order_count' => $product->order_count,
                'revenue_percentage' => $totalRevenue > 0
                    ? round(($product->total_revenue / $totalRevenue) * 100, 2)
                    : 0
            ];
        });

        $data = [
            'products' => $productData,

            'summary' => [
                'total_products' => $products->count(),
                'total_revenue' => (float) $totalRevenue,
                'total_quantity' => (int) $products->sum('total_quantity'),
                'total_orders' => $products->sum('order_count'),
                'currency' => $currency
            ],

            'top_performer' => $productData->first(),

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit
            ],

            'chart_config' => [
                'type' => 'horizontal_bar',
                'title' => 'Top ' . $limit . ' Products by Revenue',
                'x_axis' => 'total_revenue',
                'x_axis_label' => 'Revenue (' . $currency . ')',
                'y_axis' => 'product_name',
                'y_axis_label' => 'Product',
                'bars' => [
                    [
                        'name' => 'Revenue',
                        'data_key' => 'total_revenue',
                        'color' => '#3B82F6',
                        'show_values' => true,
                        'value_format' => $currency . ' {value}'
                    ]
                ],
                'show_grid' => true,
                'show_legend' => false,
                'show_rank' => true,
                'tooltip_format' => '{product_name}: ' . $currency . ' {value}'
            ]
        ];

        return successResponse('Product performance retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get product performance', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get product performance', $e->getMessage());
    }
}
/**
 * Widget 3 (Tab 3): Low Stock Alerts
 * Endpoint: GET /api/sales-dashboard/low-stock-alerts
 */
public function getLowStockAlerts(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $limit = $request->input('limit', 20);

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        $query = Product::select(
            'products.id',
            'products.name',
            'products.sku',
            'products.current_stock',
            'products.minimum_stock_level',
            'products.unit_price',
            'categories.name as category_name',
            DB::raw('(products.minimum_stock_level - products.current_stock) as stock_shortage')
        )
        ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
        ->where('products.business_id', $businessId)
        ->whereRaw('products.current_stock < products.minimum_stock_level');

        if ($branchId) {
            $query->where('products.branch_id', $branchId);
        }

        $lowStockProducts = $query->orderByDesc('stock_shortage')
            ->limit($limit)
            ->get();

        $productData = $lowStockProducts->map(function ($product) {
            $stockPercentage = $product->minimum_stock_level > 0
                ? round(($product->current_stock / $product->minimum_stock_level) * 100, 1)
                : 0;

            $urgency = 'medium';
            $urgencyColor = '#F59E0B';

            if ($product->current_stock <= 0) {
                $urgency = 'critical';
                $urgencyColor = '#EF4444';
            } elseif ($stockPercentage < 25) {
                $urgency = 'high';
                $urgencyColor = '#EF4444';
            } elseif ($stockPercentage < 50) {
                $urgency = 'medium';
                $urgencyColor = '#F59E0B';
            } else {
                $urgency = 'low';
                $urgencyColor = '#10B981';
            }

            return [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'category_name' => $product->category_name ?? 'Uncategorized',
                'current_stock' => (int) $product->current_stock,
                'minimum_stock_level' => (int) $product->minimum_stock_level,
                'stock_shortage' => (int) $product->stock_shortage,
                'stock_percentage' => $stockPercentage,
                'unit_price' => (float) $product->unit_price,
                'restock_value' => (float) ($product->stock_shortage * $product->unit_price),
                'urgency' => $urgency,
                'urgency_color' => $urgencyColor
            ];
        });

        $totalRestockValue = $productData->sum('restock_value');

        $data = [
            'low_stock_products' => $productData,

            'summary' => [
                'total_low_stock' => $lowStockProducts->count(),
                'critical_count' => $productData->where('urgency', 'critical')->count(),
                'high_urgency_count' => $productData->where('urgency', 'high')->count(),
                'total_restock_value' => (float) $totalRestockValue,
                'currency' => $currency
            ],

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'limit' => $limit
            ]
        ];

        return successResponse('Low stock alerts retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get low stock alerts', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get low stock alerts', $e->getMessage());
    }
}
/**
 * Widget 4 (Tab 3): Slow Moving Products
 * Endpoint: GET /api/sales-dashboard/slow-moving-products
 */
public function getSlowMovingProducts(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $days = $request->input('days', 30);
        $limit = $request->input('limit', 20);

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        $dateThreshold = Carbon::now()->subDays($days)->toDateString();

        $query = Product::select(
            'products.id',
            'products.name',
            'products.sku',
            'products.current_stock',
            'products.unit_price',
            'products.unit_cost',
            'categories.name as category_name',
            DB::raw('MAX(sales.completed_at) as last_sale_date')
        )
        ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
        ->leftJoin('sale_items', 'products.id', '=', 'sale_items.product_id')
        ->leftJoin('sales', function($join) {
            $join->on('sale_items.sale_id', '=', 'sales.id')
                 ->whereIn('sales.status', ['completed', 'pending']);
        })
        ->where('products.business_id', $businessId);

        if ($branchId) {
            $query->where('products.branch_id', $branchId);
        }

        $slowMovingProducts = $query->groupBy(
            'products.id',
            'products.name',
            'products.sku',
            'products.current_stock',
            'products.unit_price',
            'products.unit_cost',
            'categories.name'
        )
        ->havingRaw('MAX(sales.completed_at) IS NULL OR MAX(sales.completed_at) < ?', [$dateThreshold])
        ->orderBy('last_sale_date', 'asc')
        ->limit($limit)
        ->get();

        $productData = $slowMovingProducts->map(function ($product) use ($days) {
            $daysSinceLastSale = $product->last_sale_date
                ? Carbon::parse($product->last_sale_date)->diffInDays(Carbon::now())
                : null;

            $stockValue = $product->current_stock * $product->unit_cost;

            return [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'category_name' => $product->category_name ?? 'Uncategorized',
                'current_stock' => (int) $product->current_stock,
                'unit_price' => (float) $product->unit_price,
                'unit_cost' => (float) $product->unit_cost,
                'stock_value' => (float) $stockValue,
                'last_sale_date' => $product->last_sale_date,
                'days_since_last_sale' => $daysSinceLastSale,
                'status' => !$product->last_sale_date ? 'Never Sold' : ($daysSinceLastSale > 90 ? 'Critical' : 'Slow Moving')
            ];
        });

        $totalStockValue = $productData->sum('stock_value');

        $data = [
            'slow_moving_products' => $productData,

            'summary' => [
                'total_slow_moving' => $slowMovingProducts->count(),
                'never_sold_count' => $productData->whereNull('last_sale_date')->count(),
                'total_stock_value' => (float) $totalStockValue,
                'days_threshold' => $days,
                'currency' => $currency
            ],

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'days' => $days,
                'limit' => $limit
            ]
        ];

        return successResponse('Slow moving products retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get slow moving products', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get slow moving products', $e->getMessage());
    }
}
/**
 * Widget 5 (Tab 3): Category Performance
 * Endpoint: GET /api/sales-dashboard/category-performance
 */
public function getCategoryPerformance(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        $query = SaleItem::select(
            'categories.id as category_id',
            'categories.name as category_name',
            DB::raw('COUNT(DISTINCT sales.id) as order_count'),
            DB::raw('SUM(sale_items.quantity) as total_quantity'),
            DB::raw('SUM(sale_items.line_total) as total_revenue'),
            DB::raw('SUM(sale_items.unit_cost * sale_items.quantity) as total_cost')
        )
        ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
        ->join('products', 'sale_items.product_id', '=', 'products.id')
        ->join('categories', 'products.category_id', '=', 'categories.id')
        ->whereIn('sales.status', ['completed', 'pending'])
        ->where('sales.business_id', $businessId)
        ->whereDate('sales.completed_at', '>=', $dateFrom)
        ->whereDate('sales.completed_at', '<=', $dateTo);

        if ($branchId) {
            $query->where('sales.branch_id', $branchId);
        }

        $categories = $query->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_revenue')
            ->get();

        $totalRevenue = $categories->sum('total_revenue');

        // Category colors
        $categoryColors = [
            '#3B82F6', '#10B981', '#F59E0B', '#8B5CF6', '#EF4444',
            '#06B6D4', '#EC4899', '#F97316', '#14B8A6', '#A855F7'
        ];

        $categoryData = $categories->map(function ($category, $index) use ($totalRevenue, $categoryColors) {
            $profit = $category->total_revenue - $category->total_cost;
            $profitMargin = $category->total_revenue > 0
                ? (($profit / $category->total_revenue) * 100)
                : 0;

            return [
                'category_id' => $category->category_id,
                'category_name' => $category->category_name,
                'order_count' => $category->order_count,
                'total_quantity' => (int) $category->total_quantity,
                'total_revenue' => (float) $category->total_revenue,
                'total_cost' => (float) $category->total_cost,
                'total_profit' => (float) $profit,
                'profit_margin' => round($profitMargin, 2),
                'revenue_percentage' => $totalRevenue > 0
                    ? round(($category->total_revenue / $totalRevenue) * 100, 2)
                    : 0,
                'color' => $categoryColors[$index % count($categoryColors)]
            ];
        });

        $data = [
            'categories' => $categoryData,

            'summary' => [
                'total_revenue' => (float) $totalRevenue,
                'total_cost' => (float) $categories->sum('total_cost'),
                'total_profit' => (float) ($totalRevenue - $categories->sum('total_cost')),
                'overall_margin' => $totalRevenue > 0
                    ? round((($totalRevenue - $categories->sum('total_cost')) / $totalRevenue) * 100, 2)
                    : 0,
                'total_categories' => $categories->count(),
                'currency' => $currency
            ],

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ],

            'chart_config' => [
                'type' => 'donut',
                'title' => 'Category Performance',
                'data_key' => 'total_revenue',
                'label_key' => 'category_name',
                'color_key' => 'color',
                'show_legend' => true,
                'show_percentages' => true,
                'show_values' => true,
                'center_text' => [
                    'top' => 'Total Revenue',
                    'bottom' => $currency . ' ' . number_format($totalRevenue, 2)
                ],
                'inner_radius' => '60%',
                'tooltip_format' => $currency . ' {value} ({percentage}%)',
                'alternative_chart' => [
                    'type' => 'pie',
                    'title' => 'Category Distribution'
                ]
            ]
        ];

        return successResponse('Category performance retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get category performance', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get category performance', $e->getMessage());
    }
}
/**
 * ============================================
 * TAB 4: PAYMENT & TRANSACTION ANALYSIS 💳
 * ============================================
 */

/**
 * Function 1 (Tab 4): Payment Method Analytics
 * Shows payment method usage, fees, and breakdown
 * Visualization: SUMMARY CARDS + DONUT CHART + TABLE
 * Endpoint: GET /api/sales-dashboard/payment-method-analytics
 */
public function getPaymentMethodAnalytics(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        // 🚨 CRITICAL FIX: Use amount_in_sale_currency instead of amount
        $query = DB::table('sales')
            ->join('sale_payments', 'sales.id', '=', 'sale_payments.sale_id')
            ->join('payments', 'sale_payments.payment_id', '=', 'payments.id')
            ->join('payment_methods', 'payments.payment_method_id', '=', 'payment_methods.id')
            ->select(
                'payment_methods.id as method_id',
                'payment_methods.name as payment_method',
                'payment_methods.type as payment_type',
                DB::raw('COUNT(DISTINCT sales.id) as transaction_count'),
                DB::raw('SUM(sale_payments.amount_in_sale_currency) as total_amount'), // 🆕 FIXED
                DB::raw('SUM(payments.fee_amount) as total_fees'),
                DB::raw('AVG(sale_payments.amount_in_sale_currency) as average_transaction_value') // 🆕 FIXED
            )
            ->whereIn('sales.status', ['completed', 'pending'])
            ->where('payments.status', 'completed')
            ->where('sales.business_id', $businessId)
            ->whereDate('sales.completed_at', '>=', $dateFrom)
            ->whereDate('sales.completed_at', '<=', $dateTo);

        if ($branchId) {
            $query->where('sales.branch_id', $branchId);
        }

        $query->groupBy('payment_methods.id', 'payment_methods.name', 'payment_methods.type')
            ->orderByDesc('total_amount');

        $paymentMethods = $query->get();

        $totalAmount = $paymentMethods->sum('total_amount');
        $totalFees = $paymentMethods->sum('total_fees');
        $netAmount = $totalAmount - $totalFees;
        $totalTransactions = $paymentMethods->sum('transaction_count');

        // Find most used payment method
        $mostUsedMethod = $paymentMethods->sortByDesc('transaction_count')->first();

        // Payment method colors
        $methodColors = [
            '#3B82F6', '#10B981', '#F59E0B', '#8B5CF6', '#EF4444',
            '#06B6D4', '#EC4899', '#F97316', '#14B8A6', '#A855F7'
        ];

        $paymentData = $paymentMethods->map(function ($method, $index) use ($totalAmount, $methodColors) {
            $netMethodAmount = $method->total_amount - $method->total_fees;

            return [
                'method_id' => $method->method_id,
                'payment_method' => $method->payment_method,
                'payment_type' => $method->payment_type,
                'transaction_count' => $method->transaction_count,
                'total_amount' => (float) $method->total_amount,
                'total_fees' => (float) $method->total_fees,
                'net_amount' => (float) $netMethodAmount,
                'average_transaction_value' => round($method->average_transaction_value, 2),
                'percentage' => $totalAmount > 0
                    ? round(($method->total_amount / $totalAmount) * 100, 2)
                    : 0,
                'color' => $methodColors[$index % count($methodColors)]
            ];
        });

        $data = [
            'summary_cards' => [
                [
                    'id' => 'total_payments',
                    'title' => 'Total Payments Processed',
                    'value' => (float) $totalAmount,
                    'subtitle' => $totalTransactions . ' transactions',
                    'icon' => 'credit-card',
                    'color' => '#3B82F6'
                ],
                [
                    'id' => 'total_fees',
                    'title' => 'Total Fees Collected',
                    'value' => (float) $totalFees,
                    'subtitle' => 'Transaction fees',
                    'icon' => 'percent',
                    'color' => '#F59E0B'
                ],
                [
                    'id' => 'net_amount',
                    'title' => 'Net Amount',
                    'value' => (float) $netAmount,
                    'subtitle' => 'After fees',
                    'icon' => 'dollar-sign',
                    'color' => '#10B981'
                ],
                [
                    'id' => 'most_used_method',
                    'title' => 'Most Used Method',
                    'value' => $mostUsedMethod ? $mostUsedMethod->payment_method : 'N/A',
                    'subtitle' => $mostUsedMethod ? $mostUsedMethod->transaction_count . ' transactions' : '',
                    'icon' => 'trending-up',
                    'color' => '#8B5CF6'
                ]
            ],

            'payment_methods' => $paymentData,

            'summary' => [
                'total_amount' => (float) $totalAmount,
                'total_fees' => (float) $totalFees,
                'net_amount' => (float) $netAmount,
                'total_transactions' => $totalTransactions,
                'average_transaction' => $totalTransactions > 0
                    ? round($totalAmount / $totalTransactions, 2)
                    : 0,
                'fee_percentage' => $totalAmount > 0
                    ? round(($totalFees / $totalAmount) * 100, 2)
                    : 0,
                'currency' => $currency
            ],

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ],

            'chart_config' => [
                'type' => 'donut',
                'title' => 'Payment Method Distribution',
                'data_key' => 'total_amount',
                'label_key' => 'payment_method',
                'color_key' => 'color',
                'show_legend' => true,
                'show_percentages' => true,
                'show_values' => true,
                'center_text' => [
                    'top' => 'Total Payments',
                    'bottom' => $currency . ' ' . number_format($totalAmount, 2)
                ],
                'inner_radius' => '60%',
                'tooltip_format' => $currency . ' {value} ({percentage}%)'
            ]
        ];

        return successResponse('Payment method analytics retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get payment method analytics', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get payment method analytics', $e->getMessage());
    }
}
/**
 * Function 2 (Tab 4): Return & Refund Summary
 * Shows returns, refunds, and return reasons
 * Visualization: SUMMARY CARDS + BAR CHART + TABLE
 * Endpoint: GET /api/sales-dashboard/return-refund-summary
 */
public function getReturnRefundSummary(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        // Get return transactions
        $returnsQuery = DB::table('return_transactions')
            ->join('sales', 'return_transactions.original_sale_id', '=', 'sales.id')
            ->where('return_transactions.business_id', $businessId)
            ->whereDate('return_transactions.created_at', '>=', $dateFrom)
            ->whereDate('return_transactions.created_at', '<=', $dateTo);

        if ($branchId) {
            $returnsQuery->where('return_transactions.branch_id', $branchId);
        }

        $totalReturns = (clone $returnsQuery)->count();
        $totalRefundedAmount = (clone $returnsQuery)->sum('return_transactions.total_amount');

        // Get total sales for return rate calculation
        $totalSalesQuery = Sale::where('business_id', $businessId)
            ->whereIn('status', ['completed', 'pending'])
            ->whereDate('completed_at', '>=', $dateFrom)
            ->whereDate('completed_at', '<=', $dateTo);

        if ($branchId) {
            $totalSalesQuery->where('branch_id', $branchId);
        }

        $totalSalesCount = $totalSalesQuery->count();
        $totalSalesAmount = $totalSalesQuery->sum('total_amount');

        $returnRate = $totalSalesCount > 0
            ? round(($totalReturns / $totalSalesCount) * 100, 2)
            : 0;

        $refundRate = $totalSalesAmount > 0
            ? round(($totalRefundedAmount / $totalSalesAmount) * 100, 2)
            : 0;

        $averageRefund = $totalReturns > 0
            ? round($totalRefundedAmount / $totalReturns, 2)
            : 0;

        // Get return reasons breakdown
        $returnReasons = DB::table('return_transactions')
            ->select(
                'reason',
                DB::raw('COUNT(*) as return_count'),
                DB::raw('SUM(total_amount) as total_refunded')
            )
            ->where('business_id', $businessId)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->when($branchId, function($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            })
            ->groupBy('reason')
            ->orderByDesc('return_count')
            ->limit(10)
            ->get();

        $reasonsData = $returnReasons->map(function ($reason) use ($totalReturns) {
            return [
                'reason' => $reason->reason,
                'return_count' => $reason->return_count,
                'total_refunded' => (float) $reason->total_refunded,
                'percentage' => $totalReturns > 0
                    ? round(($reason->return_count / $totalReturns) * 100, 2)
                    : 0
            ];
        });

        $data = [
            'summary_cards' => [
                [
                    'id' => 'total_returns',
                    'title' => 'Total Returns',
                    'value' => $totalReturns,
                    'subtitle' => 'Return transactions',
                    'icon' => 'rotate-ccw',
                    'color' => '#EF4444'
                ],
                [
                    'id' => 'total_refunded',
                    'title' => 'Total Refunded',
                    'value' => (float) $totalRefundedAmount,
                    'subtitle' => 'Amount refunded',
                    'icon' => 'dollar-sign',
                    'color' => '#F59E0B'
                ],
                [
                    'id' => 'return_rate',
                    'title' => 'Return Rate',
                    'value' => $returnRate . '%',
                    'subtitle' => 'Of total sales',
                    'icon' => 'percent',
                    'color' => '#8B5CF6'
                ],
                [
                    'id' => 'average_refund',
                    'title' => 'Average Refund',
                    'value' => (float) $averageRefund,
                    'subtitle' => 'Per return',
                    'icon' => 'trending-down',
                    'color' => '#10B981'
                ]
            ],

            'return_reasons' => $reasonsData,

            'summary' => [
                'total_returns' => $totalReturns,
                'total_refunded_amount' => (float) $totalRefundedAmount,
                'return_rate' => $returnRate,
                'refund_rate' => $refundRate,
                'average_refund' => (float) $averageRefund,
                'currency' => $currency
            ],

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ],

            'chart_config' => [
                'type' => 'horizontal_bar',
                'title' => 'Top Return Reasons',
                'x_axis' => 'return_count',
                'x_axis_label' => 'Number of Returns',
                'y_axis' => 'reason',
                'y_axis_label' => 'Return Reason',
                'bars' => [
                    [
                        'name' => 'Returns',
                        'data_key' => 'return_count',
                        'color' => '#EF4444',
                        'show_values' => true
                    ]
                ],
                'show_grid' => true,
                'show_legend' => false
            ]
        ];

        return successResponse('Return refund summary retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get return refund summary', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get return refund summary', $e->getMessage());
    }
}
/**
 * Function 3 (Tab 4): Discount Usage Summary
 * Shows discounts applied, codes used, and savings
 * Visualization: SUMMARY CARDS + BAR CHART + DONUT CHART
 * Endpoint: GET /api/sales-dashboard/discount-usage-summary
 */
public function getDiscountUsageSummary(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        // Get sales with discounts
        $salesQuery = Sale::where('business_id', $businessId)
            ->whereIn('status', ['completed', 'pending'])
            ->whereDate('completed_at', '>=', $dateFrom)
            ->whereDate('completed_at', '<=', $dateTo);

        if ($branchId) {
            $salesQuery->where('branch_id', $branchId);
        }

        $totalSales = (clone $salesQuery)->count();
        $totalSalesAmount = (clone $salesQuery)->sum('total_amount');

        // Sales with discounts
        $salesWithDiscounts = (clone $salesQuery)
            ->where('discount_amount', '>', 0)
            ->get();

        $discountedSalesCount = $salesWithDiscounts->count();
        $totalDiscountAmount = $salesWithDiscounts->sum('discount_amount');

        $discountRate = $totalSales > 0
            ? round(($discountedSalesCount / $totalSales) * 100, 2)
            : 0;

        $discountPercentage = $totalSalesAmount > 0
            ? round(($totalDiscountAmount / ($totalSalesAmount + $totalDiscountAmount)) * 100, 2)
            : 0;

        $averageDiscount = $discountedSalesCount > 0
            ? round($totalDiscountAmount / $discountedSalesCount, 2)
            : 0;

        // Get discount breakdown by type (if discount table has type field)
        $discountTypes = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->select(
                DB::raw('CASE
                    WHEN sale_items.discount_amount > 0 THEN "Item Discount"
                    ELSE "No Discount"
                END as discount_type'),
                DB::raw('COUNT(DISTINCT sales.id) as transaction_count'),
                DB::raw('SUM(sale_items.discount_amount) as total_discount')
            )
            ->where('sales.business_id', $businessId)
            ->whereIn('sales.status', ['completed', 'pending'])
            ->whereDate('sales.completed_at', '>=', $dateFrom)
            ->whereDate('sales.completed_at', '<=', $dateTo)
            ->when($branchId, function($q) use ($branchId) {
                $q->where('sales.branch_id', $branchId);
            })
            ->where('sale_items.discount_amount', '>', 0)
            ->groupBy('discount_type')
            ->get();

        // Most used discounts (by sale)
        $topDiscountedProducts = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->select(
                'products.name as product_name',
                DB::raw('COUNT(*) as discount_count'),
                DB::raw('SUM(sale_items.discount_amount) as total_discount')
            )
            ->where('sales.business_id', $businessId)
            ->whereIn('sales.status', ['completed', 'pending'])
            ->where('sale_items.discount_amount', '>', 0)
            ->whereDate('sales.completed_at', '>=', $dateFrom)
            ->whereDate('sales.completed_at', '<=', $dateTo)
            ->when($branchId, function($q) use ($branchId) {
                $q->where('sales.branch_id', $branchId);
            })
            ->groupBy('products.name')
            ->orderByDesc('total_discount')
            ->limit(10)
            ->get();

        $discountColors = ['#3B82F6', '#10B981', '#F59E0B', '#8B5CF6'];

        $data = [
            'summary_cards' => [
                [
                    'id' => 'total_discounts',
                    'title' => 'Transactions with Discounts',
                    'value' => $discountedSalesCount,
                    'subtitle' => 'Sales with discounts',
                    'icon' => 'tag',
                    'color' => '#3B82F6'
                ],
                [
                    'id' => 'discount_amount',
                    'title' => 'Total Discount Amount',
                    'value' => (float) $totalDiscountAmount,
                    'subtitle' => 'Total savings given',
                    'icon' => 'dollar-sign',
                    'color' => '#F59E0B'
                ],
                [
                    'id' => 'discount_rate',
                    'title' => 'Discount Rate',
                    'value' => $discountRate . '%',
                    'subtitle' => 'Of total transactions',
                    'icon' => 'percent',
                    'color' => '#10B981'
                ],
                [
                    'id' => 'average_discount',
                    'title' => 'Average Discount',
                    'value' => (float) $averageDiscount,
                    'subtitle' => 'Per transaction',
                    'icon' => 'trending-down',
                    'color' => '#8B5CF6'
                ]
            ],

            'top_discounted_products' => $topDiscountedProducts->map(function($item) {
                return [
                    'product_name' => $item->product_name,
                    'discount_count' => $item->discount_count,
                    'total_discount' => (float) $item->total_discount
                ];
            }),

            'discount_types' => $discountTypes->map(function($type, $index) use ($discountColors) {
                return [
                    'discount_type' => $type->discount_type,
                    'transaction_count' => $type->transaction_count,
                    'total_discount' => (float) $type->total_discount,
                    'color' => $discountColors[$index % count($discountColors)]
                ];
            }),

            'summary' => [
                'total_discounts' => $discountedSalesCount,
                'total_discount_amount' => (float) $totalDiscountAmount,
                'discount_rate' => $discountRate,
                'discount_percentage' => $discountPercentage,
                'average_discount' => (float) $averageDiscount,
                'currency' => $currency
            ],

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ],

            'chart_config' => [
                'type' => 'horizontal_bar',
                'title' => 'Top Discounted Products',
                'x_axis' => 'total_discount',
                'x_axis_label' => 'Total Discount (' . $currency . ')',
                'y_axis' => 'product_name',
                'y_axis_label' => 'Product',
                'bars' => [
                    [
                        'name' => 'Discount',
                        'data_key' => 'total_discount',
                        'color' => '#F59E0B',
                        'show_values' => true
                    ]
                ],
                'show_grid' => true,
                'show_legend' => false
            ]
        ];

        return successResponse('Discount usage summary retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get discount usage summary', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get discount usage summary', $e->getMessage());
    }
}

/**
 * Function 4 (Tab 4): Credit Sales Summary
 * Shows credit sales, outstanding amounts, and aging
 * Visualization: SUMMARY CARDS + AGING CHART + TABLE
 * Endpoint: GET /api/sales-dashboard/credit-sales-summary
 */
public function getCreditSalesSummary(Request $request)
{
    try {
        // ALWAYS use authenticated user's business (SECURITY)
        $businessId = $request->user()->business_id;

        // Default to user's primary branch, but allow override if provided
        $branchId = $request->input('branch_id', $request->user()->primary_branch_id);

        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());

        // Validate user can access this branch
        if ($branchId && !$request->user()->canAccessBranch($branchId)) {
            return errorResponse('You do not have access to this branch', 403);
        }

        // 🆕 Get currency from first sale's currency relationship
        $firstSale = Sale::with('currency')
            ->where('business_id', $businessId)
            ->whereNotNull('currency_id')
            ->first();

        $currency = $firstSale && $firstSale->currency
            ? $firstSale->currency->code
            : 'KES';

        // Get credit sales
        $creditSalesQuery = Sale::where('business_id', $businessId)
            ->where('is_credit_sale', true)
            ->whereIn('status', ['completed', 'pending'])
            ->whereDate('completed_at', '>=', $dateFrom)
            ->whereDate('completed_at', '<=', $dateTo);

        if ($branchId) {
            $creditSalesQuery->where('branch_id', $branchId);
        }

        $totalCreditSales = (clone $creditSalesQuery)->count();
        $totalCreditAmount = (clone $creditSalesQuery)->sum('total_amount');

        // Get outstanding credit
        $outstandingQuery = Sale::where('business_id', $businessId)
            ->where('is_credit_sale', true)
            ->where('payment_status', '!=', 'paid');

        if ($branchId) {
            $outstandingQuery->where('branch_id', $branchId);
        }

        $outstandingCount = (clone $outstandingQuery)->count();
        $outstandingAmount = (clone $outstandingQuery)->sum('total_amount');

        // Get overdue credit
        $overdueQuery = Sale::where('business_id', $businessId)
            ->where('is_credit_sale', true)
            ->where('payment_status', '!=', 'paid')
            ->where('credit_due_date', '<', Carbon::today());

        if ($branchId) {
            $overdueQuery->where('branch_id', $branchId);
        }

        $overdueCount = (clone $overdueQuery)->count();
        $overdueAmount = (clone $overdueQuery)->sum('total_amount');

        // Calculate collection rate
        $paidCreditSales = Sale::where('business_id', $businessId)
            ->where('is_credit_sale', true)
            ->where('payment_status', 'paid')
            ->count();

        $totalAllCreditSales = Sale::where('business_id', $businessId)
            ->where('is_credit_sale', true)
            ->count();

        $collectionRate = $totalAllCreditSales > 0
            ? round(($paidCreditSales / $totalAllCreditSales) * 100, 2)
            : 0;

        $averageCreditAmount = $totalCreditSales > 0
            ? round($totalCreditAmount / $totalCreditSales, 2)
            : 0;

        // Aging analysis
        $today = Carbon::today();

        $aging030 = (clone $outstandingQuery)
            ->where('credit_due_date', '>=', $today->copy()->subDays(30))
            ->sum('total_amount');

        $aging3160 = (clone $outstandingQuery)
            ->where('credit_due_date', '<', $today->copy()->subDays(30))
            ->where('credit_due_date', '>=', $today->copy()->subDays(60))
            ->sum('total_amount');

        $aging6190 = (clone $outstandingQuery)
            ->where('credit_due_date', '<', $today->copy()->subDays(60))
            ->where('credit_due_date', '>=', $today->copy()->subDays(90))
            ->sum('total_amount');

        $aging90plus = (clone $outstandingQuery)
            ->where('credit_due_date', '<', $today->copy()->subDays(90))
            ->sum('total_amount');

        // Top customers with outstanding credit
        $topCustomers = DB::table('sales')
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->select(
                'customers.id as customer_id',
                'customers.name as customer_name',
                'customers.customer_number',
                DB::raw('COUNT(sales.id) as credit_sales_count'),
                DB::raw('SUM(sales.total_amount) as total_outstanding')
            )
            ->where('sales.business_id', $businessId)
            ->where('sales.is_credit_sale', true)
            ->where('sales.payment_status', '!=', 'paid')
            ->when($branchId, function($q) use ($branchId) {
                $q->where('sales.branch_id', $branchId);
            })
            ->groupBy('customers.id', 'customers.name', 'customers.customer_number')
            ->orderByDesc('total_outstanding')
            ->limit(10)
            ->get();

        $data = [
            'summary_cards' => [
                [
                    'id' => 'total_credit_sales',
                    'title' => 'Total Credit Sales',
                    'value' => $totalCreditSales,
                    'subtitle' => 'In selected period',
                    'icon' => 'file-text',
                    'color' => '#3B82F6'
                ],
                [
                    'id' => 'outstanding_amount',
                    'title' => 'Outstanding Amount',
                    'value' => (float) $outstandingAmount,
                    'subtitle' => $outstandingCount . ' unpaid sales',
                    'icon' => 'alert-circle',
                    'color' => '#F59E0B'
                ],
                [
                    'id' => 'overdue_count',
                    'title' => 'Overdue Credits',
                    'value' => $overdueCount,
                    'subtitle' => $currency . ' ' . number_format($overdueAmount, 2) . ' overdue',
                    'icon' => 'alert-triangle',
                    'color' => '#EF4444'
                ],
                [
                    'id' => 'collection_rate',
                    'title' => 'Collection Rate',
                    'value' => $collectionRate . '%',
                    'subtitle' => 'Credits collected',
                    'icon' => 'check-circle',
                    'color' => '#10B981'
                ]
            ],

            'aging_analysis' => [
                [
                    'age_range' => '0-30 Days',
                    'amount' => (float) $aging030,
                    'percentage' => $outstandingAmount > 0
                        ? round(($aging030 / $outstandingAmount) * 100, 2)
                        : 0,
                    'color' => '#10B981'
                ],
                [
                    'age_range' => '31-60 Days',
                    'amount' => (float) $aging3160,
                    'percentage' => $outstandingAmount > 0
                        ? round(($aging3160 / $outstandingAmount) * 100, 2)
                        : 0,
                    'color' => '#3B82F6'
                ],
                [
                    'age_range' => '61-90 Days',
                    'amount' => (float) $aging6190,
                    'percentage' => $outstandingAmount > 0
                        ? round(($aging6190 / $outstandingAmount) * 100, 2)
                        : 0,
                    'color' => '#F59E0B'
                ],
                [
                    'age_range' => '90+ Days',
                    'amount' => (float) $aging90plus,
                    'percentage' => $outstandingAmount > 0
                        ? round(($aging90plus / $outstandingAmount) * 100, 2)
                        : 0,
                    'color' => '#EF4444'
                ]
            ],

            'top_customers' => $topCustomers->map(function($customer) {
                return [
                    'customer_id' => $customer->customer_id,
                    'customer_name' => $customer->customer_name,
                    'customer_number' => $customer->customer_number,
                    'credit_sales_count' => $customer->credit_sales_count,
                    'total_outstanding' => (float) $customer->total_outstanding
                ];
            }),

            'summary' => [
                'total_credit_sales' => $totalCreditSales,
                'total_credit_amount' => (float) $totalCreditAmount,
                'outstanding_count' => $outstandingCount,
                'outstanding_amount' => (float) $outstandingAmount,
                'overdue_count' => $overdueCount,
                'overdue_amount' => (float) $overdueAmount,
                'collection_rate' => $collectionRate,
                'average_credit_amount' => (float) $averageCreditAmount,
                'currency' => $currency
            ],

            'user_context' => [
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ],

            'chart_config' => [
                'type' => 'stacked_bar',
                'title' => 'Credit Aging Analysis',
                'x_axis' => 'age_range',
                'x_axis_label' => 'Age Range',
                'y_axis_label' => 'Outstanding Amount (' . $currency . ')',
                'bars' => [
                    [
                        'name' => 'Amount',
                        'data_key' => 'amount',
                        'color_key' => 'color',
                        'show_values' => true
                    ]
                ],
                'show_grid' => true,
                'show_legend' => true
            ]
        ];

        return successResponse('Credit sales summary retrieved successfully', $data);
    } catch (\Exception $e) {
        Log::error('Failed to get credit sales summary', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return queryErrorResponse('Failed to get credit sales summary', $e->getMessage());
    }
}
}
