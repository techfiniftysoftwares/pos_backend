<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Product;
use App\Models\Category;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

// FPDF
// require_once(base_path('vendor/setasign/fpdf/fpdf.php'));

class StockPdfReportController extends Controller
{
    // Color scheme - Matisse theme (matches your brand)
    public $colors = [
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
     * REPORT 1: DETAILED STOCK LEVEL REPORT
     * =====================================================
     * Complete inventory snapshot with all product details
     */
    public function generateStockLevelReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'as_of_date' => 'nullable|date',
                'branch_id' => 'nullable|exists:branches,id',
                'business_id' => 'nullable|exists:businesses,id',
                'product_id' => 'nullable|exists:products,id',
                'category_id' => 'nullable|exists:categories,id',
                'stock_status' => 'nullable|in:low_stock,out_of_stock,in_stock,all',
                'search' => 'nullable|string',
                'sort_by' => 'nullable|in:quantity,value,product_name,last_restocked',
                'sort_order' => 'nullable|in:asc,desc',
                'min_value' => 'nullable|numeric|min:0',
                'tracked_only' => 'nullable|boolean',
                'currency_code' => 'nullable|string|size:3',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getStockLevelReportData($request);
            return $this->generateStockLevelPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate stock level report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate stock level report', $e->getMessage());
        }
    }

    /**
     * Get stock level data with comprehensive filtering
     */
    private function getStockLevelReportData(Request $request)
    {
        $user = Auth::user();

        // Get currency information
        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        $businessId = $request->business_id ?? $user->business_id;
        $branchId = $request->branch_id ?? $user->primary_branch_id;

        // Build query with comprehensive filtering
        $query = Stock::with(['product.category', 'product.unit', 'branch'])
            ->forBusiness($businessId);

        // Apply filters
        if ($branchId) {
            $query->forBranch($branchId);
        }

        if ($request->product_id) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->category_id) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        if ($request->search) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('sku', 'like', "%{$request->search}%")
                    ->orWhere('barcode', 'like', "%{$request->search}%");
            });
        }

        if ($request->stock_status) {
            switch ($request->stock_status) {
                case 'low_stock':
                    $query->lowStock();
                    break;
                case 'out_of_stock':
                    $query->outOfStock();
                    break;
                case 'in_stock':
                    $query->where('quantity', '>', 0);
                    break;
            }
        }

        if ($request->tracked_only) {
            $query->whereHas('product', function ($q) {
                $q->where('track_inventory', true);
            });
        }

        $stocks = $query->get();

        // Filter by minimum value if specified
        if ($request->min_value) {
            $stocks = $stocks->filter(function ($stock) use ($request) {
                return $stock->stock_value >= $request->min_value;
            });
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'product_name';
        $sortOrder = $request->sort_order ?? 'asc';

        $stocks = $stocks->sortBy(function ($stock) use ($sortBy) {
            switch ($sortBy) {
                case 'quantity':
                    return $stock->quantity;
                case 'value':
                    return $stock->stock_value;
                case 'product_name':
                    return $stock->product->name;
                case 'last_restocked':
                    return $stock->last_restocked_at ?? $stock->created_at;
                default:
                    return $stock->product->name;
            }
        }, SORT_REGULAR, $sortOrder === 'desc');

        // Calculate summary metrics
        $summary = [
            'total_products' => $stocks->count(),
            'total_value' => $stocks->sum('stock_value'),
            'total_quantity' => $stocks->sum('quantity'),
            'low_stock_count' => $stocks->filter(fn($s) => $s->is_low_stock)->count(),
            'out_of_stock_count' => $stocks->filter(fn($s) => $s->is_out_of_stock)->count(),
            'in_stock_count' => $stocks->filter(fn($s) => $s->quantity > 0)->count(),
            'average_unit_cost' => $stocks->avg('unit_cost'),
            'total_reserved' => $stocks->sum('reserved_quantity'),
        ];

        // Category breakdown
        $categoryBreakdown = $stocks->groupBy('product.category.name')
            ->map(function ($group) {
                return [
                    'name' => $group->first()->product->category->name ?? 'Uncategorized',
                    'count' => $group->count(),
                    'total_value' => $group->sum('stock_value'),
                    'total_quantity' => $group->sum('quantity'),
                ];
            })->values();

        return [
            'stocks' => $stocks,
            'summary' => $summary,
            'category_breakdown' => $categoryBreakdown,
            'currency' => $currencySymbol,
            'currency_code' => $currencyCode,
            'as_of_date' => $request->as_of_date ?? date('Y-m-d'),
            'business' => $user->business,
            'branch' => $branchId ? $user->primaryBranch : null,
            'generated_by' => $user->name,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Generate Stock Level PDF Report
     */
    private function generateStockLevelPDF($data)
    {
        try {
            $pdf = new \FPDF('L', 'mm', 'A4');
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->AddPage();

            // Define colors
            $matisse = [36, 116, 172];
            $sun = [252, 172, 28];
            $hippieBlue = [88, 154, 175];
            $success = [40, 167, 69];
            $danger = [220, 53, 69];
            $warning = [255, 193, 7];
            $textDark = [51, 51, 51];

            // ===== HEADER =====
            $pdf->SetY(15);
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Cell(0, 8, $data['business']->name ?? 'Your Company', 0, 1, 'C');
            $pdf->Ln(3);

            $pdf->SetFont('Arial', 'B', 20);
            $pdf->Cell(0, 10, 'RAPPORT DE NIVEAU DE STOCK', 0, 1, 'C');
            $pdf->Ln(2);

            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 6, 'Au ' . date('d F Y', strtotime($data['as_of_date'])), 0, 1, 'C');

            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(100, 100, 100);
            $filterText = 'Tout le Stock';
            if ($data['branch']) {
                $filterText = 'Succursale: ' . $data['branch']->name;
            }
            $pdf->Cell(0, 5, 'Filtre: ' . $filterText, 0, 1, 'C');
            $pdf->Ln(8);

            // ===== SUMMARY BOXES =====
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Cell(0, 7, 'RESUME DU STOCK', 0, 1);
            $pdf->Ln(2);

            $boxW = 65;
            $boxH = 22;
            $gap = 5;
            $startX = 15;
            $y = $pdf->GetY();

            // Row 1 - 4 boxes
            // Box 1: Total Products
            $pdf->SetDrawColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->SetLineWidth(0.4);
            $pdf->Rect($startX, $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->SetXY($startX, $y + 3);
            $pdf->Cell($boxW, 5, 'TOTAL PRODUITS', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX, $y + 11);
            $pdf->Cell($boxW, 6, number_format($data['summary']['total_products']), 0, 0, 'C');

            // Box 2: Stock Value
            $pdf->SetDrawColor($sun[0], $sun[1], $sun[2]);
            $pdf->Rect($startX + ($boxW + $gap), $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
            $pdf->SetXY($startX + ($boxW + $gap), $y + 3);
            $pdf->Cell($boxW, 5, 'VALEUR DU STOCK', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX + ($boxW + $gap), $y + 11);
            $pdf->Cell($boxW, 6, $data['currency'] . ' ' . number_format($data['summary']['total_value'], 2), 0, 0, 'C');

            // Box 3: Total Quantity
            $pdf->SetDrawColor($hippieBlue[0], $hippieBlue[1], $hippieBlue[2]);
            $pdf->Rect($startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($hippieBlue[0], $hippieBlue[1], $hippieBlue[2]);
            $pdf->SetXY($startX + ($boxW + $gap) * 2, $y + 3);
            $pdf->Cell($boxW, 5, 'QUANTITE TOTALE', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX + ($boxW + $gap) * 2, $y + 11);
            $pdf->Cell($boxW, 6, number_format($data['summary']['total_quantity'], 2), 0, 0, 'C');

            // Box 4: In Stock
            $pdf->SetDrawColor($success[0], $success[1], $success[2]);
            $pdf->Rect($startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($success[0], $success[1], $success[2]);
            $pdf->SetXY($startX + ($boxW + $gap) * 3, $y + 3);
            $pdf->Cell($boxW, 5, 'EN STOCK', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX + ($boxW + $gap) * 3, $y + 11);
            $pdf->Cell($boxW, 6, number_format($data['summary']['in_stock_count']), 0, 0, 'C');

            // Row 2 - 4 boxes
            $y += $boxH + $gap;

            // Box 5: Low Stock
            $pdf->SetDrawColor($warning[0], $warning[1], $warning[2]);
            $pdf->Rect($startX, $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($warning[0], $warning[1], $warning[2]);
            $pdf->SetXY($startX, $y + 3);
            $pdf->Cell($boxW, 5, 'STOCK BAS', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX, $y + 11);
            $pdf->Cell($boxW, 6, number_format($data['summary']['low_stock_count']), 0, 0, 'C');

            // Box 6: Out of Stock
            $pdf->SetDrawColor($danger[0], $danger[1], $danger[2]);
            $pdf->Rect($startX + ($boxW + $gap), $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($danger[0], $danger[1], $danger[2]);
            $pdf->SetXY($startX + ($boxW + $gap), $y + 3);
            $pdf->Cell($boxW, 5, 'RUPTURE DE STOCK', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX + ($boxW + $gap), $y + 11);
            $pdf->Cell($boxW, 6, number_format($data['summary']['out_of_stock_count']), 0, 0, 'C');

            // Box 7: Reserved Qty
            $pdf->SetDrawColor(102, 102, 102);
            $pdf->Rect($startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(102, 102, 102);
            $pdf->SetXY($startX + ($boxW + $gap) * 2, $y + 3);
            $pdf->Cell($boxW, 5, 'QTE RESERVEE', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX + ($boxW + $gap) * 2, $y + 11);
            $pdf->Cell($boxW, 6, number_format($data['summary']['total_reserved'], 2), 0, 0, 'C');

            // Box 8: Avg Unit Cost
            $pdf->SetDrawColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Rect($startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->SetXY($startX + ($boxW + $gap) * 3, $y + 3);
            $pdf->Cell($boxW, 5, 'COUT UNIT. MOY.', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX + ($boxW + $gap) * 3, $y + 11);
            $pdf->Cell($boxW, 6, $data['currency'] . ' ' . number_format($data['summary']['average_unit_cost'], 2), 0, 0, 'C');

            $pdf->SetY($y + $boxH + 8);

            // ===== CATEGORY BREAKDOWN =====
            if ($data['category_breakdown']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
                $pdf->Cell(0, 6, 'REPARTITION PAR CATEGORIE', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($sun[0], $sun[1], $sun[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 9);

                $pdf->Cell(100, 8, 'Categorie', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Produits', 1, 0, 'C', true);
                $pdf->Cell(50, 8, 'Quantite', 1, 0, 'C', true);
                $pdf->Cell(60, 8, 'Valeur (' . $data['currency'] . ')', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 8);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                foreach ($data['category_breakdown'] as $category) {
                    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                    $pdf->Cell(100, 6, substr($category['name'], 0, 35), 1, 0, 'L', $fill);
                    $pdf->Cell(40, 6, number_format($category['count']), 1, 0, 'C', $fill);
                    $pdf->Cell(50, 6, number_format($category['total_quantity'], 2), 1, 0, 'R', $fill);
                    $pdf->Cell(60, 6, number_format($category['total_value'], 2), 1, 1, 'R', $fill);
                    $fill = !$fill;
                }
                $pdf->Ln(5);
            }

            // ===== DETAILED STOCK TABLE =====
            if ($data['stocks']->isEmpty()) {
                $pdf->SetFont('Arial', 'I', 11);
                $pdf->SetTextColor(128, 128, 128);
                $pdf->Cell(0, 10, 'Aucun enregistrement de stock trouve correspondant aux criteres.', 0, 1, 'C');
            } else {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->Cell(0, 6, 'NIVEAUX DE STOCK DETAILLES', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 7);

                $pdf->Cell(20, 8, 'SKU', 1, 0, 'C', true);
                $pdf->Cell(65, 8, 'Nom du Produit', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Categorie', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Succursale', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Qte', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Reservee', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Disponible', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Cout Unit.', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Valeur Stock', 1, 0, 'C', true);
                $pdf->Cell(15, 8, 'Statut', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 6);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                foreach ($data['stocks'] as $stock) {
                    $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);

                    $status = 'OK';
                    if ($stock->is_out_of_stock) {
                        $status = 'OUT';
                    } elseif ($stock->is_low_stock) {
                        $status = 'LOW';
                    }

                    $pdf->Cell(20, 6, substr($stock->product->sku, 0, 8), 1, 0, 'C', $fill);
                    $pdf->Cell(65, 6, substr($stock->product->name, 0, 35), 1, 0, 'L', $fill);
                    $pdf->Cell(35, 6, substr($stock->product->category->name ?? 'N/A', 0, 18), 1, 0, 'L', $fill);
                    $pdf->Cell(30, 6, substr($stock->branch->name, 0, 15), 1, 0, 'L', $fill);
                    $pdf->Cell(25, 6, number_format($stock->quantity, 1), 1, 0, 'R', $fill);
                    $pdf->Cell(25, 6, number_format($stock->reserved_quantity, 1), 1, 0, 'R', $fill);
                    $pdf->Cell(25, 6, number_format($stock->available_quantity, 1), 1, 0, 'R', $fill);
                    $pdf->Cell(30, 6, $data['currency'] . number_format($stock->unit_cost, 2), 1, 0, 'R', $fill);
                    $pdf->Cell(35, 6, $data['currency'] . number_format($stock->stock_value, 2), 1, 0, 'R', $fill);
                    $pdf->Cell(15, 6, $status, 1, 1, 'C', $fill);

                    $fill = !$fill;

                    if ($pdf->GetY() > 180) {
                        $pdf->AddPage();
                        // Repeat header
                        $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                        $pdf->SetTextColor(255, 255, 255);
                        $pdf->SetFont('Arial', 'B', 7);

                        $pdf->Cell(20, 8, 'SKU', 1, 0, 'C', true);
                        $pdf->Cell(65, 8, 'Nom du Produit', 1, 0, 'C', true);
                        $pdf->Cell(35, 8, 'Categorie', 1, 0, 'C', true);
                        $pdf->Cell(30, 8, 'Succursale', 1, 0, 'C', true);
                        $pdf->Cell(25, 8, 'Qte', 1, 0, 'C', true);
                        $pdf->Cell(25, 8, 'Reservee', 1, 0, 'C', true);
                        $pdf->Cell(25, 8, 'Disponible', 1, 0, 'C', true);
                        $pdf->Cell(30, 8, 'Cout Unit.', 1, 0, 'C', true);
                        $pdf->Cell(35, 8, 'Valeur Stock', 1, 0, 'C', true);
                        $pdf->Cell(15, 8, 'Statut', 1, 1, 'C', true);

                        $pdf->SetFont('Arial', '', 6);
                        $pdf->SetTextColor(0, 0, 0);
                    }
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
            $pdf->Cell(0, 4, 'Genere par: ' . $data['generated_by'] . ' | ' . $data['generated_at'], 0, 1, 'C');
            $pdf->Cell(0, 4, 'Page ' . $pdf->PageNo(), 0, 1, 'C');
            $pdf->Cell(0, 4, 'Ceci est un document genere par ordinateur et ne necessite aucune signature', 0, 1, 'C');

            $filename = 'rapport_niveau_stock_' . date('Y-m-d') . '.pdf';
            return response()->stream(
                fn() => print ($pdf->Output('S')),
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
     * REPORT 2: STOCK SUMMARY REPORT (Executive)
     * =====================================================
     * High-level overview with aggregated metrics
     */
    public function generateStockSummaryReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'branch_id' => 'nullable|exists:branches,id',
                'business_id' => 'nullable|exists:businesses,id',
                'category_id' => 'nullable|exists:categories,id',
                'currency_code' => 'nullable|string|size:3',
                'include_branch_comparison' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getStockSummaryData($request);
            return $this->generateStockSummaryPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate stock summary report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate stock summary report', $e->getMessage());
        }
    }

    /**
     * Get stock summary data (aggregated metrics only)
     */
    private function getStockSummaryData(Request $request)
    {
        $user = Auth::user();

        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        $businessId = $request->business_id ?? $user->business_id;
        $branchId = $request->branch_id ?? $user->primary_branch_id;

        // Base query
        $query = Stock::with(['product.category', 'branch'])
            ->forBusiness($businessId);

        if ($branchId) {
            $query->forBranch($branchId);
        }

        if ($request->category_id) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        $stocks = $query->get();

        // Overall metrics
        $overallMetrics = [
            'total_products' => $stocks->count(),
            'total_stock_value' => $stocks->sum('stock_value'),
            'total_quantity' => $stocks->sum('quantity'),
            'total_reserved' => $stocks->sum('reserved_quantity'),
            'low_stock_items' => $stocks->filter(fn($s) => $s->is_low_stock)->count(),
            'out_of_stock_items' => $stocks->filter(fn($s) => $s->is_out_of_stock)->count(),
            'average_stock_value' => $stocks->count() > 0 ? $stocks->sum('stock_value') / $stocks->count() : 0,
            'total_categories' => $stocks->pluck('product.category_id')->unique()->count(),
        ];

        // Top 10 most valuable items
        $topValueItems = $stocks->sortByDesc('stock_value')->take(10);

        // Category analysis
        $categoryAnalysis = $stocks->groupBy('product.category.name')->map(function ($group) {
            $categoryName = $group->first()->product->category->name ?? 'Uncategorized';
            return [
                'name' => $categoryName,
                'product_count' => $group->count(),
                'total_value' => $group->sum('stock_value'),
                'total_quantity' => $group->sum('quantity'),
                'low_stock_count' => $group->filter(fn($s) => $s->is_low_stock)->count(),
            ];
        })->sortByDesc('total_value')->values();

        // Branch comparison
        $branchComparison = collect();
        if ($request->include_branch_comparison && !$branchId) {
            $branchComparison = $stocks->groupBy('branch.name')->map(function ($group) {
                return [
                    'name' => $group->first()->branch->name,
                    'product_count' => $group->count(),
                    'total_value' => $group->sum('stock_value'),
                    'low_stock_count' => $group->filter(fn($s) => $s->is_low_stock)->count(),
                    'out_of_stock_count' => $group->filter(fn($s) => $s->is_out_of_stock)->count(),
                ];
            })->sortByDesc('total_value')->values();
        }

        return [
            'overall_metrics' => $overallMetrics,
            'top_value_items' => $topValueItems,
            'category_analysis' => $categoryAnalysis,
            'branch_comparison' => $branchComparison,
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
     * Generate Stock Summary PDF Report
     */
    private function generateStockSummaryPDF($data)
    {
        try {
            $pdf = new \FPDF('P', 'mm', 'A4');
            $pdf->SetAutoPageBreak(true, 25);
            $pdf->AddPage();

            // Define colors
            $matisse = [36, 116, 172];
            $sun = [252, 172, 28];
            $hippieBlue = [88, 154, 175];
            $success = [40, 167, 69];

            // ===== HEADER =====
            $pdf->SetY(15);
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Cell(0, 8, $data['business']->name ?? 'Your Company', 0, 1, 'C');
            $pdf->Ln(3);

            $pdf->SetFont('Arial', 'B', 20);
            $pdf->Cell(0, 10, 'RAPPORT RESUME DU STOCK', 0, 1, 'C');
            $pdf->Ln(2);

            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor(0, 0, 0);
            // Handle date display - show date range if provided, otherwise show "As of" current date
            if ($data['period']['start'] && $data['period']['end']) {
                $period = date('F d, Y', strtotime($data['period']['start'])) . ' - ' . date('F d, Y', strtotime($data['period']['end']));
            } else {
                $period = 'Au ' . date('d F Y');
            }
            $pdf->Cell(0, 6, $period, 0, 1, 'C');

            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(100, 100, 100);
            $filterText = 'Toutes les Categories';
            if ($data['branch']) {
                $filterText = 'Succursale: ' . $data['branch']->name;
            }
            $pdf->Cell(0, 5, 'Filtre: ' . $filterText, 0, 1, 'C');
            $pdf->Ln(8);

            // ===== OVERALL METRICS (2x2 LARGE BOXES) =====
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Cell(0, 6, 'INDICATEURS CLES DE PERFORMANCE', 0, 1);
            $pdf->Ln(2);

            $boxW = 90;
            $boxH = 26;
            $gap = 10;
            $startX = 15;
            $y = $pdf->GetY();

            // Row 1
            // Box 1: Total Stock Value
            $pdf->SetDrawColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->SetLineWidth(0.5);
            $pdf->Rect($startX, $y, $boxW, $boxH, 'D');
            $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Rect($startX, $y, $boxW, 3, 'F');
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->SetXY($startX, $y + 5);
            $pdf->Cell($boxW, 5, 'VALEUR TOTALE DU STOCK', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($startX, $y + 12);
            $pdf->Cell($boxW, 7, $data['currency'] . ' ' . number_format($data['overall_metrics']['total_stock_value'], 2), 0, 0, 'C');
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetXY($startX, $y + 21);
            $pdf->Cell($boxW, 4, number_format($data['overall_metrics']['total_products']) . ' produits suivis', 0, 0, 'C');

            // Box 2: Inventory Health
            $pdf->SetDrawColor($sun[0], $sun[1], $sun[2]);
            $pdf->Rect($startX + $boxW + $gap, $y, $boxW, $boxH, 'D');
            $pdf->SetFillColor($sun[0], $sun[1], $sun[2]);
            $pdf->Rect($startX + $boxW + $gap, $y, $boxW, 3, 'F');
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
            $pdf->SetXY($startX + $boxW + $gap, $y + 5);
            $pdf->Cell($boxW, 5, 'SANTE DU STOCK', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($startX + $boxW + $gap, $y + 12);
            $pdf->Cell($boxW, 7, number_format($data['overall_metrics']['low_stock_items']) . ' stock bas', 0, 0, 'C');
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetXY($startX + $boxW + $gap, $y + 21);
            $pdf->Cell($boxW, 4, number_format($data['overall_metrics']['out_of_stock_items']) . ' en rupture', 0, 0, 'C');

            // Row 2
            $y += $boxH + $gap;

            // Box 3: Total Quantity
            $pdf->SetDrawColor($success[0], $success[1], $success[2]);
            $pdf->Rect($startX, $y, $boxW, $boxH, 'D');
            $pdf->SetFillColor($success[0], $success[1], $success[2]);
            $pdf->Rect($startX, $y, $boxW, 3, 'F');
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor($success[0], $success[1], $success[2]);
            $pdf->SetXY($startX, $y + 5);
            $pdf->Cell($boxW, 5, 'QUANTITE TOTALE', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($startX, $y + 12);
            $pdf->Cell($boxW, 7, number_format($data['overall_metrics']['total_quantity'], 2) . ' unites', 0, 0, 'C');
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetXY($startX, $y + 21);
            $pdf->Cell($boxW, 4, number_format($data['overall_metrics']['total_reserved'], 2) . ' reservees', 0, 0, 'C');

            // Box 4: Avg Stock Value
            $pdf->SetDrawColor($hippieBlue[0], $hippieBlue[1], $hippieBlue[2]);
            $pdf->Rect($startX + $boxW + $gap, $y, $boxW, $boxH, 'D');
            $pdf->SetFillColor($hippieBlue[0], $hippieBlue[1], $hippieBlue[2]);
            $pdf->Rect($startX + $boxW + $gap, $y, $boxW, 3, 'F');
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor($hippieBlue[0], $hippieBlue[1], $hippieBlue[2]);
            $pdf->SetXY($startX + $boxW + $gap, $y + 5);
            $pdf->Cell($boxW, 5, 'VALEUR STOCK MOY.', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($startX + $boxW + $gap, $y + 12);
            $pdf->Cell($boxW, 7, $data['currency'] . ' ' . number_format($data['overall_metrics']['average_stock_value'], 2), 0, 0, 'C');
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetXY($startX + $boxW + $gap, $y + 21);
            $pdf->Cell($boxW, 4, number_format($data['overall_metrics']['total_categories']) . ' categories', 0, 0, 'C');

            $pdf->SetY($y + $boxH + 6);

            // ===== CATEGORY ANALYSIS =====
            if ($data['category_analysis']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->Cell(0, 6, 'ANALYSE DE PERFORMANCE PAR CATEGORIE', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);

                $pdf->Cell(60, 8, 'Categorie', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Produits', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Qte Totale', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Valeur Totale (' . $data['currency'] . ')', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Stock Bas', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                foreach ($data['category_analysis']->take(10) as $category) {
                    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                    $pdf->Cell(60, 6, substr($category['name'], 0, 25), 1, 0, 'L', $fill);
                    $pdf->Cell(30, 6, number_format($category['product_count']), 1, 0, 'C', $fill);
                    $pdf->Cell(35, 6, number_format($category['total_quantity'], 1), 1, 0, 'R', $fill);
                    $pdf->Cell(40, 6, number_format($category['total_value'], 2), 1, 0, 'R', $fill);
                    $pdf->Cell(25, 6, number_format($category['low_stock_count']), 1, 1, 'C', $fill);
                    $fill = !$fill;
                }
                $pdf->Ln(5);
            }

            // ===== TOP VALUE ITEMS =====
            if ($data['top_value_items']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
                $pdf->Cell(0, 6, 'TOP 10 ARTICLES A PLUS FORTE VALEUR', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($sun[0], $sun[1], $sun[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);

                $pdf->Cell(10, 8, '#', 1, 0, 'C', true);
                $pdf->Cell(70, 8, 'Nom du Produit', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'SKU', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Quantite', 1, 0, 'C', true);
                $pdf->Cell(50, 8, 'Valeur Stock (' . $data['currency'] . ')', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                $rank = 1;
                foreach ($data['top_value_items'] as $item) {
                    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                    $pdf->Cell(10, 6, $rank, 1, 0, 'C', $fill);
                    $pdf->Cell(70, 6, substr($item->product->name, 0, 30), 1, 0, 'L', $fill);
                    $pdf->Cell(30, 6, substr($item->product->sku, 0, 12), 1, 0, 'C', $fill);
                    $pdf->Cell(30, 6, number_format($item->quantity, 1), 1, 0, 'R', $fill);
                    $pdf->Cell(50, 6, number_format($item->stock_value, 2), 1, 1, 'R', $fill);
                    $fill = !$fill;
                    $rank++;
                }
                $pdf->Ln(5);
            }

            // ===== BRANCH COMPARISON =====
            if ($data['branch_comparison']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($hippieBlue[0], $hippieBlue[1], $hippieBlue[2]);
                $pdf->Cell(0, 6, 'COMPARAISON DES SUCCURSALES', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($hippieBlue[0], $hippieBlue[1], $hippieBlue[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 9);

                $pdf->Cell(60, 8, 'Nom de la Succursale', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Produits', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Valeur Totale (' . $data['currency'] . ')', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Stock Bas', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Rupture Stock', 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 8);
                $pdf->SetTextColor(0, 0, 0);

                $fill = false;
                foreach ($data['branch_comparison'] as $branch) {
                    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
                    $pdf->Cell(60, 6, substr($branch['name'], 0, 25), 1, 0, 'L', $fill);
                    $pdf->Cell(30, 6, number_format($branch['product_count']), 1, 0, 'C', $fill);
                    $pdf->Cell(40, 6, number_format($branch['total_value'], 2), 1, 0, 'R', $fill);
                    $pdf->Cell(30, 6, number_format($branch['low_stock_count']), 1, 0, 'C', $fill);
                    $pdf->Cell(30, 6, number_format($branch['out_of_stock_count']), 1, 1, 'C', $fill);
                    $fill = !$fill;
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
            $pdf->Cell(0, 4, 'Genere par: ' . $data['generated_by'] . ' | ' . $data['generated_at'], 0, 1, 'C');
            $pdf->Cell(0, 4, 'Page ' . $pdf->PageNo(), 0, 1, 'C');
            $pdf->Cell(0, 4, 'Ceci est un document genere par ordinateur et ne necessite aucune signature', 0, 1, 'C');

            $filename = 'resume_stock_' . date('Y-m-d') . '.pdf';
            return response()->stream(
                fn() => print ($pdf->Output('S')),
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
     * REPORT 3: STOCK MOVEMENT REPORT (Detailed)
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
                'sort_by' => 'nullable|in:date,quantity,product_name,value',
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
            $query->whereHas('product', function ($q) use ($request) {
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
            $query->where(function ($q) use ($request) {
                $q->whereHas('product', function ($pq) use ($request) {
                    $pq->where('name', 'like', "%{$request->search}%")
                        ->orWhere('sku', 'like', "%{$request->search}%");
                })->orWhere('notes', 'like', "%{$request->search}%");
            });
        }

        // Sorting
        $sortBy = $request->sort_by ?? 'date';
        $sortOrder = $request->sort_order ?? 'desc';

        if ($sortBy === 'product_name') {
            $query->join('products', 'stock_movements.product_id', '=', 'products.id')
                ->orderBy('products.name', $sortOrder)
                ->select('stock_movements.*');
        } elseif ($sortBy === 'value') {
            $query->orderByRaw('(quantity * unit_cost) ' . ($sortOrder === 'desc' ? 'DESC' : 'ASC'));
        } else {
            $orderColumn = $sortBy === 'date' ? 'created_at' : $sortBy;
            $query->orderBy($orderColumn, $sortOrder);
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
            'total_cost_impact' => $movements->sum(function ($movement) {
                return $movement->quantity * $movement->unit_cost;
            }),
            'unique_products' => $movements->pluck('product_id')->unique()->count(),
        ];

        // Movement type breakdown
        $typeBreakdown = $movements->groupBy('movement_type')->map(function ($group) {
            return [
                'type' => $group->first()->movement_type,
                'count' => $group->count(),
                'total_quantity' => $group->sum('quantity'),
                'positive_movements' => $group->where('quantity', '>', 0)->count(),
                'negative_movements' => $group->where('quantity', '<', 0)->count(),
            ];
        })->values();

        // Daily summary
        $dailySummary = $movements->groupBy(function ($movement) {
            return $movement->created_at->format('Y-m-d');
        })->map(function ($dayMovements) {
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
            $pdf->Cell(0, 10, 'RAPPORT DE MOUVEMENT DE STOCK', 0, 1, 'C');
            $pdf->Ln(2);

            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor(0, 0, 0);
            $period = date('F d, Y', strtotime($data['period']['start'])) . ' - ' . date('F d, Y', strtotime($data['period']['end']));
            $pdf->Cell(0, 6, $period, 0, 1, 'C');

            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(100, 100, 100);
            $filterText = 'Tous les Mouvements';
            if ($data['branch']) {
                $filterText = 'Succursale: ' . $data['branch']->name;
            }
            $pdf->Cell(0, 5, 'Filtre: ' . $filterText, 0, 1, 'C');
            $pdf->Ln(8);

            // ===== SUMMARY BOXES =====
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Cell(0, 7, 'RESUME DES MOUVEMENTS', 0, 1);
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
            $pdf->Cell($boxW, 5, 'TOTAL MOUVEMENTS', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX, $y + 11);
            $pdf->Cell($boxW, 6, number_format($data['summary']['total_movements']), 0, 0, 'C');

            // Box 2: Stock In
            $pdf->SetDrawColor($success[0], $success[1], $success[2]);
            $pdf->Rect($startX + ($boxW + $gap), $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($success[0], $success[1], $success[2]);
            $pdf->SetXY($startX + ($boxW + $gap), $y + 3);
            $pdf->Cell($boxW, 5, 'ENTREE STOCK', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX + ($boxW + $gap), $y + 11);
            $pdf->Cell($boxW, 6, number_format($data['summary']['stock_in_movements']) . ' mvts', 0, 0, 'C');

            // Box 3: Stock Out
            $pdf->SetDrawColor($danger[0], $danger[1], $danger[2]);
            $pdf->Rect($startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($danger[0], $danger[1], $danger[2]);
            $pdf->SetXY($startX + ($boxW + $gap) * 2, $y + 3);
            $pdf->Cell($boxW, 5, 'SORTIE STOCK', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX + ($boxW + $gap) * 2, $y + 11);
            $pdf->Cell($boxW, 6, number_format($data['summary']['stock_out_movements']) . ' mvts', 0, 0, 'C');

            // Box 4: Unique Products
            $pdf->SetDrawColor($sun[0], $sun[1], $sun[2]);
            $pdf->Rect($startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
            $pdf->SetXY($startX + ($boxW + $gap) * 3, $y + 3);
            $pdf->Cell($boxW, 5, 'PRODUITS UNIQUES', 0, 0, 'C');
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
            $pdf->Cell($boxW, 5, 'QTE ENTREE TOTALE', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX, $y + 11);
            $pdf->Cell($boxW, 6, number_format($data['summary']['total_stock_in'], 2), 0, 0, 'C');

            // Box 6: Total Out Qty
            $pdf->SetDrawColor($danger[0], $danger[1], $danger[2]);
            $pdf->Rect($startX + ($boxW + $gap), $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($danger[0], $danger[1], $danger[2]);
            $pdf->SetXY($startX + ($boxW + $gap), $y + 3);
            $pdf->Cell($boxW, 5, 'QTE SORTIE TOTALE', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX + ($boxW + $gap), $y + 11);
            $pdf->Cell($boxW, 6, number_format($data['summary']['total_stock_out'], 2), 0, 0, 'C');

            // Box 7: Net Movement
            $pdf->SetDrawColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->Rect($startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
            $pdf->SetXY($startX + ($boxW + $gap) * 2, $y + 3);
            $pdf->Cell($boxW, 5, 'MOUVEMENT NET', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX + ($boxW + $gap) * 2, $y + 11);
            $pdf->Cell($boxW, 6, number_format($data['summary']['net_movement'], 2), 0, 0, 'C');

            // Box 8: Cost Impact
            $pdf->SetDrawColor($sun[0], $sun[1], $sun[2]);
            $pdf->Rect($startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'D');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor($sun[0], $sun[1], $sun[2]);
            $pdf->SetXY($startX + ($boxW + $gap) * 3, $y + 3);
            $pdf->Cell($boxW, 5, 'IMPACT COUT', 0, 0, 'C');
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetXY($startX + ($boxW + $gap) * 3, $y + 11);
            $pdf->Cell($boxW, 6, $data['currency'] . ' ' . number_format($data['summary']['total_cost_impact'], 0), 0, 0, 'C');

            $pdf->SetY($y + $boxH + 8);

            // ===== MOVEMENT TYPE BREAKDOWN =====
            if ($data['type_breakdown']->isNotEmpty()) {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->Cell(0, 6, 'REPARTITION PAR TYPE DE MOUVEMENT', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 9);

                $pdf->Cell(80, 8, 'Type de Mouvement', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Nombre Total', 1, 0, 'C', true);
                $pdf->Cell(45, 8, 'Quantite Totale', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Entree Stock', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Sortie Stock', 1, 1, 'C', true);

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
                $pdf->Cell(0, 6, 'RESUME JOURNALIER DES MOUVEMENTS', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($sun[0], $sun[1], $sun[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 9);

                $pdf->Cell(60, 8, 'Date', 1, 0, 'C', true);
                $pdf->Cell(45, 8, 'Total Mouvements', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Qte Entree', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Qte Sortie', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Variation Nette', 1, 1, 'C', true);

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
                $pdf->Cell(0, 10, 'Aucun mouvement de stock trouve pour cette periode.', 0, 1, 'C');
            } else {
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->SetTextColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->Cell(0, 6, 'HISTORIQUE DETAILLE DES MOUVEMENTS', 0, 1);
                $pdf->Ln(1);

                $pdf->SetFillColor($matisse[0], $matisse[1], $matisse[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 7);

                $pdf->Cell(25, 8, 'Date', 1, 0, 'C', true);
                $pdf->Cell(55, 8, 'Produit', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Succursale', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Type Mouvement', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Quantite', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Avant', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Apres', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Utilisateur', 1, 0, 'C', true);
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
                    $userName = substr($movement->user->name ?? 'Systeme', 0, 15);
                    $reference = substr($movement->reference_type ?? 'Manuel', -15);

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
                        $pdf->Cell(55, 8, 'Produit', 1, 0, 'C', true);
                        $pdf->Cell(30, 8, 'Succursale', 1, 0, 'C', true);
                        $pdf->Cell(30, 8, 'Type Mouvement', 1, 0, 'C', true);
                        $pdf->Cell(25, 8, 'Quantite', 1, 0, 'C', true);
                        $pdf->Cell(25, 8, 'Avant', 1, 0, 'C', true);
                        $pdf->Cell(25, 8, 'Apres', 1, 0, 'C', true);
                        $pdf->Cell(30, 8, 'Utilisateur', 1, 0, 'C', true);
                        $pdf->Cell(35, 8, 'Reference', 1, 1, 'C', true);

                        $pdf->SetFont('Arial', '', 6);
                        $pdf->SetTextColor(0, 0, 0);
                    }
                }

                if ($data['movements']->count() > 50) {
                    $pdf->Ln(2);
                    $pdf->SetFont('Arial', 'I', 8);
                    $pdf->SetTextColor(100, 100, 100);
                    $pdf->Cell(0, 5, 'Note: Affichage des 50 premiers mouvements. Total mouvements: ' . number_format($data['movements']->count()), 0, 1, 'C');
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
            $pdf->Cell(0, 4, 'Genere par: ' . $data['generated_by'] . ' | ' . $data['generated_at'], 0, 1, 'C');
            $pdf->Cell(0, 4, 'Page ' . $pdf->PageNo(), 0, 1, 'C');
            $pdf->Cell(0, 4, 'Ceci est un document genere par ordinateur et ne necessite aucune signature', 0, 1, 'C');

            $filename = 'rapport_mouvement_stock_' . date('Y-m-d') . '.pdf';
            return response()->stream(
                fn() => print ($pdf->Output('S')),
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