<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Product;
use App\Models\Category;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

// FPDF
require_once(base_path('vendor/setasign/fpdf/fpdf.php'));

class InventoryStockPdfReportController extends Controller
{
    // Color scheme - Matisse theme (matches your brand)
    private $colors = [
        'matisse' => [36, 116, 172],      // #2474ac - Primary blue
        'sun' => [252, 172, 28],          // #fcac1c - Accent orange
        'hippie_blue' => [88, 154, 175],  // #589aaf - Secondary blue
        'text_dark' => [51, 51, 51],      // #333333 - Dark text
        'text_light' => [102, 102, 102],  // #666666 - Light text
        'success' => [40, 167, 69],       // #28a745 - Green
        'danger' => [220, 53, 69],        // #dc3545 - Red
        'warning' => [255, 193, 7],       // #ffc107 - Yellow
        'info' => [23, 162, 184],         // #17a2b8 - Info blue
        'gray_light' => [248, 249, 250],  // #f8f9fa - Light gray
        'gray_medium' => [233, 236, 239], // #e9ecef - Medium gray
        'white' => [255, 255, 255]        // #ffffff - White
    ];

    private $logoPath;

    public function __construct()
    {
        $this->logoPath = public_path('images/company/logo.png');
    }

    /**
     * =====================================================
     * REPORT 1: INVENTORY VALUATION REPORT
     * =====================================================
     * Calculate total inventory value with different valuation methods
     */
    public function generateInventoryValuationReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'valuation_date' => 'nullable|date',
                'branch_id' => 'nullable|exists:branches,id',
                'business_id' => 'nullable|exists:businesses,id',
                'category_id' => 'nullable|exists:categories,id',
                'product_id' => 'nullable|exists:products,id',
                'valuation_method' => 'nullable|in:fifo,lifo,weighted_average,standard_cost',
                'currency_code' => 'nullable|string|size:3',
                'include_zero_stock' => 'nullable|boolean',
                'search' => 'nullable|string',
                'min_value' => 'nullable|numeric',
                'max_value' => 'nullable|numeric',
                'sort_by' => 'nullable|in:value,quantity,product_name',
                'sort_order' => 'nullable|in:asc,desc',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getInventoryValuationData($request);
            return $this->generateInventoryValuationPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate inventory valuation report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate inventory valuation report', $e->getMessage());
        }
    }

    /**
     * Get inventory valuation data
     */
    private function getInventoryValuationData(Request $request)
    {
        $user = Auth::user();

        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        $businessId = $request->business_id ?? $user->business_id;
        $branchId = $request->branch_id ?? $user->primary_branch_id;
        $valuationDate = $request->valuation_date ?? date('Y-m-d');
        $valuationMethod = $request->valuation_method ?? 'weighted_average';

        // Build query
        $query = Stock::with(['product.category', 'product.unit', 'branch'])
            ->where('business_id', $businessId);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        if ($request->product_id) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->category_id) {
            $query->whereHas('product', function($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        if ($request->search) {
            $query->whereHas('product', function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('sku', 'like', "%{$request->search}%")
                  ->orWhere('barcode', 'like', "%{$request->search}%");
            });
        }

        if (!$request->include_zero_stock) {
            $query->where('quantity', '>', 0);
        }

        $stocks = $query->get();

        // Apply valuation method
        $stocks = $stocks->map(function($stock) use ($valuationMethod) {
            $stock->valuation_cost = $this->calculateValuationCost($stock, $valuationMethod);
            $stock->valuation_value = $stock->quantity * $stock->valuation_cost;
            return $stock;
        });

        // Filter by value range
        if ($request->min_value) {
            $stocks = $stocks->filter(fn($s) => $s->valuation_value >= $request->min_value);
        }
        if ($request->max_value) {
            $stocks = $stocks->filter(fn($s) => $s->valuation_value <= $request->max_value);
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'value';
        $sortOrder = $request->sort_order ?? 'desc';

        $stocks = $stocks->sortBy(function($stock) use ($sortBy) {
            switch ($sortBy) {
                case 'value': return $stock->valuation_value;
                case 'quantity': return $stock->quantity;
                case 'product_name': return $stock->product->name;
                default: return $stock->valuation_value;
            }
        }, SORT_REGULAR, $sortOrder === 'desc');

        // Summary metrics
        $summary = [
            'total_products' => $stocks->count(),
            'total_quantity' => $stocks->sum('quantity'),
            'total_valuation' => $stocks->sum('valuation_value'),
            'average_unit_cost' => $stocks->avg('valuation_cost'),
            'highest_value_item' => $stocks->sortByDesc('valuation_value')->first(),
            'lowest_value_item' => $stocks->where('quantity', '>', 0)->sortBy('valuation_value')->first(),
            'total_categories' => $stocks->pluck('product.category_id')->unique()->count(),
        ];

        // Category breakdown
        $categoryBreakdown = $stocks->groupBy('product.category.name')->map(function($group) {
            return [
                'name' => $group->first()->product->category->name ?? 'Uncategorized',
                'product_count' => $group->count(),
                'total_quantity' => $group->sum('quantity'),
                'total_value' => $group->sum('valuation_value'),
                'avg_unit_cost' => $group->avg('valuation_cost'),
            ];
        })->sortByDesc('total_value')->values();

        // Top 10 highest value items
        $topValueItems = $stocks->sortByDesc('valuation_value')->take(10);

        // Valuation method breakdown
        $methodBreakdown = [
            'method_used' => ucfirst(str_replace('_', ' ', $valuationMethod)),
            'description' => $this->getValuationMethodDescription($valuationMethod),
        ];

        return [
            'stocks' => $stocks,
            'summary' => $summary,
            'category_breakdown' => $categoryBreakdown,
            'top_value_items' => $topValueItems,
            'method_breakdown' => $methodBreakdown,
            'valuation_date' => $valuationDate,
            'valuation_method' => $valuationMethod,
            'currency' => $currencySymbol,
            'currency_code' => $currencyCode,
            'business' => $user->business,
            'branch' => $branchId ? $user->primaryBranch : null,
            'generated_by' => $user->name,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Calculate valuation cost based on method
     */
    private function calculateValuationCost($stock, $method)
    {
        switch ($method) {
            case 'fifo':
            case 'lifo':
            case 'weighted_average':
                return $stock->unit_cost ?? 0;
            case 'standard_cost':
                return $stock->product->standard_cost ?? $stock->unit_cost ?? 0;
            default:
                return $stock->unit_cost ?? 0;
        }
    }

    /**
     * Get valuation method description
     */
    private function getValuationMethodDescription($method)
    {
        $descriptions = [
            'fifo' => 'First In, First Out - Values inventory based on oldest costs',
            'lifo' => 'Last In, First Out - Values inventory based on newest costs',
            'weighted_average' => 'Weighted Average - Uses average cost of all units',
            'standard_cost' => 'Standard Cost - Uses predetermined standard costs',
        ];
        return $descriptions[$method] ?? 'Standard inventory valuation';
    }

    /**
     * Generate Inventory Valuation PDF
     */
    private function generateInventoryValuationPDF($data)
    {
        try {
            $pdf = new \FPDF('L', 'mm', 'A4');
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->AddPage();

            $matisse = $this->colors['matisse'];
            $sun = $this->colors['sun'];
            $hippieBlue = $this->colors['hippie_blue'];
            $success = $this->colors['success'];

            // ===== HEADER =====
            $this->addHeader($pdf, 'INVENTORY VALUATION REPORT', $data, $matisse);

            // ===== SUMMARY BOXES =====
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Cell(0, 7, 'VALUATION SUMMARY', 0, 1);
            $pdf->Ln(2);

            $boxW = 65;
            $boxH = 22;
            $gap = 5;
            $startX = 15;
            $y = $pdf->GetY();

            // Row 1
            $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL VALUATION', $data['currency'] . ' ' . number_format($data['summary']['total_valuation'], 2), $matisse);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'TOTAL PRODUCTS', number_format($data['summary']['total_products']), $sun);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'TOTAL QUANTITY', number_format($data['summary']['total_quantity'], 1), $hippieBlue);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'AVG UNIT COST', $data['currency'] . ' ' . number_format($data['summary']['average_unit_cost'], 2), $success);

            $pdf->SetY($y + $boxH + 8);

            // ===== VALUATION METHOD INFO =====
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
            $pdf->Cell(0, 6, 'VALUATION METHOD', 0, 1);
            $pdf->Ln(1);

            $pdf->SetFillColor(255, 250, 230);
            $pdf->SetDrawColor($sun[0], $sun[1], $sun[2]);
            $pdf->Rect(15, $pdf->GetY(), 270, 12, 'D');
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(270, 6, 'Method: ' . $data['method_breakdown']['method_used'], 0, 1, 'L');
            $pdf->SetFont('Arial', 'I', 8);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Cell(270, 6, $data['method_breakdown']['description'], 0, 1, 'L');
            $pdf->Ln(5);

            // ===== CATEGORY BREAKDOWN =====
            if ($data['category_breakdown']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->Cell(0, 6, 'VALUATION BY CATEGORY', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 9);

                $pdf->Cell(80, 8, 'Category', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Products', 1, 0, 'C', true);
                $pdf->Cell(50, 8, 'Quantity', 1, 0, 'C', true);
                $pdf->Cell(50, 8, 'Avg Unit Cost', 1, 0, 'C', true);
                $pdf->Cell(50, 8, 'Total Value (' . $data['currency'] . ')', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 8);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                foreach ($data['category_breakdown'] as $category) {
                    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                    $pdf->Cell(80, 6, substr($category['name'], 0, 30), 1, 0, 'L', $fill);
                    $pdf->Cell(40, 6, number_format($category['product_count']), 1, 0, 'C', $fill);
                    $pdf->Cell(50, 6, number_format($category['total_quantity'], 2), 1, 0, 'R', $fill);
                    $pdf->Cell(50, 6, $data['currency'] . ' ' . number_format($category['avg_unit_cost'], 2), 1, 0, 'R', $fill);
                    $pdf->Cell(50, 6, number_format($category['total_value'], 2), 1, 1, 'R', $fill);
                    $fill = !$fill;
                }
                $pdf->Ln(5);
            }

            // ===== TOP VALUE ITEMS =====
            if ($data['top_value_items']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
                $pdf->Cell(0, 6, 'TOP 10 HIGHEST VALUE ITEMS', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($sun[0], $sun[1], $sun[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);

                $pdf->Cell(10, 8, '#', 1, 0, 'C', true);
                $pdf->Cell(20, 8, 'SKU', 1, 0, 'C', true);
                $pdf->Cell(70, 8, 'Product Name', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Category', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Quantity', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Unit Cost', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Branch', 1, 0, 'C', true);
                $pdf->Cell(20, 8, 'Total Value', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                $rank = 1;
                foreach ($data['top_value_items'] as $item) {
                    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                    $pdf->Cell(10, 6, $rank, 1, 0, 'C', $fill);
                    $pdf->Cell(20, 6, substr($item->product->sku, 0, 8), 1, 0, 'C', $fill);
                    $pdf->Cell(70, 6, substr($item->product->name, 0, 35), 1, 0, 'L', $fill);
                    $pdf->Cell(40, 6, substr($item->product->category->name ?? 'N/A', 0, 18), 1, 0, 'L', $fill);
                    $pdf->Cell(35, 6, number_format($item->quantity, 1), 1, 0, 'R', $fill);
                    $pdf->Cell(35, 6, $data['currency'] . number_format($item->valuation_cost, 2), 1, 0, 'R', $fill);
                    $pdf->Cell(40, 6, substr($item->branch->name, 0, 18), 1, 0, 'L', $fill);
                    $pdf->Cell(20, 6, number_format($item->valuation_value, 0), 1, 1, 'R', $fill);
                    $fill = !$fill;
                    $rank++;
                }
                $pdf->Ln(5);
            }

            // ===== DETAILED INVENTORY TABLE =====
            if ($data['stocks']->isEmpty()) {
                $pdf->SetFont('Arial', 'I', 11);
                $pdf->SetTextColor(128, 128, 128);
                $pdf->Cell(0, 10, 'No inventory items found matching the criteria.', 0, 1, 'C');
            } else {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->Cell(0, 6, 'DETAILED INVENTORY VALUATION', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 7);

                $pdf->Cell(20, 8, 'SKU', 1, 0, 'C', true);
                $pdf->Cell(70, 8, 'Product Name', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Category', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Branch', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Quantity', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Unit Cost', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Unit', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Total Value', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 6);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                foreach ($data['stocks']->take(50) as $stock) {
                    $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);
                    $pdf->Cell(20, 6, substr($stock->product->sku, 0, 10), 1, 0, 'C', $fill);
                    $pdf->Cell(70, 6, substr($stock->product->name, 0, 35), 1, 0, 'L', $fill);
                    $pdf->Cell(40, 6, substr($stock->product->category->name ?? 'N/A', 0, 18), 1, 0, 'L', $fill);
                    $pdf->Cell(35, 6, substr($stock->branch->name, 0, 16), 1, 0, 'L', $fill);
                    $pdf->Cell(25, 6, number_format($stock->quantity, 1), 1, 0, 'R', $fill);
                    $pdf->Cell(30, 6, $data['currency'] . number_format($stock->valuation_cost, 2), 1, 0, 'R', $fill);
                    $pdf->Cell(25, 6, $stock->product->unit->abbreviation ?? 'pcs', 1, 0, 'C', $fill);
                    $pdf->Cell(25, 6, number_format($stock->valuation_value, 0), 1, 1, 'R', $fill);
                    $fill = !$fill;

                    if ($pdf->GetY() > 180) {
                        $pdf->AddPage();
                        $this->repeatInventoryTableHeader($pdf, $data['currency'], $matisse);
                    }
                }

                if ($data['stocks']->count() > 50) {
                    $pdf->Ln(2);
                    $pdf->SetFont('Arial', 'I', 8);
                    $pdf->SetTextColor(100, 100, 100);
                    $pdf->Cell(0, 5, 'Note: Showing first 50 items. Total items: ' . number_format($data['stocks']->count()), 0, 1, 'C');
                }
            }

            // ===== FOOTER =====
            $this->addFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

            $filename = 'inventory_valuation_report_' . date('Y-m-d') . '.pdf';
            return response()->stream(
                fn() => print($pdf->Output('S')),
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="' . $filename . '"'
                ]
            );

        } catch (\Exception $e) {
            Log::error('PDF Generation Error: ' . $e->getMessage());
            return serverErrorResponse('Failed to generate PDF', $e->getMessage());
        }
    }

    /**
     * =====================================================
     * REPORT 2: STOCK ADJUSTMENT REPORT
     * =====================================================
     * Track all stock adjustments (increases/decreases)
     */
    public function generateStockAdjustmentReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'branch_id' => 'nullable|exists:branches,id',
                'business_id' => 'nullable|exists:businesses,id',
                'product_id' => 'nullable|exists:products,id',
                'category_id' => 'nullable|exists:categories,id',
                'adjustment_type' => 'nullable|in:increase,decrease,both',
                'reason' => 'nullable|in:damaged,expired,theft,count_error,lost,found,other',
                'status' => 'nullable|in:pending,approved,rejected',
                'user_id' => 'nullable|exists:users,id',
                'approved_by' => 'nullable|exists:users,id',
                'search' => 'nullable|string',
                'min_quantity' => 'nullable|numeric',
                'sort_by' => 'nullable|in:date,quantity,value,product_name',
                'sort_order' => 'nullable|in:asc,desc',
                'currency_code' => 'nullable|string|size:3',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getStockAdjustmentReportData($request);
            return $this->generateStockAdjustmentPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate stock adjustment report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate stock adjustment report', $e->getMessage());
        }
    }

    /**
     * Get stock adjustment data with comprehensive filtering
     */
    /**
 * Get stock adjustment data with comprehensive filtering
 */
private function getStockAdjustmentReportData(Request $request)
{
    $user = Auth::user();

    $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
    $currency = Currency::where('code', $currencyCode)->first();
    $currencySymbol = $currency ? $currency->symbol : '$';

    $businessId = $request->business_id ?? $user->business_id;
    $branchId = $request->branch_id ?? $user->primary_branch_id;

    // Build comprehensive query
    $query = StockAdjustment::with(['product.category', 'branch', 'adjustedBy', 'approvedBy'])
        ->where('business_id', $businessId)
        ->whereBetween('created_at', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);

    // Apply filters
    if ($branchId) {
        $query->where('branch_id', $branchId);
    }

    if ($request->product_id) {
        $query->where('product_id', $request->product_id);
    }

    if ($request->category_id) {
        $query->whereHas('product', function($q) use ($request) {
            $q->where('category_id', $request->category_id);
        });
    }

    if ($request->adjustment_type && $request->adjustment_type !== 'both') {
        if ($request->adjustment_type === 'increase') {
            $query->where('quantity_adjusted', '>', 0);
        } elseif ($request->adjustment_type === 'decrease') {
            $query->where('quantity_adjusted', '<', 0);
        }
    }

    if ($request->reason) {
        $query->where('reason', $request->reason);
    }

    if ($request->status) {
        if ($request->status === 'approved') {
            $query->whereNotNull('approved_at');
        } elseif ($request->status === 'pending') {
            $query->whereNull('approved_at');
        }
    }

    if ($request->user_id) {
        $query->where('adjusted_by', $request->user_id);
    }

    if ($request->approved_by) {
        $query->where('approved_by', $request->approved_by);
    }

    if ($request->search) {
        $query->where(function($q) use ($request) {
            $q->whereHas('product', function($pq) use ($request) {
                $pq->where('name', 'like', "%{$request->search}%")
                   ->orWhere('sku', 'like', "%{$request->search}%");
            })->orWhere('notes', 'like', "%{$request->search}%");
        });
    }

    if ($request->min_quantity) {
        $query->where(DB::raw('ABS(quantity_adjusted)'), '>=', $request->min_quantity);
    }

    // Sorting
    $sortBy = $request->sort_by ?? 'date';
    $sortOrder = $request->sort_order ?? 'desc';

    if ($sortBy === 'product_name') {
        $query->join('products', 'stock_adjustments.product_id', '=', 'products.id')
              ->orderBy('products.name', $sortOrder)
              ->select('stock_adjustments.*');
    } else {
        $orderColumn = $sortBy === 'date' ? 'created_at' : $sortBy;
        $query->orderBy($orderColumn, $sortOrder);
    }

    $adjustments = $query->get();

    // Calculate summary metrics
    $summary = [
        'total_adjustments' => $adjustments->count(),
        'approved_count' => $adjustments->whereNotNull('approved_at')->count(),
        'pending_count' => $adjustments->whereNull('approved_at')->count(),
        'rejected_count' => 0, // Your model doesn't track rejected
        'increase_count' => $adjustments->where('quantity_adjusted', '>', 0)->count(),
        'decrease_count' => $adjustments->where('quantity_adjusted', '<', 0)->count(),
        'total_increase_qty' => $adjustments->where('quantity_adjusted', '>', 0)->sum('quantity_adjusted'),
        'total_decrease_qty' => abs($adjustments->where('quantity_adjusted', '<', 0)->sum('quantity_adjusted')),
        'net_adjustment' => $adjustments->sum('quantity_adjusted'),
        'total_cost_impact' => $adjustments->sum('cost_impact'),
        'unique_products' => $adjustments->pluck('product_id')->unique()->count(),
    ];

    // Reason breakdown
    $reasonBreakdown = $adjustments->groupBy('reason')->map(function($group) {
        return [
            'reason' => ucfirst(str_replace('_', ' ', $group->first()->reason ?? 'Unknown')),
            'count' => $group->count(),
            'total_quantity' => $group->sum('quantity_adjusted'),
            'increase_count' => $group->where('quantity_adjusted', '>', 0)->count(),
            'decrease_count' => $group->where('quantity_adjusted', '<', 0)->count(),
        ];
    })->values();

    // Status breakdown
    $statusBreakdown = $adjustments->groupBy(function($item) {
        return $item->approved_at ? 'approved' : 'pending';
    })->map(function($group, $key) {
        return [
            'status' => ucfirst($key),
            'count' => $group->count(),
            'total_quantity' => abs($group->sum('quantity_adjusted')),
        ];
    })->values();

    // Top adjusted products
    $topAdjustedProducts = $adjustments->groupBy('product_id')->map(function($group) {
        return [
            'product' => $group->first()->product,
            'adjustment_count' => $group->count(),
            'total_quantity_change' => $group->sum('quantity_adjusted'),
            'net_change' => $group->sum('quantity_adjusted'),
        ];
    })->sortByDesc('adjustment_count')->take(10)->values();

    return [
        'adjustments' => $adjustments,
        'summary' => $summary,
        'reason_breakdown' => $reasonBreakdown,
        'status_breakdown' => $statusBreakdown,
        'top_adjusted_products' => $topAdjustedProducts,
        'currency' => $currencySymbol,
        'currency_code' => $currencyCode,
        'period' => [
            'start' => $request->start_date,
            'end' => $request->end_date,
        ],
        'business' => $user->business,
        'branch' => $branchId ? $user->primaryBranch : null,
        'generated_by' => $user->name,
        'generated_at' => now()->format('Y-m-d H:i:s'),
    ];
}

    /**
     * Generate Stock Adjustment PDF Report
     */
    private function generateStockAdjustmentPDF($data)
    {
        try {
            $pdf = new \FPDF('L', 'mm', 'A4');
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->AddPage();

            $matisse = $this->colors['matisse'];
            $sun = $this->colors['sun'];
            $hippieBlue = $this->colors['hippie_blue'];
            $success = $this->colors['success'];
            $danger = $this->colors['danger'];
            $warning = $this->colors['warning'];

            // ===== HEADER =====
            $this->addHeader($pdf, 'STOCK ADJUSTMENT REPORT', $data, $matisse);

            // ===== SUMMARY BOXES =====
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Cell(0, 7, 'ADJUSTMENT SUMMARY', 0, 1);
            $pdf->Ln(2);

            $boxW = 65;
            $boxH = 22;
            $gap = 5;
            $startX = 15;
            $y = $pdf->GetY();

            // Row 1 - 4 boxes
            $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL ADJUSTMENTS', number_format($data['summary']['total_adjustments']), $matisse);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'APPROVED', number_format($data['summary']['approved_count']), $success);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'PENDING', number_format($data['summary']['pending_count']), $warning);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'REJECTED', number_format($data['summary']['rejected_count']), $danger);

            // Row 2 - 4 boxes
            $y += $boxH + $gap;
            $increaseText = number_format($data['summary']['increase_count']) . ' adj' . "\n" . '+' . number_format($data['summary']['total_increase_qty'], 1) . ' qty';
            $decreaseText = number_format($data['summary']['decrease_count']) . ' adj' . "\n" . '-' . number_format($data['summary']['total_decrease_qty'], 1) . ' qty';

            $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'INCREASES', number_format($data['summary']['increase_count']) . ' (' . number_format($data['summary']['total_increase_qty'], 0) . ')', $success);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'DECREASES', number_format($data['summary']['decrease_count']) . ' (' . number_format($data['summary']['total_decrease_qty'], 0) . ')', $danger);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'NET ADJUSTMENT', number_format($data['summary']['net_adjustment'], 1), $hippieBlue);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'COST IMPACT', $data['currency'] . ' ' . number_format($data['summary']['total_cost_impact'], 0), $sun);

            $pdf->SetY($y + $boxH + 8);

            // ===== REASON BREAKDOWN =====
            if ($data['reason_breakdown']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->Cell(0, 6, 'BREAKDOWN BY REASON', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 9);

                $pdf->Cell(80, 8, 'Reason', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Count', 1, 0, 'C', true);
                $pdf->Cell(45, 8, 'Total Qty Change', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Increases', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Decreases', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 8);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                foreach ($data['reason_breakdown'] as $reason) {
                    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                    $pdf->Cell(80, 6, substr($reason['reason'], 0, 30), 1, 0, 'L', $fill);
                    $pdf->Cell(40, 6, number_format($reason['count']), 1, 0, 'C', $fill);
                    $pdf->Cell(45, 6, number_format($reason['total_quantity'], 2), 1, 0, 'R', $fill);
                    $pdf->Cell(35, 6, number_format($reason['increase_count']), 1, 0, 'C', $fill);
                    $pdf->Cell(35, 6, number_format($reason['decrease_count']), 1, 1, 'C', $fill);
                    $fill = !$fill;
                }
                $pdf->Ln(5);
            }

            // ===== STATUS BREAKDOWN =====
            if ($data['status_breakdown']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
                $pdf->Cell(0, 6, 'BREAKDOWN BY STATUS', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($sun[0], $sun[1], $sun[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 9);

                $pdf->Cell(100, 8, 'Status', 1, 0, 'C', true);
                $pdf->Cell(65, 8, 'Count', 1, 0, 'C', true);
                $pdf->Cell(70, 8, 'Total Quantity', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 8);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                foreach ($data['status_breakdown'] as $status) {
                    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                    $pdf->Cell(100, 6, $status['status'], 1, 0, 'L', $fill);
                    $pdf->Cell(65, 6, number_format($status['count']), 1, 0, 'C', $fill);
                    $pdf->Cell(70, 6, number_format($status['total_quantity'], 2), 1, 1, 'R', $fill);
                    $fill = !$fill;
                }
                $pdf->Ln(5);
            }

            // ===== TOP ADJUSTED PRODUCTS =====
            if ($data['top_adjusted_products']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($hippieBlue[0], $hippieBlue[1], $hippieBlue[2]);
                $pdf->Cell(0, 6, 'TOP 10 MOST ADJUSTED PRODUCTS', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($hippieBlue[0], $hippieBlue[1], $hippieBlue[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);

                $pdf->Cell(10, 8, '#', 1, 0, 'C', true);
                $pdf->Cell(80, 8, 'Product Name', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'SKU', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Adj Count', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Net Change', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Total Qty', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                $rank = 1;
                foreach ($data['top_adjusted_products'] as $item) {
                    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                    $pdf->Cell(10, 6, $rank, 1, 0, 'C', $fill);
                    $pdf->Cell(80, 6, substr($item['product']->name ?? 'Unknown', 0, 35), 1, 0, 'L', $fill);
                    $pdf->Cell(30, 6, substr($item['product']->sku ?? 'N/A', 0, 12), 1, 0, 'C', $fill);
                    $pdf->Cell(40, 6, number_format($item['adjustment_count']), 1, 0, 'C', $fill);
                    $pdf->Cell(40, 6, number_format($item['net_change'], 1), 1, 0, 'R', $fill);
                    $pdf->Cell(35, 6, number_format($item['total_quantity_change'], 1), 1, 1, 'R', $fill);
                    $fill = !$fill;
                    $rank++;
                }
                $pdf->Ln(5);
            }

            // ===== DETAILED ADJUSTMENTS TABLE =====
            if ($data['adjustments']->isEmpty()) {
                $pdf->SetFont('Arial', 'I', 11);
                $pdf->SetTextColor(128, 128, 128);
                $pdf->Cell(0, 10, 'No stock adjustments found for this period.', 0, 1, 'C');
            } else {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->Cell(0, 6, 'DETAILED ADJUSTMENT HISTORY', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 7);

                $pdf->Cell(22, 8, 'Date', 1, 0, 'C', true);
                $pdf->Cell(50, 8, 'Product', 1, 0, 'C', true);
                $pdf->Cell(28, 8, 'Branch', 1, 0, 'C', true);
                $pdf->Cell(22, 8, 'Qty Change', 1, 0, 'C', true);
                $pdf->Cell(20, 8, 'Before', 1, 0, 'C', true);
                $pdf->Cell(20, 8, 'After', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Reason', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Status', 1, 0, 'C', true);
                $pdf->Cell(28, 8, 'User', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Approved By', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 6);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                foreach ($data['adjustments']->take(50) as $adjustment) {
                    $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);

                    $date = $adjustment->created_at->format('m/d H:i');
                    $productName = substr($adjustment->product->name ?? 'Unknown', 0, 22);
                    $branchName = substr($adjustment->branch->name ?? 'N/A', 0, 14);
                    $qtyChange = ($adjustment->quantity_change >= 0 ? '+' : '') . number_format($adjustment->quantity_change, 1);
                    $reason = substr(str_replace('_', ' ', $adjustment->reason ?? 'N/A'), 0, 15);
                    $status = ucfirst($adjustment->status ?? 'N/A');
                    $userName = substr($adjustment->user->name ?? 'System', 0, 14);
                    $approvedBy = substr($adjustment->approvedBy->name ?? '-', 0, 14);

                    $pdf->Cell(22, 6, $date, 1, 0, 'C', $fill);
                    $pdf->Cell(50, 6, $productName, 1, 0, 'L', $fill);
                    $pdf->Cell(28, 6, $branchName, 1, 0, 'L', $fill);
                    $pdf->Cell(22, 6, $qtyChange, 1, 0, 'R', $fill);
                    $pdf->Cell(20, 6, number_format($adjustment->quantity_before ?? 0, 1), 1, 0, 'R', $fill);
                    $pdf->Cell(20, 6, number_format($adjustment->quantity_after ?? 0, 1), 1, 0, 'R', $fill);
                    $pdf->Cell(30, 6, $reason, 1, 0, 'L', $fill);
                    $pdf->Cell(25, 6, $status, 1, 0, 'C', $fill);
                    $pdf->Cell(28, 6, $userName, 1, 0, 'L', $fill);
                    $pdf->Cell(30, 6, $approvedBy, 1, 1, 'L', $fill);

                    $fill = !$fill;

                    if ($pdf->GetY() > 180) {
                        $pdf->AddPage();
                        $this->repeatAdjustmentTableHeader($pdf, $matisse);
                    }
                }

                if ($data['adjustments']->count() > 50) {
                    $pdf->Ln(2);
                    $pdf->SetFont('Arial', 'I', 8);
                    $pdf->SetTextColor(100, 100, 100);
                    $pdf->Cell(0, 5, 'Note: Showing first 50 adjustments. Total adjustments: ' . number_format($data['adjustments']->count()), 0, 1, 'C');
                }
            }

            // ===== FOOTER =====
            $this->addFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

            $filename = 'stock_adjustment_report_' . date('Y-m-d') . '.pdf';
            return response()->stream(
                fn() => print($pdf->Output('S')),
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="' . $filename . '"'
                ]
            );

        } catch (\Exception $e) {
            Log::error('PDF Generation Error: ' . $e->getMessage());
            return serverErrorResponse('Failed to generate PDF', $e->getMessage());
        }
    }

    /**
     * =====================================================
     * REPORT 3: STOCK TRANSFER REPORT
     * =====================================================
     * Track stock transfers between branches
     */
    public function generateStockTransferReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'from_branch_id' => 'nullable|exists:branches,id',
                'to_branch_id' => 'nullable|exists:branches,id',
                'business_id' => 'nullable|exists:businesses,id',
                'product_id' => 'nullable|exists:products,id',
                'category_id' => 'nullable|exists:categories,id',
                'status' => 'nullable|in:pending,approved,sent,received,cancelled',
                'transfer_type' => 'nullable|in:all,inbound,outbound',
                'user_id' => 'nullable|exists:users,id',
                'approved_by' => 'nullable|exists:users,id',
                'search' => 'nullable|string',
                'min_quantity' => 'nullable|numeric',
                'include_cancelled' => 'nullable|boolean',
                'sort_by' => 'nullable|in:date,quantity,product_name,status',
                'sort_order' => 'nullable|in:asc,desc',
                'currency_code' => 'nullable|string|size:3',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getStockTransferReportData($request);
            return $this->generateStockTransferPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate stock transfer report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate stock transfer report', $e->getMessage());
        }
    }
  /**
 * Get stock transfer data with comprehensive filtering
 */
private function getStockTransferReportData(Request $request)
{
    $user = Auth::user();

    $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
    $currency = Currency::where('code', $currencyCode)->first();
    $currencySymbol = $currency ? $currency->symbol : '$';

    $businessId = $request->business_id ?? $user->business_id;

    // Build comprehensive query
    $query = StockTransfer::with([
        'items.product.category',
        'fromBranch',
        'toBranch',
        'initiatedBy',
        'approvedBy'
    ])
    ->where('business_id', $businessId)
    ->whereBetween('created_at', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);

    // Apply filters
    if ($request->from_branch_id) {
        $query->where('from_branch_id', $request->from_branch_id);
    }

    if ($request->to_branch_id) {
        $query->where('to_branch_id', $request->to_branch_id);
    }

    // Transfer type filter (inbound/outbound from user's branch perspective)
    if ($request->transfer_type && $request->transfer_type !== 'all') {
        $userBranchId = $user->primary_branch_id;
        if ($request->transfer_type === 'inbound') {
            $query->where('to_branch_id', $userBranchId);
        } elseif ($request->transfer_type === 'outbound') {
            $query->where('from_branch_id', $userBranchId);
        }
    }

    if ($request->product_id) {
        $query->whereHas('items', function($q) use ($request) {
            $q->where('product_id', $request->product_id);
        });
    }

    if ($request->category_id) {
        $query->whereHas('items.product', function($q) use ($request) {
            $q->where('category_id', $request->category_id);
        });
    }

    if ($request->status) {
        $query->where('status', $request->status);
    }

    if ($request->user_id) {
        $query->where('initiated_by', $request->user_id);
    }

    if ($request->approved_by) {
        $query->where('approved_by', $request->approved_by);
    }

    if ($request->search) {
        $query->where(function($q) use ($request) {
            $q->whereHas('items.product', function($pq) use ($request) {
                $pq->where('name', 'like', "%{$request->search}%")
                   ->orWhere('sku', 'like', "%{$request->search}%");
            })->orWhere('notes', 'like', "%{$request->search}%")
              ->orWhere('transfer_number', 'like', "%{$request->search}%");
        });
    }

    if ($request->min_quantity) {
        $query->whereHas('items', function($q) use ($request) {
            $q->where('quantity', '>=', $request->min_quantity);
        });
    }

    // Include cancelled transfers option
    if (!$request->include_cancelled) {
        $query->where('status', '!=', 'cancelled');
    }

    // Sorting
    $sortBy = $request->sort_by ?? 'date';
    $sortOrder = $request->sort_order ?? 'desc';

    if ($sortBy === 'product_name') {
        // Can't easily sort by product name when there are multiple items per transfer
        $query->orderBy('created_at', $sortOrder);
    } else {
        $orderColumn = $sortBy === 'date' ? 'created_at' : $sortBy;
        $query->orderBy($orderColumn, $sortOrder);
    }

    $transfers = $query->get();

    // Calculate summary metrics (aggregating from items)
    $totalQuantityTransferred = 0;
    $totalQuantityInTransit = 0;
    $totalTransferValue = 0;
    $uniqueProducts = collect();

    foreach ($transfers as $transfer) {
        foreach ($transfer->items as $item) {
            $uniqueProducts->push($item->product_id);
            $itemValue = $item->quantity * ($item->unit_cost ?? 0);
            $totalTransferValue += $itemValue;

            if ($transfer->status === 'completed' || $transfer->status === 'received') {
                $totalQuantityTransferred += $item->quantity;
            }

            if (in_array($transfer->status, ['in_transit', 'approved'])) {
                $totalQuantityInTransit += $item->quantity;
            }
        }
    }

    $summary = [
        'total_transfers' => $transfers->count(),
        'pending_count' => $transfers->where('status', 'pending')->count(),
        'approved_count' => $transfers->where('status', 'approved')->count(),
        'sent_count' => $transfers->where('status', 'in_transit')->count(),
        'received_count' => $transfers->whereIn('status', ['completed', 'received'])->count(),
        'cancelled_count' => $transfers->where('status', 'cancelled')->count(),
        'total_quantity_transferred' => $totalQuantityTransferred,
        'total_quantity_in_transit' => $totalQuantityInTransit,
        'total_transfer_value' => $totalTransferValue,
        'unique_products' => $uniqueProducts->unique()->count(),
        'unique_routes' => $transfers->map(function($t) {
            return $t->from_branch_id . '-' . $t->to_branch_id;
        })->unique()->count(),
    ];

    // Status breakdown
    $statusBreakdown = $transfers->groupBy('status')->map(function($group) {
        $totalQty = 0;
        foreach ($group as $transfer) {
            $totalQty += $transfer->items->sum('quantity');
        }

        return [
            'status' => ucfirst(str_replace('_', ' ', $group->first()->status)),
            'count' => $group->count(),
            'total_quantity' => $totalQty,
            'percentage' => 0,
        ];
    })->values();

    // Calculate percentages
    $totalCount = $statusBreakdown->sum('count');
    $statusBreakdown = $statusBreakdown->map(function($item) use ($totalCount) {
        $item['percentage'] = $totalCount > 0 ? ($item['count'] / $totalCount * 100) : 0;
        return $item;
    });

    // Branch route analysis (most active routes)
    $routeAnalysis = $transfers->groupBy(function($transfer) {
        return $transfer->fromBranch->name . ' → ' . $transfer->toBranch->name;
    })->map(function($group) {
        $totalQty = 0;
        foreach ($group as $transfer) {
            $totalQty += $transfer->items->sum('quantity');
        }

        return [
            'route' => $group->first()->fromBranch->name . ' → ' . $group->first()->toBranch->name,
            'transfer_count' => $group->count(),
            'total_quantity' => $totalQty,
            'completed' => $group->whereIn('status', ['completed', 'received'])->count(),
            'in_transit' => $group->whereIn('status', ['in_transit', 'approved'])->count(),
        ];
    })->sortByDesc('transfer_count')->take(10)->values();

    // Top transferred products (aggregate across all transfer items)
    // FIXED: Use regular array instead of collection
    $productTransfers = [];
    foreach ($transfers as $transfer) {
        foreach ($transfer->items as $item) {
            $key = $item->product_id;
            if (!isset($productTransfers[$key])) {
                $productTransfers[$key] = [
                    'product' => $item->product,
                    'transfer_count' => 0,
                    'total_quantity' => 0,
                    'completed_transfers' => 0,
                    'pending_transfers' => 0,
                ];
            }

            $productTransfers[$key]['transfer_count']++;
            $productTransfers[$key]['total_quantity'] += $item->quantity;

            if (in_array($transfer->status, ['completed', 'received'])) {
                $productTransfers[$key]['completed_transfers']++;
            }
            if (in_array($transfer->status, ['pending', 'approved', 'in_transit'])) {
                $productTransfers[$key]['pending_transfers']++;
            }
        }
    }
    // Convert to collection and sort
    $topTransferredProducts = collect($productTransfers)->sortByDesc('transfer_count')->take(10)->values();

    // Branch performance (sending)
    $sendingBranchPerformance = $transfers->groupBy('from_branch_id')->map(function($group) {
        $totalQty = 0;
        foreach ($group as $transfer) {
            $totalQty += $transfer->items->sum('quantity');
        }

        return [
            'branch_name' => $group->first()->fromBranch->name,
            'total_sent' => $group->count(),
            'quantity_sent' => $totalQty,
            'completed' => $group->whereIn('status', ['completed', 'received'])->count(),
        ];
    })->sortByDesc('total_sent')->take(5)->values();

    // Branch performance (receiving)
    $receivingBranchPerformance = $transfers->groupBy('to_branch_id')->map(function($group) {
        $totalQty = 0;
        foreach ($group as $transfer) {
            $totalQty += $transfer->items->sum('quantity');
        }

        return [
            'branch_name' => $group->first()->toBranch->name,
            'total_received' => $group->count(),
            'quantity_received' => $totalQty,
            'completed' => $group->whereIn('status', ['completed', 'received'])->count(),
        ];
    })->sortByDesc('total_received')->take(5)->values();

    return [
        'transfers' => $transfers,
        'summary' => $summary,
        'status_breakdown' => $statusBreakdown,
        'route_analysis' => $routeAnalysis,
        'top_transferred_products' => $topTransferredProducts,
        'sending_branch_performance' => $sendingBranchPerformance,
        'receiving_branch_performance' => $receivingBranchPerformance,
        'currency' => $currencySymbol,
        'currency_code' => $currencyCode,
        'period' => [
            'start' => $request->start_date,
            'end' => $request->end_date,
        ],
        'business' => $user->business,
        'generated_by' => $user->name,
        'generated_at' => now()->format('Y-m-d H:i:s'),
    ];
}

    /**
     * Generate Stock Transfer PDF Report
     */
    private function generateStockTransferPDF($data)
    {
        try {
            $pdf = new \FPDF('L', 'mm', 'A4');
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->AddPage();

            $matisse = $this->colors['matisse'];
            $sun = $this->colors['sun'];
            $hippieBlue = $this->colors['hippie_blue'];
            $success = $this->colors['success'];
            $danger = $this->colors['danger'];
            $warning = $this->colors['warning'];
            $info = $this->colors['info'];

            // ===== HEADER =====
            $this->addHeader($pdf, 'STOCK TRANSFER REPORT', $data, $matisse);

            // ===== SUMMARY BOXES =====
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Cell(0, 7, 'TRANSFER SUMMARY', 0, 1);
            $pdf->Ln(2);

            $boxW = 65;
            $boxH = 22;
            $gap = 5;
            $startX = 15;
            $y = $pdf->GetY();

            // Row 1 - 4 boxes
            $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL TRANSFERS', number_format($data['summary']['total_transfers']), $matisse);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'RECEIVED', number_format($data['summary']['received_count']), $success);
            $sentCount = $data['summary']['sent_count'] + $data['summary']['approved_count'];
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'IN TRANSIT', number_format($sentCount), $info);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'PENDING', number_format($data['summary']['pending_count']), $warning);

            // Row 2 - 4 boxes
            $y += $boxH + $gap;
            $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'QTY TRANSFERRED', number_format($data['summary']['total_quantity_transferred'], 1), $success);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'QTY IN TRANSIT', number_format($data['summary']['total_quantity_in_transit'], 1), $info);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'UNIQUE PRODUCTS', number_format($data['summary']['unique_products']), $hippieBlue);
            $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'TRANSFER VALUE', $data['currency'] . ' ' . number_format($data['summary']['total_transfer_value'], 0), $sun);

            $pdf->SetY($y + $boxH + 8);

            // ===== STATUS BREAKDOWN =====
            if ($data['status_breakdown']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->Cell(0, 6, 'BREAKDOWN BY STATUS', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 9);

                $pdf->Cell(80, 8, 'Status', 1, 0, 'C', true);
                $pdf->Cell(45, 8, 'Count', 1, 0, 'C', true);
                $pdf->Cell(50, 8, 'Total Quantity', 1, 0, 'C', true);
                $pdf->Cell(60, 8, 'Percentage', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 8);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                foreach ($data['status_breakdown'] as $status) {
                    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                    $pdf->Cell(80, 6, $status['status'], 1, 0, 'L', $fill);
                    $pdf->Cell(45, 6, number_format($status['count']), 1, 0, 'C', $fill);
                    $pdf->Cell(50, 6, number_format($status['total_quantity'], 2), 1, 0, 'R', $fill);
                    $pdf->Cell(60, 6, number_format($status['percentage'], 1) . '%', 1, 1, 'R', $fill);
                    $fill = !$fill;
                }
                $pdf->Ln(5);
            }

            // ===== ROUTE ANALYSIS =====
            if ($data['route_analysis']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
                $pdf->Cell(0, 6, 'TOP 10 MOST ACTIVE TRANSFER ROUTES', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($sun[0], $sun[1], $sun[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);

                $pdf->Cell(10, 8, '#', 1, 0, 'C', true);
                $pdf->Cell(75, 8, 'Route', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Transfers', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Total Qty', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Completed', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'In Transit', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                $rank = 1;
                foreach ($data['route_analysis'] as $route) {
                    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                    $pdf->Cell(10, 6, $rank, 1, 0, 'C', $fill);
                    $pdf->Cell(75, 6, substr($route['route'], 0, 35), 1, 0, 'L', $fill);
                    $pdf->Cell(35, 6, number_format($route['transfer_count']), 1, 0, 'C', $fill);
                    $pdf->Cell(40, 6, number_format($route['total_quantity'], 1), 1, 0, 'R', $fill);
                    $pdf->Cell(35, 6, number_format($route['completed']), 1, 0, 'C', $fill);
                    $pdf->Cell(40, 6, number_format($route['in_transit']), 1, 1, 'C', $fill);
                    $fill = !$fill;
                    $rank++;
                }
                $pdf->Ln(5);
            }

            // ===== TOP TRANSFERRED PRODUCTS =====
            if ($data['top_transferred_products']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($hippieBlue[0], $hippieBlue[1], $hippieBlue[2]);
                $pdf->Cell(0, 6, 'TOP 10 MOST TRANSFERRED PRODUCTS', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($hippieBlue[0], $hippieBlue[1], $hippieBlue[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);

                $pdf->Cell(10, 8, '#', 1, 0, 'C', true);
                $pdf->Cell(70, 8, 'Product Name', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'SKU', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Transfers', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Total Qty', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Completed', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Pending', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                $rank = 1;
                foreach ($data['top_transferred_products'] as $item) {
                    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                    $pdf->Cell(10, 6, $rank, 1, 0, 'C', $fill);
                    $pdf->Cell(70, 6, substr($item['product']->name ?? 'Unknown', 0, 30), 1, 0, 'L', $fill);
                    $pdf->Cell(30, 6, substr($item['product']->sku ?? 'N/A', 0, 12), 1, 0, 'C', $fill);
                    $pdf->Cell(35, 6, number_format($item['transfer_count']), 1, 0, 'C', $fill);
                    $pdf->Cell(40, 6, number_format($item['total_quantity'], 1), 1, 0, 'R', $fill);
                    $pdf->Cell(35, 6, number_format($item['completed_transfers']), 1, 0, 'C', $fill);
                    $pdf->Cell(35, 6, number_format($item['pending_transfers']), 1, 1, 'C', $fill);
                    $fill = !$fill;
                    $rank++;
                }
                $pdf->Ln(5);
            }

            // ===== BRANCH PERFORMANCE =====
            if ($data['sending_branch_performance']->isNotEmpty() || $data['receiving_branch_performance']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($success[0], $success[1], $success[2]);
                $pdf->Cell(0, 6, 'BRANCH PERFORMANCE ANALYSIS', 0, 1);
                $pdf->Ln(1);

                // Sending Performance
                if ($data['sending_branch_performance']->isNotEmpty()) {
                    $pdf->SetFont('Arial', 'B', 9);
                    $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
                    $pdf->Cell(0, 5, 'Top Sending Branches', 0, 1);
                    $pdf->Ln(1);

                    $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->SetFont('Arial', 'B', 8);

                    $pdf->Cell(70, 7, 'Branch Name', 1, 0, 'C', true);
                    $pdf->Cell(40, 7, 'Total Sent', 1, 0, 'C', true);
                    $pdf->Cell(45, 7, 'Quantity Sent', 1, 0, 'C', true);
                    $pdf->Cell(40, 7, 'Completed', 1, 1, 'C', true);

                    $pdf->SetFont('Arial', '', 7);
                    $pdf->SetTextColor(0, 0, 0);

                    $fill = false;
                    foreach ($data['sending_branch_performance'] as $branch) {
                        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                        $pdf->Cell(70, 6, substr($branch['branch_name'], 0, 30), 1, 0, 'L', $fill);
                        $pdf->Cell(40, 6, number_format($branch['total_sent']), 1, 0, 'C', $fill);
                        $pdf->Cell(45, 6, number_format($branch['quantity_sent'], 1), 1, 0, 'R', $fill);
                        $pdf->Cell(40, 6, number_format($branch['completed']), 1, 1, 'C', $fill);
                        $fill = !$fill;
                    }
                    $pdf->Ln(3);
                }

                // Receiving Performance
                if ($data['receiving_branch_performance']->isNotEmpty()) {
                    $pdf->SetFont('Arial', 'B', 9);
                    $pdf->SetTextColor($success[0], $success[1], $success[2]);
                    $pdf->Cell(0, 5, 'Top Receiving Branches', 0, 1);
                    $pdf->Ln(1);

                    $pdf->SetFillColor($success[0], $success[1], $success[2]);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->SetFont('Arial', 'B', 8);

                    $pdf->Cell(70, 7, 'Branch Name', 1, 0, 'C', true);
                    $pdf->Cell(40, 7, 'Total Received', 1, 0, 'C', true);
                    $pdf->Cell(45, 7, 'Quantity Received', 1, 0, 'C', true);
                    $pdf->Cell(40, 7, 'Completed', 1, 1, 'C', true);

                    $pdf->SetFont('Arial', '', 7);
                    $pdf->SetTextColor(0, 0, 0);

                    $fill = false;
                    foreach ($data['receiving_branch_performance'] as $branch) {
                        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                        $pdf->Cell(70, 6, substr($branch['branch_name'], 0, 30), 1, 0, 'L', $fill);
                        $pdf->Cell(40, 6, number_format($branch['total_received']), 1, 0, 'C', $fill);
                        $pdf->Cell(45, 6, number_format($branch['quantity_received'], 1), 1, 0, 'R', $fill);
                        $pdf->Cell(40, 6, number_format($branch['completed']), 1, 1, 'C', $fill);
                        $fill = !$fill;
                    }
                    $pdf->Ln(5);
                }
            }

            // ===== DETAILED TRANSFERS TABLE =====
            if ($data['transfers']->isEmpty()) {
                $pdf->SetFont('Arial', 'I', 11);
                $pdf->SetTextColor(128, 128, 128);
                $pdf->Cell(0, 10, 'No stock transfers found for this period.', 0, 1, 'C');
            } else {
                $pdf->AddPage();

                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->Cell(0, 6, 'DETAILED TRANSFER HISTORY', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 7);

                $pdf->Cell(22, 8, 'Date', 1, 0, 'C', true);
                $pdf->Cell(48, 8, 'Product', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'From Branch', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'To Branch', 1, 0, 'C', true);
                $pdf->Cell(22, 8, 'Quantity', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Status', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'User', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Approved By', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 6);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                foreach ($data['transfers']->take(100) as $transfer) {
                    $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);

                    $date = $transfer->created_at->format('m/d H:i');
                    $productName = substr($transfer->product->name ?? 'Unknown', 0, 22);
                    $fromBranch = substr($transfer->fromBranch->name ?? 'N/A', 0, 18);
                    $toBranch = substr($transfer->toBranch->name ?? 'N/A', 0, 18);
                    $status = ucfirst($transfer->status ?? 'N/A');
                    $userName = substr($transfer->user->name ?? 'System', 0, 15);
                    $approvedBy = substr($transfer->approvedBy->name ?? '-', 0, 15);

                    $pdf->Cell(22, 6, $date, 1, 0, 'C', $fill);
                    $pdf->Cell(48, 6, $productName, 1, 0, 'L', $fill);
                    $pdf->Cell(35, 6, $fromBranch, 1, 0, 'L', $fill);
                    $pdf->Cell(35, 6, $toBranch, 1, 0, 'L', $fill);
                    $pdf->Cell(22, 6, number_format($transfer->quantity, 1), 1, 0, 'R', $fill);
                    $pdf->Cell(25, 6, $status, 1, 0, 'C', $fill);
                    $pdf->Cell(30, 6, $userName, 1, 0, 'L', $fill);
                    $pdf->Cell(30, 6, $approvedBy, 1, 1, 'L', $fill);

                    $fill = !$fill;

                    if ($pdf->GetY() > 180) {
                        $pdf->AddPage();
                        $this->repeatTransferTableHeader($pdf, $matisse);
                    }
                }

                if ($data['transfers']->count() > 100) {
                    $pdf->Ln(2);
                    $pdf->SetFont('Arial', 'I', 8);
                    $pdf->SetTextColor(100, 100, 100);
                    $pdf->Cell(0, 5, 'Note: Showing first 100 transfers. Total transfers: ' . number_format($data['transfers']->count()), 0, 1, 'C');
                }
            }

            // ===== FOOTER =====
            $this->addFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], null);

            $filename = 'stock_transfer_report_' . date('Y-m-d') . '.pdf';
            return response()->stream(
                fn() => print($pdf->Output('S')),
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="' . $filename . '"'
                ]
            );

        } catch (\Exception $e) {
            Log::error('PDF Generation Error: ' . $e->getMessage());
            return serverErrorResponse('Failed to generate PDF', $e->getMessage());
        }
    }

    // =====================================================
    // SHARED PDF HELPER METHODS
    // =====================================================

    /**
     * Add professional header
     */
    private function addHeader($pdf, $title, $data, $primary)
    {
        $pdf->SetY(15);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 8, $data['business']->name ?? 'Your Company', 0, 1, 'C');
        $pdf->Ln(3);

        $pdf->SetFont('Arial', 'B', 20);
        $pdf->Cell(0, 10, $title, 0, 1, 'C');
        $pdf->Ln(2);

        if (isset($data['valuation_date'])) {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 6, 'As of ' . date('F d, Y', strtotime($data['valuation_date'])), 0, 1, 'C');
        } else {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor(0, 0, 0);
            $period = date('F d, Y', strtotime($data['period']['start'])) . ' - ' . date('F d, Y', strtotime($data['period']['end']));
            $pdf->Cell(0, 6, $period, 0, 1, 'C');
        }

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(100, 100, 100);
        $filterText = 'All Records';
        if (isset($data['branch']) && $data['branch']) {
            $filterText = 'Branch: ' . $data['branch']->name;
        }
        $pdf->Cell(0, 5, 'Filter: ' . $filterText, 0, 1, 'C');
        $pdf->Ln(8);
    }

    /**
     * Draw metric box
     */
    private function drawMetricBox($pdf, $x, $y, $w, $h, $title, $value, $color)
    {
        $pdf->SetDrawColor($color[0], $color[1], $color[2]);
        $pdf->SetLineWidth(0.4);
        $pdf->Rect($x, $y, $w, $h, 'D');

        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetXY($x, $y + 3);
        $pdf->Cell($w, 5, $title, 0, 0, 'C');

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetXY($x, $y + 11);
        $pdf->Cell($w, 6, $value, 0, 0, 'C');
    }

    /**
     * Add professional footer
     */
    private function addFooter($pdf, $generatedBy, $generatedAt, $business = null, $branch = null)
    {
        $pdf->SetY(-25);

        if ($business || $branch) {
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(36, 116, 172);
            $businessText = ($business ? $business->name : '') . ($branch ? ' - ' . $branch->name : '');
            $pdf->Cell(0, 5, $businessText, 0, 1, 'C');
        }

        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell(0, 4, 'Generated by: ' . $generatedBy . ' | ' . $generatedAt, 0, 1, 'C');
        $pdf->Cell(0, 4, 'Page ' . $pdf->PageNo(), 0, 1, 'C');
        $pdf->Cell(0, 4, 'This is a computer-generated document and requires no signature', 0, 1, 'C');
    }

    /**
     * Repeat inventory table header on new page
     */
    private function repeatInventoryTableHeader($pdf, $currency, $matisse)
    {
        $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 7);

        $pdf->Cell(20, 8, 'SKU', 1, 0, 'C', true);
        $pdf->Cell(70, 8, 'Product Name', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Category', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Branch', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Quantity', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Unit Cost', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Unit', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Total Value', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 6);
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Repeat adjustment table header on new page
     */
    private function repeatAdjustmentTableHeader($pdf, $matisse)
    {
        $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 7);

        $pdf->Cell(22, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Product', 1, 0, 'C', true);
        $pdf->Cell(28, 8, 'Branch', 1, 0, 'C', true);
        $pdf->Cell(22, 8, 'Qty Change', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'Before', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'After', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Reason', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Status', 1, 0, 'C', true);
        $pdf->Cell(28, 8, 'User', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Approved By', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 6);
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Repeat transfer table header on new page
     */
    private function repeatTransferTableHeader($pdf, $matisse)
    {
        $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 7);

        $pdf->Cell(22, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(48, 8, 'Product', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'From Branch', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'To Branch', 1, 0, 'C', true);
        $pdf->Cell(22, 8, 'Quantity', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Status', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'User', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Approved By', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 6);
        $pdf->SetTextColor(0, 0, 0);
    }
}