<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SalesDashboardController extends Controller
{
 public function getSalesSummary(Request $request)
{
    try {
        $businessId = $request->input('business_id');
        $branchId = $request->input('branch_id');
        $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
        $dateTo = $request->input('date_to', Carbon::today()->toDateString());
        $status = $request->input('status'); // NEW: optional status filter

        // Default: exclude cancelled sales
        $query = Sale::whereIn('status', ['completed', 'pending']);

        // If specific status requested, filter by it
        if ($status) {
            $query = Sale::where('status', $status);
        }

        if ($businessId) {
            $query->where('business_id', $businessId);
        }

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $query->whereDate('completed_at', '>=', $dateFrom)
              ->whereDate('completed_at', '<=', $dateTo);

        $totalSales = $query->sum('total_amount');
        $totalTransactions = $query->count();
        $averageOrderValue = $totalTransactions > 0 ? $totalSales / $totalTransactions : 0;

        $firstSale = Sale::whereIn('status', ['completed', 'pending'])
            ->when($businessId, function($q) use ($businessId) {
                $q->where('business_id', $businessId);
            })
            ->first();
        $currency = $firstSale->currency ?? 'KES';

        // Add status breakdown
        $statusBreakdown = Sale::select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as total'))
            ->when($businessId, function($q) use ($businessId) {
                $q->where('business_id', $businessId);
            })
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
            })
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
    /**
     * Function 2: Get Sales Comparison
     * Endpoint: GET /api/sales-dashboard/sales-comparison
     */
    public function getSalesComparison(Request $request)
    {
        try {
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
            $dateTo = $request->input('date_to', Carbon::today()->toDateString());

            // Calculate previous period dates
            $currentStart = Carbon::parse($dateFrom);
            $currentEnd = Carbon::parse($dateTo);
            $daysDiff = $currentStart->diffInDays($currentEnd) + 1;

            $previousStart = $currentStart->copy()->subDays($daysDiff);
            $previousEnd = $currentEnd->copy()->subDays($daysDiff);

            // Current period sales
            $currentQuery = Sale::where('status', 'completed');
            if ($businessId) $currentQuery->where('business_id', $businessId);
            if ($branchId) $currentQuery->where('branch_id', $branchId);
            $currentQuery->whereBetween(DB::raw('DATE(created_at)'), [$dateFrom, $dateTo]);
            $currentSales = $currentQuery->sum('total_amount');

            // Previous period sales
            $previousQuery = Sale::where('status', 'completed');
            if ($businessId) $previousQuery->where('business_id', $businessId);
            if ($branchId) $previousQuery->where('branch_id', $branchId);
            $previousQuery->whereBetween(DB::raw('DATE(created_at)'), [
                $previousStart->toDateString(),
                $previousEnd->toDateString()
            ]);
            $previousSales = $previousQuery->sum('total_amount');

            // Calculate growth
            $growthAmount = $currentSales - $previousSales;
            $growthPercentage = $previousSales > 0
                ? (($currentSales - $previousSales) / $previousSales) * 100
                : 0;
            $trend = $growthAmount >= 0 ? 'up' : 'down';

            $data = [
                'current_period' => [
                    'total_sales' => (float) $currentSales,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo
                ],
                'previous_period' => [
                    'total_sales' => (float) $previousSales,
                    'date_from' => $previousStart->toDateString(),
                    'date_to' => $previousEnd->toDateString()
                ],
                'growth_amount' => (float) $growthAmount,
                'growth_percentage' => round($growthPercentage, 2),
                'trend' => $trend
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

    /**
     * Function 3: Get Top Selling Products
     * Endpoint: GET /api/sales-dashboard/top-selling-products
     */
    public function getTopSellingProducts(Request $request)
    {
        try {
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
            $dateTo = $request->input('date_to', Carbon::today()->toDateString());
            $limit = $request->input('limit', 5);

            $query = SaleItem::select(
                'sale_items.product_id',
                'products.name as product_name',
                DB::raw('SUM(sale_items.quantity) as quantity_sold'),
                DB::raw('SUM(sale_items.line_total) as total_revenue')
            )
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->where('sales.status', 'completed');

            if ($businessId) {
                $query->where('sales.business_id', $businessId);
            }

            if ($branchId) {
                $query->where('sales.branch_id', $branchId);
            }

            $query->whereBetween(DB::raw('DATE(sales.created_at)'), [$dateFrom, $dateTo])
                ->groupBy('sale_items.product_id', 'products.name')
                ->orderByDesc('total_revenue')
                ->limit($limit);

            $products = $query->get()->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity_sold' => (float) $item->quantity_sold,
                    'total_revenue' => (float) $item->total_revenue
                ];
            });

            $data = [
                'products' => $products
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

    /**
     * Function 4: Get Sales By Payment Method
     * Endpoint: GET /api/sales-dashboard/sales-by-payment-method
     */
    public function getSalesByPaymentMethod(Request $request)
    {
        try {
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $dateFrom = $request->input('date_from', Carbon::today()->toDateString());
            $dateTo = $request->input('date_to', Carbon::today()->toDateString());

            $query = Payment::select(
                'payment_methods.name as payment_method',
                DB::raw('COUNT(payments.id) as transaction_count'),
                DB::raw('SUM(payments.amount) as total_amount')
            )
            ->join('payment_methods', 'payments.payment_method_id', '=', 'payment_methods.id')
            ->where('payments.status', 'completed')
            ->where('payments.payment_type', 'payment');

            if ($businessId) {
                $query->where('payments.business_id', $businessId);
            }

            if ($branchId) {
                $query->where('payments.branch_id', $branchId);
            }

            $query->whereBetween(DB::raw('DATE(payments.payment_date)'), [$dateFrom, $dateTo])
                ->groupBy('payment_methods.name')
                ->orderByDesc('total_amount');

            $paymentMethods = $query->get();

            $totalAmount = $paymentMethods->sum('total_amount');

            $data = [
                'payment_methods' => $paymentMethods->map(function ($item) use ($totalAmount) {
                    return [
                        'payment_method' => $item->payment_method,
                        'transaction_count' => $item->transaction_count,
                        'total_amount' => (float) $item->total_amount,
                        'percentage' => $totalAmount > 0
                            ? round(($item->total_amount / $totalAmount) * 100, 2)
                            : 0
                    ];
                }),
                'total_amount' => (float) $totalAmount
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
}