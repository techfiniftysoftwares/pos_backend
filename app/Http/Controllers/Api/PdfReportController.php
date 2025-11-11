<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Stock;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;


// FPDF
require_once(base_path('vendor/setasign/fpdf/fpdf.php'));

class PdfReportController extends Controller
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
     * REPORT 1: COMPREHENSIVE DAILY SALES REPORT
     * =====================================================
     * Full sales report with all details, filters, and breakdowns
     */
    public function generateDailySalesReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'branch_id' => 'nullable|exists:branches,id',
                'business_id' => 'nullable|exists:businesses,id',
                'customer_id' => 'nullable|exists:customers,id',
                'payment_type' => 'nullable|in:cash,credit,mixed',
                'payment_status' => 'nullable|in:paid,unpaid,partial',
                'status' => 'nullable|in:completed,pending,cancelled',
                'cashier_id' => 'nullable|exists:users,id',
                'payment_method_id' => 'nullable|exists:payment_methods,id',
                'category_id' => 'nullable|exists:categories,id',
                'product_id' => 'nullable|exists:products,id',
                'currency_code' => 'nullable|string|size:3',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getDailySalesReportData($request);
            return $this->generateDailySalesPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate daily sales report PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate PDF report', $e->getMessage());
        }
    }

    /**
     * Get daily sales data with comprehensive filtering
     */
    private function getDailySalesReportData(Request $request)
    {
        $user = Auth::user();

        // Get currency information
        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        // Build query
        $query = Sale::with([
            'customer',
            'cashier',
            'branch',
            'business',
            'items.product.category',
            'salePayments.payment.paymentMethod'
        ]);

        $businessId = $request->business_id ?? $user->business_id;
        $branchId = $request->branch_id ?? $user->primary_branch_id;

        $query->where('business_id', $businessId);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        // Date filtering
        $query->whereDate('completed_at', '>=', $request->start_date)
              ->whereDate('completed_at', '<=', $request->end_date);

        // Apply all filters
        if ($request->customer_id) $query->where('customer_id', $request->customer_id);
        if ($request->payment_type) $query->where('payment_type', $request->payment_type);
        if ($request->payment_status) $query->where('payment_status', $request->payment_status);
        if ($request->status) $query->where('status', $request->status);
        if ($request->cashier_id) $query->where('user_id', $request->cashier_id);

        if ($request->product_id) {
            $query->whereHas('items', fn($q) => $q->where('product_id', $request->product_id));
        }

        if ($request->category_id) {
            $query->whereHas('items.product', fn($q) => $q->where('category_id', $request->category_id));
        }

        if ($request->payment_method_id) {
            $query->whereHas('salePayments.payment', fn($q) => $q->where('payment_method_id', $request->payment_method_id));
        }

        $query->orderBy('completed_at', 'desc');
        $sales = $query->get();

        // Calculate comprehensive metrics
        $summary = [
            'total_sales' => $sales->count(),
            'total_amount' => $sales->sum('total_amount'),
            'total_tax' => $sales->sum('tax_amount'),
            'total_discount' => $sales->sum('discount_amount'),
            'subtotal' => $sales->sum('subtotal'),
            'cash_sales_count' => $sales->where('payment_type', 'cash')->count(),
            'credit_sales_count' => $sales->where('payment_type', 'credit')->count(),
            'cash_sales_amount' => $sales->where('payment_type', 'cash')->sum('total_amount'),
            'credit_sales_amount' => $sales->where('payment_type', 'credit')->sum('total_amount'),
            'average_sale' => $sales->count() > 0 ? $sales->sum('total_amount') / $sales->count() : 0,
            'total_items_sold' => $sales->sum(fn($sale) => $sale->items->sum('quantity')),
        ];

        // Payment method breakdown
        $paymentMethods = [];
        foreach ($sales as $sale) {
            foreach ($sale->salePayments as $sp) {
                $method = $sp->payment->paymentMethod->name ?? 'Unknown';
                if (!isset($paymentMethods[$method])) {
                    $paymentMethods[$method] = ['count' => 0, 'amount' => 0];
                }
                $paymentMethods[$method]['count']++;
                $paymentMethods[$method]['amount'] += $sp->amount;
            }
        }

        // Top products
        $productSales = [];
        foreach ($sales as $sale) {
            foreach ($sale->items as $item) {
                $pid = $item->product_id;
                if (!isset($productSales[$pid])) {
                    $productSales[$pid] = [
                        'product' => $item->product,
                        'quantity' => 0,
                        'amount' => 0
                    ];
                }
                $productSales[$pid]['quantity'] += $item->quantity;
                $productSales[$pid]['amount'] += $item->line_total;
            }
        }
        usort($productSales, fn($a, $b) => $b['quantity'] <=> $a['quantity']);
        $topProducts = array_slice($productSales, 0, 10);

        return [
            'sales' => $sales,
            'summary' => $summary,
            'payment_methods' => $paymentMethods,
            'top_products' => $topProducts,
            'currency' => $currencySymbol,
            'currency_code' => $currencyCode,
            'filters' => [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'branch_id' => $branchId,
            ],
            'business' => $user->business,
            'branch' => $branchId ? $user->primaryBranch : null,
            'generated_by' => $user->name,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Generate beautiful daily sales PDF
     */
    private function generateDailySalesPDF($data)
    {
        $pdf = new \FPDF('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $primary = $this->colors['matisse'];
        $accent = $this->colors['sun'];

        // HEADER
        $this->addProfessionalHeader($pdf, 'DAILY SALES REPORT', $data, $primary);

        // SUMMARY METRICS
        $this->addBeautifulSummaryBoxes($pdf, $data['summary'], $data['currency'], $primary, $accent);

        // PAYMENT BREAKDOWN
        if (!empty($data['payment_methods'])) {
            $this->addPaymentBreakdown($pdf, $data['payment_methods'], $data['currency'], $primary);
        }

        // TOP PRODUCTS
        if (!empty($data['top_products'])) {
            $this->addTopProducts($pdf, $data['top_products'], $data['currency'], $accent);
        }

        // SALES DETAILS TABLE
        $this->addSalesDetailsTable($pdf, $data['sales'], $data['currency'], $primary);

        // FOOTER
        $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

        $filename = 'daily_sales_report_' . date('Y-m-d') . '.pdf';
        return response()->stream(
            fn() => print($pdf->Output('S')),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"'
            ]
        );
    }

    /**
     * =====================================================
     * REPORT 2: SALES SUMMARY REPORT (COMPACT)
     * =====================================================
     * High-level executive summary - perfect for quick overview
     */
    public function generateSalesSummaryReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'branch_id' => 'nullable|exists:branches,id',
                'business_id' => 'nullable|exists:businesses,id',
                'currency_code' => 'nullable|string|size:3',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getSalesSummaryData($request);
            return $this->generateSalesSummaryPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate sales summary report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate summary report', $e->getMessage());
        }
    }

    /**
     * Get sales summary data (aggregated metrics only)
     */
    private function getSalesSummaryData(Request $request)
    {
        $user = Auth::user();

        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        $businessId = $request->business_id ?? $user->business_id;
        $branchId = $request->branch_id ?? $user->primary_branch_id;

        // Get sales with minimal relationships
        $query = Sale::where('business_id', $businessId)
            ->whereDate('completed_at', '>=', $request->start_date)
            ->whereDate('completed_at', '<=', $request->end_date);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $sales = $query->get();

        // Aggregate metrics
        $metrics = [
            'total_sales' => $sales->count(),
            'total_revenue' => $sales->sum('total_amount'),
            'total_tax' => $sales->sum('tax_amount'),
            'total_discount' => $sales->sum('discount_amount'),
            'avg_sale_value' => $sales->count() > 0 ? $sales->sum('total_amount') / $sales->count() : 0,
            'cash_sales' => $sales->where('payment_type', 'cash')->count(),
            'credit_sales' => $sales->where('payment_type', 'credit')->count(),
            'cash_amount' => $sales->where('payment_type', 'cash')->sum('total_amount'),
            'credit_amount' => $sales->where('payment_type', 'credit')->sum('total_amount'),
            'completed_sales' => $sales->where('status', 'completed')->count(),
            'cancelled_sales' => $sales->where('status', 'cancelled')->count(),
        ];

        // Sales by day
        $dailySales = $sales->groupBy(fn($sale) => $sale->completed_at->format('Y-m-d'))
            ->map(fn($group) => [
                'date' => $group->first()->completed_at->format('M d, Y'),
                'count' => $group->count(),
                'amount' => $group->sum('total_amount')
            ])->values();

        // Sales by payment type
        $byPaymentType = [
            ['type' => 'Cash', 'count' => $metrics['cash_sales'], 'amount' => $metrics['cash_amount']],
            ['type' => 'Credit', 'count' => $metrics['credit_sales'], 'amount' => $metrics['credit_amount']],
        ];

        return [
            'metrics' => $metrics,
            'daily_sales' => $dailySales,
            'by_payment_type' => $byPaymentType,
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
     * Generate beautiful sales summary PDF
     */
    private function generateSalesSummaryPDF($data)
    {
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 25); // Increased bottom margin for footer
        $pdf->AddPage();

        $primary = $this->colors['matisse'];
        $accent = $this->colors['sun'];

        // HEADER
        $this->addProfessionalHeader($pdf, 'SALES SUMMARY REPORT', $data, $primary);

        // KEY METRICS (LARGE BOXES) - Reduced spacing
        $this->addCompactExecutiveSummaryBoxes($pdf, $data['metrics'], $data['currency'], $primary, $accent);

        // DAILY BREAKDOWN TABLE - Reduced spacing
        $this->addCompactDailyBreakdown($pdf, $data['daily_sales'], $data['currency'], $primary);

        // PAYMENT TYPE ANALYSIS - Reduced spacing
        $this->addCompactPaymentTypeAnalysis($pdf, $data['by_payment_type'], $data['currency'], $accent);

        // FOOTER
        $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

        $filename = 'sales_summary_' . date('Y-m-d') . '.pdf';
        return response()->stream(
            fn() => print($pdf->Output('S')),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"'
            ]
        );
    }

    /**
     * Compact executive summary boxes for sales summary report
     */
    private function addCompactExecutiveSummaryBoxes($pdf, $metrics, $currency, $primary, $accent)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 6, 'KEY PERFORMANCE INDICATORS', 0, 1);
        $pdf->Ln(2);

        $boxW = 90;
        $boxH = 26;
        $gap = 10;
        $startX = 15;
        $y = $pdf->GetY();

        // Row 1
        $this->drawLargeMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL REVENUE', $currency . ' ' . number_format($metrics['total_revenue'], 2), 'From ' . $metrics['total_sales'] . ' transactions', $primary);
        $this->drawLargeMetricBox($pdf, $startX + $boxW + $gap, $y, $boxW, $boxH, 'AVERAGE SALE', $currency . ' ' . number_format($metrics['avg_sale_value'], 2), 'Per transaction', $accent);

        // Row 2
        $y += $boxH + $gap;
        $this->drawLargeMetricBox($pdf, $startX, $y, $boxW, $boxH, 'CASH SALES', number_format($metrics['cash_sales']) . ' transactions', $currency . ' ' . number_format($metrics['cash_amount'], 2), $this->colors['success']);
        $this->drawLargeMetricBox($pdf, $startX + $boxW + $gap, $y, $boxW, $boxH, 'CREDIT SALES', number_format($metrics['credit_sales']) . ' transactions', $currency . ' ' . number_format($metrics['credit_amount'], 2), $this->colors['warning']);

        $pdf->SetY($y + $boxH + 6);
    }

    /**
     * Compact daily breakdown table
     */
    private function addCompactDailyBreakdown($pdf, $dailySales, $currency, $primary)
    {
        if ($dailySales->isEmpty()) {
            return;
        }

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 6, 'DAILY BREAKDOWN', 0, 1);
        $pdf->Ln(1);

        $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);

        $pdf->Cell(70, 7, 'Date', 1, 0, 'C', true);
        $pdf->Cell(55, 7, 'Transactions', 1, 0, 'C', true);
        $pdf->Cell(65, 7, 'Total Amount (' . $currency . ')', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($dailySales as $day) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell(70, 6, $day['date'], 1, 0, 'L', $fill);
            $pdf->Cell(55, 6, number_format($day['count']), 1, 0, 'C', $fill);
            $pdf->Cell(65, 6, number_format($day['amount'], 2), 1, 1, 'R', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(5);
    }

    /**
     * Compact payment type analysis
     */
    private function addCompactPaymentTypeAnalysis($pdf, $paymentTypes, $currency, $accent)
    {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
        $pdf->Cell(0, 6, 'PAYMENT TYPE ANALYSIS', 0, 1);
        $pdf->Ln(1);

        $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);

        $pdf->Cell(63, 7, 'Payment Type', 1, 0, 'C', true);
        $pdf->Cell(63, 7, 'Transactions', 1, 0, 'C', true);
        $pdf->Cell(64, 7, 'Amount (' . $currency . ')', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($paymentTypes as $type) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell(63, 6, $type['type'], 1, 0, 'L', $fill);
            $pdf->Cell(63, 6, number_format($type['count']), 1, 0, 'C', $fill);
            $pdf->Cell(64, 6, number_format($type['amount'], 2), 1, 1, 'R', $fill);
            $fill = !$fill;
        }

        // No extra Ln() here - let footer handle spacing
    }

    // =====================================================
    // SHARED PDF COMPONENTS (Editable Canvas)
    // =====================================================

    /**
     * Professional header with logo and company info
     */
    private function addProfessionalHeader($pdf, $title, $data, $primary)
    {
        // Logo centered at top
        if (file_exists($this->logoPath)) {
            $pageWidth = $pdf->GetPageWidth();
            $logoWidth = 35;
            $logoX = ($pageWidth - $logoWidth) / 2;
            $pdf->Image($this->logoPath, $logoX, 10, $logoWidth);
            $pdf->SetY(30);
        } else {
            $pdf->SetY(15);
        }

        // Report title
        $pdf->SetFont('Arial', 'B', 20);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 10, $title, 0, 1, 'C');
        $pdf->Ln(2);

        // Period
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor(0, 0, 0);
        $period = date('F d, Y', strtotime($data['period']['start'] ?? $data['filters']['start_date'])) .
                  ' - ' .
                  date('F d, Y', strtotime($data['period']['end'] ?? $data['filters']['end_date']));
        $pdf->Cell(0, 6, $period, 0, 1, 'C');

        // Subtitle
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 5, 'Filters: All Sales', 0, 1, 'C');

        $pdf->Ln(8);
    }

    /**
     * Beautiful summary boxes - 2x4 grid
     */
    private function addBeautifulSummaryBoxes($pdf, $summary, $currency, $primary, $accent)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'SUMMARY OVERVIEW', 0, 1);
        $pdf->Ln(2);

        $boxW = 65;
        $boxH = 22;
        $gap = 5;
        $startX = 15;
        $y = $pdf->GetY();

        // Row 1
        $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL SALES', number_format($summary['total_sales']), $primary);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'TOTAL REVENUE', $currency . ' ' . number_format($summary['total_amount'], 2), $accent);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'AVG SALE', $currency . ' ' . number_format($summary['average_sale'], 2), $this->colors['hippie_blue']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'ITEMS SOLD', number_format($summary['total_items_sold']), $this->colors['success']);

        // Row 2
        $y += $boxH + $gap;
        $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'CASH SALES', number_format($summary['cash_sales_count']) . ' (' . $currency . number_format($summary['cash_sales_amount'], 0) . ')', $this->colors['success']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'CREDIT SALES', number_format($summary['credit_sales_count']) . ' (' . $currency . number_format($summary['credit_sales_amount'], 0) . ')', $this->colors['warning']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'TAX COLLECTED', $currency . ' ' . number_format($summary['total_tax'], 2), $primary);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'DISCOUNTS', $currency . ' ' . number_format($summary['total_discount'], 2), $this->colors['danger']);

        $pdf->SetY($y + $boxH + 8);
    }

    /**
     * Executive summary boxes (larger, 2x2 grid)
     */
    private function addExecutiveSummaryBoxes($pdf, $metrics, $currency, $primary, $accent)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'KEY PERFORMANCE INDICATORS', 0, 1);
        $pdf->Ln(2);

        $boxW = 90;
        $boxH = 28;
        $gap = 10;
        $startX = 15;
        $y = $pdf->GetY();

        // Row 1
        $this->drawLargeMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL REVENUE', $currency . ' ' . number_format($metrics['total_revenue'], 2), 'From ' . $metrics['total_sales'] . ' transactions', $primary);
        $this->drawLargeMetricBox($pdf, $startX + $boxW + $gap, $y, $boxW, $boxH, 'AVERAGE SALE', $currency . ' ' . number_format($metrics['avg_sale_value'], 2), 'Per transaction', $accent);

        // Row 2
        $y += $boxH + $gap;
        $this->drawLargeMetricBox($pdf, $startX, $y, $boxW, $boxH, 'CASH SALES', number_format($metrics['cash_sales']) . ' transactions', $currency . ' ' . number_format($metrics['cash_amount'], 2), $this->colors['success']);
        $this->drawLargeMetricBox($pdf, $startX + $boxW + $gap, $y, $boxW, $boxH, 'CREDIT SALES', number_format($metrics['credit_sales']) . ' transactions', $currency . ' ' . number_format($metrics['credit_amount'], 2), $this->colors['warning']);

        $pdf->SetY($y + $boxH + 8);
    }

    /**
     * Standard metric box
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
     * Large metric box (for executive summary)
     */
    private function drawLargeMetricBox($pdf, $x, $y, $w, $h, $title, $value, $subtitle, $color)
    {
        // Border
        $pdf->SetDrawColor($color[0], $color[1], $color[2]);
        $pdf->SetLineWidth(0.5);
        $pdf->Rect($x, $y, $w, $h, 'D');

        // Accent strip at top
        $pdf->SetFillColor($color[0], $color[1], $color[2]);
        $pdf->Rect($x, $y, $w, 3, 'F');

        // Title
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetXY($x, $y + 5);
        $pdf->Cell($w, 5, $title, 0, 0, 'C');

        // Value - Check length and adjust font
        $valueLength = strlen($value);
        if ($valueLength > 20) {
            $pdf->SetFont('Arial', 'B', 12);
        } elseif ($valueLength > 15) {
            $pdf->SetFont('Arial', 'B', 14);
        } else {
            $pdf->SetFont('Arial', 'B', 15);
        }

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($x, $y + 12);
        $pdf->Cell($w, 7, $value, 0, 0, 'C');

        // Subtitle
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetXY($x, $y + 21);
        $pdf->Cell($w, 4, $subtitle, 0, 0, 'C');
    }

    /**
     * Payment method breakdown table
     */
    private function addPaymentBreakdown($pdf, $methods, $currency, $primary)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'PAYMENT METHOD BREAKDOWN', 0, 1);
        $pdf->Ln(2);

        $tableWidth = 280; // Same as card width
        $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);

        $pdf->Cell(140, 8, 'Payment Method', 1, 0, 'C', true);
        $pdf->Cell(70, 8, 'Transactions', 1, 0, 'C', true);
        $pdf->Cell(70, 8, 'Total Amount (' . $currency . ')', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($methods as $name => $data) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell(140, 7, $name, 1, 0, 'L', $fill);
            $pdf->Cell(70, 7, number_format($data['count']), 1, 0, 'C', $fill);
            $pdf->Cell(70, 7, number_format($data['amount'], 2), 1, 1, 'R', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(6);
    }

    /**
     * Top products table
     */
    private function addTopProducts($pdf, $products, $currency, $accent)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
        $pdf->Cell(0, 7, 'TOP 10 SELLING PRODUCTS', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);

        $pdf->Cell(15, 8, '#', 1, 0, 'C', true);
        $pdf->Cell(155, 8, 'Product Name', 1, 0, 'C', true);
        $pdf->Cell(55, 8, 'Qty Sold', 1, 0, 'C', true);
        $pdf->Cell(55, 8, 'Revenue (' . $currency . ')', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        $rank = 1;
        foreach ($products as $p) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell(15, 7, $rank, 1, 0, 'C', $fill);
            $pdf->Cell(155, 7, substr($p['product']->name ?? 'Unknown', 0, 60), 1, 0, 'L', $fill);
            $pdf->Cell(55, 7, number_format($p['quantity'], 2), 1, 0, 'C', $fill);
            $pdf->Cell(55, 7, number_format($p['amount'], 2), 1, 1, 'R', $fill);
            $fill = !$fill;
            $rank++;
        }

        $pdf->Ln(6);
    }

    /**
     * Sales details table (landscape)
     */
    private function addSalesDetailsTable($pdf, $sales, $currency, $primary)
    {
        if ($sales->isEmpty()) {
            $pdf->SetFont('Arial', 'I', 11);
            $pdf->SetTextColor(128, 128, 128);
            $pdf->Cell(0, 10, 'No sales transactions found for this period.', 0, 1, 'C');
            return;
        }

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'SALES DETAILS', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);

        $pdf->Cell(25, 8, 'Sale No.', 1, 0, 'C', true);
        $pdf->Cell(28, 8, 'Date/Time', 1, 0, 'C', true);
        $pdf->Cell(43, 8, 'Customer', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Cashier', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Type', 1, 0, 'C', true);
        $pdf->Cell(27, 8, 'Status', 1, 0, 'C', true);
        $pdf->Cell(22, 8, 'Items', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Amount (' . $currency . ')', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($sales as $sale) {
            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);

            $saleNum = substr($sale->sale_number, -8);
            $dateTime = $sale->completed_at ? $sale->completed_at->format('m/d H:i') : $sale->created_at->format('m/d H:i');
            $customer = $sale->customer ? substr($sale->customer->name, 0, 20) : 'Walk-in';
            $cashier = substr($sale->cashier->name ?? 'N/A', 0, 20);

            $pdf->Cell(25, 7, $saleNum, 1, 0, 'C', $fill);
            $pdf->Cell(28, 7, $dateTime, 1, 0, 'C', $fill);
            $pdf->Cell(43, 7, $customer, 1, 0, 'L', $fill);
            $pdf->Cell(40, 7, $cashier, 1, 0, 'L', $fill);
            $pdf->Cell(25, 7, ucfirst($sale->payment_type), 1, 0, 'C', $fill);
            $pdf->Cell(27, 7, ucfirst($sale->payment_status), 1, 0, 'C', $fill);
            $pdf->Cell(22, 7, number_format($sale->items->sum('quantity')), 1, 0, 'C', $fill);
            $pdf->Cell(40, 7, number_format($sale->total_amount, 2), 1, 1, 'R', $fill);

            $fill = !$fill;

            if ($pdf->GetY() > 180) {
                $pdf->AddPage();
                // Repeat header
                $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->Cell(25, 8, 'Sale No.', 1, 0, 'C', true);
                $pdf->Cell(28, 8, 'Date/Time', 1, 0, 'C', true);
                $pdf->Cell(43, 8, 'Customer', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Cashier', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Type', 1, 0, 'C', true);
                $pdf->Cell(27, 8, 'Status', 1, 0, 'C', true);
                $pdf->Cell(22, 8, 'Items', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Amount (' . $currency . ')', 1, 1, 'C', true);
                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);
            }
        }
    }

    /**
     * Daily sales breakdown table
     */
    private function addDailySalesBreakdown($pdf, $dailySales, $currency, $primary)
    {
        if ($dailySales->isEmpty()) {
            return;
        }

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'DAILY BREAKDOWN', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 10);

        $pdf->Cell(70, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(55, 8, 'Transactions', 1, 0, 'C', true);
        $pdf->Cell(65, 8, 'Total Amount (' . $currency . ')', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($dailySales as $day) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell(70, 7, $day['date'], 1, 0, 'L', $fill);
            $pdf->Cell(55, 7, number_format($day['count']), 1, 0, 'C', $fill);
            $pdf->Cell(65, 7, number_format($day['amount'], 2), 1, 1, 'R', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(6);
    }

    /**
     * Payment type analysis
     */
    private function addPaymentTypeAnalysis($pdf, $paymentTypes, $currency, $accent)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
        $pdf->Cell(0, 7, 'PAYMENT TYPE ANALYSIS', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 10);

        $pdf->Cell(63, 8, 'Payment Type', 1, 0, 'C', true);
        $pdf->Cell(63, 8, 'Transactions', 1, 0, 'C', true);
        $pdf->Cell(64, 8, 'Amount (' . $currency . ')', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($paymentTypes as $type) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell(63, 7, $type['type'], 1, 0, 'L', $fill);
            $pdf->Cell(63, 7, number_format($type['count']), 1, 0, 'C', $fill);
            $pdf->Cell(64, 7, number_format($type['amount'], 2), 1, 1, 'R', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(6);
    }

    /**
     * Professional footer
     */
    private function addProfessionalFooter($pdf, $generatedBy, $generatedAt, $business = null, $branch = null)
    {
        $pdf->SetY(-25);

        // Business and branch info
        if ($business || $branch) {
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(36, 116, 172); // Matisse blue
            $businessText = ($business ? $business->name : '') . ($branch ? ' - ' . $branch->name : '');
            $pdf->Cell(0, 5, $businessText, 0, 1, 'C');
        }

        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell(0, 4, 'Generated by: ' . $generatedBy . ' | ' . date('F d, Y h:i A', strtotime($generatedAt)), 0, 1, 'C');
        $pdf->Cell(0, 4, 'Page ' . $pdf->PageNo(), 0, 1, 'C');
        $pdf->Cell(0, 4, 'This is a computer-generated document and requires no signature', 0, 1, 'C');
    }


/**
 * =====================================================
 * REPORT 5: STOCK MOVEMENT REPORT (Detailed)
 * =====================================================
 * Comprehensive log of stock movements with filters
 */
public function generateStockMovementReport(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'branch_id' => 'nullable|exists:branches,id',
            'business_id' => 'nullable|exists:businesses,id',
            'product_id' => 'nullable|exists:products,id',
            'category_id' => 'nullable|exists:categories,id',
            'movement_type' => 'nullable|in:purchase,sale,adjustment,transfer_in,transfer_out,return_in,return_out',
            'direction' => 'nullable|in:stock_in,stock_out,all',
            'user_id' => 'nullable|exists:users,id',
            'reference_type' => 'nullable|string',
            'reason' => 'nullable|in:damaged,expired,theft,count_error,lost,found,other',
            'sort_by' => 'nullable|in:date,quantity,product_name',
            'sort_order' => 'nullable|in:asc,desc',
            'search' => 'nullable|string',
            'currency_code' => 'nullable|string|size:3',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $reportData = $this->getStockMovementReportData($request);
        return $this->generateStockMovementPDF($reportData);

    } catch (\Exception $e) {
        Log::error('Failed to generate stock movement report', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return serverErrorResponse('Failed to generate stock movement report', $e->getMessage());
    }
}

/**
 * Get stock movement data with comprehensive filtering
 */
private function getStockMovementReportData(Request $request)
{
    $user = Auth::user();

    $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
    $currency = Currency::where('code', $currencyCode)->first();
    $currencySymbol = $currency ? $currency->symbol : '$';

    $businessId = $request->business_id ?? $user->business_id;
    $branchId = $request->branch_id ?? $user->primary_branch_id;

    // Build comprehensive query
    $query = StockMovement::with(['product.category', 'branch', 'user'])
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

    if ($request->movement_type) {
        $query->where('movement_type', $request->movement_type);
    }

    if ($request->direction) {
        if ($request->direction === 'stock_in') {
            $query->where('quantity', '>', 0);
        } elseif ($request->direction === 'stock_out') {
            $query->where('quantity', '<', 0);
        }
    }

    if ($request->user_id) {
        $query->where('user_id', $request->user_id);
    }

    if ($request->reference_type) {
        $query->where('reference_type', $request->reference_type);
    }

    if ($request->reason) {
        $query->where('reason', $request->reason);
    }

    if ($request->search) {
        $query->where(function($q) use ($request) {
            $q->whereHas('product', function($pq) use ($request) {
                $pq->where('name', 'like', "%{$request->search}%")
                   ->orWhere('sku', 'like', "%{$request->search}%");
            })->orWhere('notes', 'like', "%{$request->search}%");
        });
    }

    // Sorting
    $sortBy = $request->sort_by ?? 'date';
    $sortOrder = $request->sort_order ?? 'desc';

    switch ($sortBy) {
        case 'date':
            $query->orderBy('created_at', $sortOrder);
            break;
        case 'quantity':
            $query->orderBy('quantity', $sortOrder);
            break;
        case 'product_name':
            $query->join('products', 'stock_movements.product_id', '=', 'products.id')
                  ->orderBy('products.name', $sortOrder)
                  ->select('stock_movements.*');
            break;
        default:
            $query->orderBy('created_at', $sortOrder);
            break;
    }

    $movements = $query->get();

    // Calculate summary metrics
    $summary = [
        'total_movements' => $movements->count(),
        'stock_in_movements' => $movements->where('quantity', '>', 0)->count(),
        'stock_out_movements' => $movements->where('quantity', '<', 0)->count(),
        'total_stock_in' => $movements->where('quantity', '>', 0)->sum('quantity'),
        'total_stock_out' => abs($movements->where('quantity', '<', 0)->sum('quantity')),
        'net_movement' => $movements->sum('quantity'),
        'total_cost_impact' => $movements->sum(function($movement) {
            return $movement->quantity * $movement->unit_cost;
        }),
        'unique_products' => $movements->pluck('product_id')->unique()->count(),
    ];

    // Movement type breakdown
    $typeBreakdown = $movements->groupBy('movement_type')->map(function($group) {
        return [
            'type' => $group->first()->movement_type,
            'count' => $group->count(),
            'total_quantity' => $group->sum('quantity'),
            'positive_movements' => $group->where('quantity', '>', 0)->count(),
            'negative_movements' => $group->where('quantity', '<', 0)->count(),
        ];
    })->values();

    // Daily summary
    $dailySummary = $movements->groupBy(function($movement) {
        return $movement->created_at->format('Y-m-d');
    })->map(function($dayMovements) {
        return [
            'date' => $dayMovements->first()->created_at->format('M d, Y'),
            'total_movements' => $dayMovements->count(),
            'stock_in' => $dayMovements->where('quantity', '>', 0)->sum('quantity'),
            'stock_out' => abs($dayMovements->where('quantity', '<', 0)->sum('quantity')),
            'net_change' => $dayMovements->sum('quantity'),
        ];
    })->values();

    return [
        'movements' => $movements,
        'summary' => $summary,
        'type_breakdown' => $typeBreakdown,
        'daily_summary' => $dailySummary,
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
 * Generate Stock Movement PDF Report
 */
private function generateStockMovementPDF($data)
{
    try {
        $pdf = new \FPDF('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        // Define colors
        $matisse = [36, 116, 172];
        $sun = [252, 172, 28];
        $success = [40, 167, 69];
        $danger = [220, 53, 69];

        // ===== HEADER =====
        $pdf->SetY(15);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
        $pdf->Cell(0, 8, $data['business']->name ?? 'Your Company', 0, 1, 'C');
        $pdf->Ln(3);

        $pdf->SetFont('Arial', 'B', 20);
        $pdf->Cell(0, 10, 'STOCK MOVEMENT REPORT', 0, 1, 'C');
        $pdf->Ln(2);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor(0, 0, 0);
        $period = date('F d, Y', strtotime($data['period']['start'])) . ' - ' . date('F d, Y', strtotime($data['period']['end']));
        $pdf->Cell(0, 6, $period, 0, 1, 'C');

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(100, 100, 100);
        $filterText = 'All Movements';
        if ($data['branch']) {
            $filterText = 'Branch: ' . $data['branch']->name;
        }
        $pdf->Cell(0, 5, 'Filter: ' . $filterText, 0, 1, 'C');
        $pdf->Ln(8);

        // ===== SUMMARY BOXES =====
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
        $pdf->Cell(0, 7, 'MOVEMENT SUMMARY', 0, 1);
        $pdf->Ln(2);

        $boxW = 65;
        $boxH = 22;
        $gap = 5;
        $startX = 15;
        $y = $pdf->GetY();

        // Row 1 - 4 boxes
        // Box 1: Total Movements
        $pdf->SetDrawColor($matisse[0], $matisse[1], $matisse[2]);
        $pdf->SetLineWidth(0.4);
        $pdf->Rect($startX, $y, $boxW, $boxH, 'D');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
        $pdf->SetXY($startX, $y + 3);
        $pdf->Cell($boxW, 5, 'TOTAL MOVEMENTS', 0, 0, 'C');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetXY($startX, $y + 11);
        $pdf->Cell($boxW, 6, number_format($data['summary']['total_movements']), 0, 0, 'C');

        // Box 2: Stock In
        $pdf->SetDrawColor($success[0], $success[1], $success[2]);
        $pdf->Rect($startX + ($boxW + $gap), $y, $boxW, $boxH, 'D');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor($success[0], $success[1], $success[2]);
        $pdf->SetXY($startX + ($boxW + $gap), $y + 3);
        $pdf->Cell($boxW, 5, 'STOCK IN', 0, 0, 'C');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetXY($startX + ($boxW + $gap), $y + 11);
        $pdf->Cell($boxW, 6, number_format($data['summary']['stock_in_movements']) . ' moves', 0, 0, 'C');

        // Box 3: Stock Out
        $pdf->SetDrawColor($danger[0], $danger[1], $danger[2]);
        $pdf->Rect($startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'D');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor($danger[0], $danger[1], $danger[2]);
        $pdf->SetXY($startX + ($boxW + $gap) * 2, $y + 3);
        $pdf->Cell($boxW, 5, 'STOCK OUT', 0, 0, 'C');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetXY($startX + ($boxW + $gap) * 2, $y + 11);
        $pdf->Cell($boxW, 6, number_format($data['summary']['stock_out_movements']) . ' moves', 0, 0, 'C');

        // Box 4: Unique Products
        $pdf->SetDrawColor($sun[0], $sun[1], $sun[2]);
        $pdf->Rect($startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'D');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
        $pdf->SetXY($startX + ($boxW + $gap) * 3, $y + 3);
        $pdf->Cell($boxW, 5, 'UNIQUE PRODUCTS', 0, 0, 'C');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetXY($startX + ($boxW + $gap) * 3, $y + 11);
        $pdf->Cell($boxW, 6, number_format($data['summary']['unique_products']), 0, 0, 'C');

        // Row 2 - 4 boxes
        $y += $boxH + $gap;

        // Box 5: Total In Qty
        $pdf->SetDrawColor($success[0], $success[1], $success[2]);
        $pdf->Rect($startX, $y, $boxW, $boxH, 'D');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor($success[0], $success[1], $success[2]);
        $pdf->SetXY($startX, $y + 3);
        $pdf->Cell($boxW, 5, 'TOTAL IN QTY', 0, 0, 'C');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetXY($startX, $y + 11);
        $pdf->Cell($boxW, 6, number_format($data['summary']['total_stock_in'], 2), 0, 0, 'C');

        // Box 6: Total Out Qty
        $pdf->SetDrawColor($danger[0], $danger[1], $danger[2]);
        $pdf->Rect($startX + ($boxW + $gap), $y, $boxW, $boxH, 'D');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor($danger[0], $danger[1], $danger[2]);
        $pdf->SetXY($startX + ($boxW + $gap), $y + 3);
        $pdf->Cell($boxW, 5, 'TOTAL OUT QTY', 0, 0, 'C');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetXY($startX + ($boxW + $gap), $y + 11);
        $pdf->Cell($boxW, 6, number_format($data['summary']['total_stock_out'], 2), 0, 0, 'C');

        // Box 7: Net Movement
        $pdf->SetDrawColor($matisse[0], $matisse[1], $matisse[2]);
        $pdf->Rect($startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'D');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
        $pdf->SetXY($startX + ($boxW + $gap) * 2, $y + 3);
        $pdf->Cell($boxW, 5, 'NET MOVEMENT', 0, 0, 'C');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetXY($startX + ($boxW + $gap) * 2, $y + 11);
        $pdf->Cell($boxW, 6, number_format($data['summary']['net_movement'], 2), 0, 0, 'C');

        // Box 8: Cost Impact
        $pdf->SetDrawColor($sun[0], $sun[1], $sun[2]);
        $pdf->Rect($startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'D');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
        $pdf->SetXY($startX + ($boxW + $gap) * 3, $y + 3);
        $pdf->Cell($boxW, 5, 'COST IMPACT', 0, 0, 'C');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetXY($startX + ($boxW + $gap) * 3, $y + 11);
        $pdf->Cell($boxW, 6, $data['currency'] . ' ' . number_format($data['summary']['total_cost_impact'], 0), 0, 0, 'C');

        $pdf->SetY($y + $boxH + 8);

        // ===== MOVEMENT TYPE BREAKDOWN =====
        if ($data['type_breakdown']->isNotEmpty()) {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Cell(0, 6, 'BREAKDOWN BY MOVEMENT TYPE', 0, 1);
            $pdf->Ln(1);

            $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 9);

            $pdf->Cell(80, 8, 'Movement Type', 1, 0, 'C', true);
            $pdf->Cell(40, 8, 'Total Count', 1, 0, 'C', true);
            $pdf->Cell(45, 8, 'Total Quantity', 1, 0, 'C', true);
            $pdf->Cell(35, 8, 'Stock In', 1, 0, 'C', true);
            $pdf->Cell(35, 8, 'Stock Out', 1, 1, 'C', true);

            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(0, 0, 0);

            $fill = false;
            foreach ($data['type_breakdown'] as $breakdown) {
                $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                $pdf->Cell(80, 6, ucfirst(str_replace('_', ' ', $breakdown['type'])), 1, 0, 'L', $fill);
                $pdf->Cell(40, 6, number_format($breakdown['count']), 1, 0, 'C', $fill);
                $pdf->Cell(45, 6, number_format($breakdown['total_quantity'], 2), 1, 0, 'R', $fill);
                $pdf->Cell(35, 6, number_format($breakdown['positive_movements']), 1, 0, 'C', $fill);
                $pdf->Cell(35, 6, number_format($breakdown['negative_movements']), 1, 1, 'C', $fill);
                $fill = !$fill;
            }
            $pdf->Ln(5);
        }

        // ===== DAILY SUMMARY =====
        if ($data['daily_summary']->isNotEmpty()) {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
            $pdf->Cell(0, 6, 'DAILY MOVEMENT SUMMARY', 0, 1);
            $pdf->Ln(1);

            $pdf->SetFillColor($sun[0], $sun[1], $sun[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 9);

            $pdf->Cell(60, 8, 'Date', 1, 0, 'C', true);
            $pdf->Cell(45, 8, 'Total Movements', 1, 0, 'C', true);
            $pdf->Cell(40, 8, 'Stock In Qty', 1, 0, 'C', true);
            $pdf->Cell(40, 8, 'Stock Out Qty', 1, 0, 'C', true);
            $pdf->Cell(40, 8, 'Net Change', 1, 1, 'C', true);

            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(0, 0, 0);

            $fill = false;
            foreach ($data['daily_summary']->take(10) as $day) {
                $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                $pdf->Cell(60, 6, $day['date'], 1, 0, 'L', $fill);
                $pdf->Cell(45, 6, number_format($day['total_movements']), 1, 0, 'C', $fill);
                $pdf->Cell(40, 6, number_format($day['stock_in'], 2), 1, 0, 'R', $fill);
                $pdf->Cell(40, 6, number_format($day['stock_out'], 2), 1, 0, 'R', $fill);
                $pdf->Cell(40, 6, number_format($day['net_change'], 2), 1, 1, 'R', $fill);
                $fill = !$fill;
            }
            $pdf->Ln(5);
        }

        // ===== DETAILED MOVEMENTS TABLE =====
        if ($data['movements']->isEmpty()) {
            $pdf->SetFont('Arial', 'I', 11);
            $pdf->SetTextColor(128, 128, 128);
            $pdf->Cell(0, 10, 'No stock movements found for this period.', 0, 1, 'C');
        } else {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Cell(0, 6, 'DETAILED MOVEMENT HISTORY', 0, 1);
            $pdf->Ln(1);

            $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 7);

            $pdf->Cell(25, 8, 'Date', 1, 0, 'C', true);
            $pdf->Cell(55, 8, 'Product', 1, 0, 'C', true);
            $pdf->Cell(30, 8, 'Branch', 1, 0, 'C', true);
            $pdf->Cell(30, 8, 'Movement Type', 1, 0, 'C', true);
            $pdf->Cell(25, 8, 'Quantity', 1, 0, 'C', true);
            $pdf->Cell(25, 8, 'Before', 1, 0, 'C', true);
            $pdf->Cell(25, 8, 'After', 1, 0, 'C', true);
            $pdf->Cell(30, 8, 'User', 1, 0, 'C', true);
            $pdf->Cell(35, 8, 'Reference', 1, 1, 'C', true);

            $pdf->SetFont('Arial', '', 6);
            $pdf->SetTextColor(0, 0, 0);

            $fill = false;
            foreach ($data['movements']->take(50) as $movement) {
                $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);

                $date = $movement->created_at->format('m/d H:i');
                $productName = substr($movement->product->name ?? 'Unknown', 0, 25);
                $branchName = substr($movement->branch->name ?? 'N/A', 0, 15);
                $movementType = substr(str_replace('_', ' ', $movement->movement_type), 0, 15);
                $quantity = ($movement->quantity >= 0 ? '+' : '') . number_format($movement->quantity, 1);
                $userName = substr($movement->user->name ?? 'System', 0, 15);
                $reference = substr($movement->reference_type ?? 'Manual', -15);

                $pdf->Cell(25, 6, $date, 1, 0, 'C', $fill);
                $pdf->Cell(55, 6, $productName, 1, 0, 'L', $fill);
                $pdf->Cell(30, 6, $branchName, 1, 0, 'L', $fill);
                $pdf->Cell(30, 6, $movementType, 1, 0, 'C', $fill);
                $pdf->Cell(25, 6, $quantity, 1, 0, 'R', $fill);
                $pdf->Cell(25, 6, number_format($movement->previous_quantity, 1), 1, 0, 'R', $fill);
                $pdf->Cell(25, 6, number_format($movement->new_quantity, 1), 1, 0, 'R', $fill);
                $pdf->Cell(30, 6, $userName, 1, 0, 'L', $fill);
                $pdf->Cell(35, 6, $reference, 1, 1, 'L', $fill);

                $fill = !$fill;

                if ($pdf->GetY() > 180) {
                    $pdf->AddPage();
                    // Repeat header
                    $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->SetFont('Arial', 'B', 7);

                    $pdf->Cell(25, 8, 'Date', 1, 0, 'C', true);
                    $pdf->Cell(55, 8, 'Product', 1, 0, 'C', true);
                    $pdf->Cell(30, 8, 'Branch', 1, 0, 'C', true);
                    $pdf->Cell(30, 8, 'Movement Type', 1, 0, 'C', true);
                    $pdf->Cell(25, 8, 'Quantity', 1, 0, 'C', true);
                    $pdf->Cell(25, 8, 'Before', 1, 0, 'C', true);
                    $pdf->Cell(25, 8, 'After', 1, 0, 'C', true);
                    $pdf->Cell(30, 8, 'User', 1, 0, 'C', true);
                    $pdf->Cell(35, 8, 'Reference', 1, 1, 'C', true);

                    $pdf->SetFont('Arial', '', 6);
                    $pdf->SetTextColor(0, 0, 0);
                }
            }

            if ($data['movements']->count() > 50) {
                $pdf->Ln(2);
                $pdf->SetFont('Arial', 'I', 8);
                $pdf->SetTextColor(100, 100, 100);
                $pdf->Cell(0, 5, 'Note: Showing first 50 movements. Total movements: ' . number_format($data['movements']->count()), 0, 1, 'C');
            }
        }

        // ===== FOOTER =====
        $pdf->SetY(-25);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(36, 116, 172);
        $businessText = $data['business']->name . ($data['branch'] ? ' - ' . $data['branch']->name : '');
        $pdf->Cell(0, 5, $businessText, 0, 1, 'C');

        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell(0, 4, 'Generated by: ' . $data['generated_by'] . ' | ' . $data['generated_at'], 0, 1, 'C');
        $pdf->Cell(0, 4, 'Page ' . $pdf->PageNo(), 0, 1, 'C');
        $pdf->Cell(0, 4, 'This is a computer-generated document and requires no signature', 0, 1, 'C');

        $filename = 'stock_movement_report_' . date('Y-m-d') . '.pdf';
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





}
