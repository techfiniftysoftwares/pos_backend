<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

// FPDF
require_once(base_path('vendor/setasign/fpdf/fpdf.php'));

class CustomerCreditPdfReportController extends Controller
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
     * REPORT 1: CUSTOMER DEBT REPORT
     * =====================================================
     * Detailed report of individual customer's debt history
     */
    public function generateCustomerDebtReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'branch_id' => 'nullable|exists:branches,id',
                'business_id' => 'nullable|exists:businesses,id',
                'transaction_type' => 'nullable|in:sale,payment,adjustment',
                'include_paid' => 'nullable|boolean',
                'currency_code' => 'nullable|string|size:3',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getCustomerDebtData($request);
            return $this->generateCustomerDebtPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate customer debt report PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate customer debt report', $e->getMessage());
        }
    }

    /**
     * Get customer debt data
     */
    private function getCustomerDebtData(Request $request)
    {
        $user = Auth::user();

        // Get currency information
        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        // Get customer
        $customer = Customer::with('business')->findOrFail($request->customer_id);

        // Build query for transactions
        $query = CustomerCreditTransaction::query()
            ->with(['paymentMethod', 'processedBy', 'branch'])
            ->where('customer_id', $request->customer_id)
            ->whereDate('created_at', '>=', $request->start_date)
            ->whereDate('created_at', '<=', $request->end_date);

        // Apply filters
        if ($request->transaction_type) {
            $query->where('transaction_type', $request->transaction_type);
        }

        if ($request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        $query->orderBy('created_at', 'asc');
        $transactions = $query->get();

        // Calculate metrics
        $totalSales = $transactions->where('transaction_type', 'sale')->sum('amount');
        $totalPayments = $transactions->where('transaction_type', 'payment')->sum('amount');
        $totalAdjustments = $transactions->where('transaction_type', 'adjustment')->sum('amount');
        $currentBalance = $customer->current_credit_balance;

        $summary = [
            'total_sales' => $totalSales,
            'total_payments' => $totalPayments,
            'total_adjustments' => $totalAdjustments,
            'current_balance' => $currentBalance,
            'credit_limit' => $customer->credit_limit,
            'available_credit' => $customer->credit_limit - $currentBalance,
            'transaction_count' => $transactions->count(),
        ];

        return [
            'customer' => $customer,
            'transactions' => $transactions,
            'summary' => $summary,
            'currency' => $currencySymbol,
            'currency_code' => $currencyCode,
            'filters' => [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'branch_id' => $request->branch_id,
            ],
            'business' => $user->business,
            'branch' => $request->branch_id ? $user->primaryBranch : null,
            'generated_by' => $user->name,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Generate customer debt PDF
     */
    private function generateCustomerDebtPDF($data)
    {
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        $primary = $this->colors['matisse'];
        $danger = $this->colors['danger'];

        // HEADER
        $this->addProfessionalHeader($pdf, 'CUSTOMER DEBT REPORT', $data, $primary);

        // CUSTOMER INFO
        $this->addCustomerInfoBox($pdf, $data['customer'], $data['summary'], $data['currency'], $primary, $danger);

        // SUMMARY METRICS
        $this->addDebtSummaryBoxes($pdf, $data['summary'], $data['currency'], $primary);

        // TRANSACTIONS TABLE
        $this->addTransactionsTable($pdf, $data['transactions'], $data['currency'], $primary);

        // FOOTER
        $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

        $filename = 'customer_debt_report_' . $data['customer']->customer_number . '_' . date('Y-m-d') . '.pdf';
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
     * REPORT 2: CREDIT AGING REPORT
     * =====================================================
     * Shows all customers grouped by debt age
     */
    public function generateCreditAgingReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'business_id' => 'nullable|exists:businesses,id',
                'branch_id' => 'nullable|exists:branches,id',
                'min_balance' => 'nullable|numeric|min:0',
                'customer_type' => 'nullable|in:regular,vip,wholesale',
                'as_of_date' => 'nullable|date',
                'currency_code' => 'nullable|string|size:3',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getCreditAgingData($request);
            return $this->generateCreditAgingPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate credit aging report PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate credit aging report', $e->getMessage());
        }
    }

    /**
     * Get credit aging data
     */
    private function getCreditAgingData(Request $request)
    {
        $user = Auth::user();

        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        $businessId = $request->business_id ?? $user->business_id;
        $minBalance = $request->min_balance ?? 0;
        $asOfDate = $request->as_of_date ?? now()->format('Y-m-d');

        // Get customers with outstanding credit
        $query = Customer::query()
            ->where('business_id', $businessId)
            ->where('current_credit_balance', '>', $minBalance);

        if ($request->customer_type) {
            $query->where('customer_type', $request->customer_type);
        }

        $customers = $query->get();

        // Group customers by aging buckets
        $aging = [
            'current' => [],      // 0-30 days
            'days_31_60' => [],   // 31-60 days
            'days_61_90' => [],   // 61-90 days
            'over_90' => [],      // Over 90 days
        ];

        foreach ($customers as $customer) {
            $lastSale = CustomerCreditTransaction::where('customer_id', $customer->id)
                ->where('transaction_type', 'sale')
                ->latest()
                ->first();

            if ($lastSale) {
                $daysOld = now()->diffInDays($lastSale->created_at);

                $customerData = [
                    'customer_number' => $customer->customer_number,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'balance' => $customer->current_credit_balance,
                    'credit_limit' => $customer->credit_limit,
                    'days_old' => $daysOld,
                    'last_sale_date' => $lastSale->created_at->format('Y-m-d'),
                ];

                if ($daysOld <= 30) {
                    $aging['current'][] = $customerData;
                } elseif ($daysOld <= 60) {
                    $aging['days_31_60'][] = $customerData;
                } elseif ($daysOld <= 90) {
                    $aging['days_61_90'][] = $customerData;
                } else {
                    $aging['over_90'][] = $customerData;
                }
            }
        }

        // Calculate summary
        $summary = [
            'current_total' => collect($aging['current'])->sum('balance'),
            'current_count' => count($aging['current']),
            'days_31_60_total' => collect($aging['days_31_60'])->sum('balance'),
            'days_31_60_count' => count($aging['days_31_60']),
            'days_61_90_total' => collect($aging['days_61_90'])->sum('balance'),
            'days_61_90_count' => count($aging['days_61_90']),
            'over_90_total' => collect($aging['over_90'])->sum('balance'),
            'over_90_count' => count($aging['over_90']),
            'total_outstanding' => $customers->sum('current_credit_balance'),
            'total_customers' => $customers->count(),
        ];

        return [
            'aging' => $aging,
            'summary' => $summary,
            'currency' => $currencySymbol,
            'currency_code' => $currencyCode,
            'as_of_date' => $asOfDate,
            'filters' => [
                'min_balance' => $minBalance,
                'customer_type' => $request->customer_type,
            ],
            'business' => $user->business,
            'branch' => $request->branch_id ? $user->primaryBranch : null,
            'generated_by' => $user->name,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Generate credit aging PDF
     */
    private function generateCreditAgingPDF($data)
    {
        $pdf = new \FPDF('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        $primary = $this->colors['matisse'];
        $warning = $this->colors['warning'];
        $danger = $this->colors['danger'];

        // HEADER
        $this->addProfessionalHeader($pdf, 'CREDIT AGING REPORT', $data, $primary);

        // SUMMARY BOXES
        $this->addAgingSummaryBoxes($pdf, $data['summary'], $data['currency'], $primary, $warning, $danger);

        // AGING BUCKETS
        $this->addAgingBucket($pdf, 'CURRENT (0-30 DAYS)', $data['aging']['current'], $data['currency'], $this->colors['success']);
        $this->addAgingBucket($pdf, '31-60 DAYS', $data['aging']['days_31_60'], $data['currency'], $warning);
        $this->addAgingBucket($pdf, '61-90 DAYS', $data['aging']['days_61_90'], $data['currency'], $this->colors['sun']);
        $this->addAgingBucket($pdf, 'OVER 90 DAYS', $data['aging']['over_90'], $data['currency'], $danger);

        // FOOTER
        $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

        $filename = 'credit_aging_report_' . date('Y-m-d') . '.pdf';
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
     * REPORT 3: OUTSTANDING CREDITS REPORT
     * =====================================================
     * Summary list of all customers with outstanding debt
     */
    public function generateOutstandingCreditsReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'business_id' => 'nullable|exists:businesses,id',
                'branch_id' => 'nullable|exists:branches,id',
                'min_balance' => 'nullable|numeric|min:0',
                'max_balance' => 'nullable|numeric',
                'customer_type' => 'nullable|in:regular,vip,wholesale',
                'sort_by' => 'nullable|in:balance_desc,balance_asc,name_asc,name_desc,days_old',
                'currency_code' => 'nullable|string|size:3',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getOutstandingCreditsData($request);
            return $this->generateOutstandingCreditsPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate outstanding credits report PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate outstanding credits report', $e->getMessage());
        }
    }

    /**
     * Get outstanding credits data
     */
    private function getOutstandingCreditsData(Request $request)
    {
        $user = Auth::user();

        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        $businessId = $request->business_id ?? $user->business_id;
        $minBalance = $request->min_balance ?? 0;

        // Build query
        $query = Customer::query()
            ->where('business_id', $businessId)
            ->where('current_credit_balance', '>', $minBalance);

        if ($request->max_balance) {
            $query->where('current_credit_balance', '<=', $request->max_balance);
        }

        if ($request->customer_type) {
            $query->where('customer_type', $request->customer_type);
        }

        // Get customers
        $customers = $query->get();

        // Add last payment info
        $customersData = $customers->map(function ($customer) {
            $lastPayment = CustomerCreditTransaction::where('customer_id', $customer->id)
                ->where('transaction_type', 'payment')
                ->latest()
                ->first();

            $lastSale = CustomerCreditTransaction::where('customer_id', $customer->id)
                ->where('transaction_type', 'sale')
                ->latest()
                ->first();

            return [
                'customer_number' => $customer->customer_number,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'customer_type' => $customer->customer_type,
                'credit_limit' => $customer->credit_limit,
                'current_balance' => $customer->current_credit_balance,
                'available_credit' => $customer->credit_limit - $customer->current_credit_balance,
                'last_payment_date' => $lastPayment ? $lastPayment->created_at->format('Y-m-d') : 'Never',
                'last_sale_date' => $lastSale ? $lastSale->created_at->format('Y-m-d') : 'N/A',
                'days_since_last_payment' => $lastPayment ? now()->diffInDays($lastPayment->created_at) : null,
            ];
        });

        // Sort
        $sortBy = $request->sort_by ?? 'balance_desc';
        switch ($sortBy) {
            case 'balance_asc':
                $customersData = $customersData->sortBy('current_balance')->values();
                break;
            case 'name_asc':
                $customersData = $customersData->sortBy('name')->values();
                break;
            case 'name_desc':
                $customersData = $customersData->sortByDesc('name')->values();
                break;
            case 'days_old':
                $customersData = $customersData->sortByDesc('days_since_last_payment')->values();
                break;
            default: // balance_desc
                $customersData = $customersData->sortByDesc('current_balance')->values();
        }

        // Summary
        $summary = [
            'total_customers' => $customersData->count(),
            'total_outstanding' => $customersData->sum('current_balance'),
            'total_credit_limit' => $customersData->sum('credit_limit'),
            'total_available_credit' => $customersData->sum('available_credit'),
            'average_balance' => $customersData->count() > 0 ? $customersData->sum('current_balance') / $customersData->count() : 0,
        ];

        return [
            'customers' => $customersData,
            'summary' => $summary,
            'currency' => $currencySymbol,
            'currency_code' => $currencyCode,
            'filters' => [
                'min_balance' => $minBalance,
                'max_balance' => $request->max_balance,
                'customer_type' => $request->customer_type,
                'sort_by' => $sortBy,
            ],
            'business' => $user->business,
            'branch' => $request->branch_id ? $user->primaryBranch : null,
            'generated_by' => $user->name,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Generate outstanding credits PDF
     */
    private function generateOutstandingCreditsPDF($data)
    {
        $pdf = new \FPDF('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        $primary = $this->colors['matisse'];
        $danger = $this->colors['danger'];

        // HEADER
        $this->addProfessionalHeader($pdf, 'OUTSTANDING CREDITS REPORT', $data, $primary);

        // SUMMARY BOXES
        $this->addOutstandingSummaryBoxes($pdf, $data['summary'], $data['currency'], $primary, $danger);

        // CUSTOMERS TABLE
        $this->addOutstandingCustomersTable($pdf, $data['customers'], $data['currency'], $primary);

        // FOOTER
        $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

        $filename = 'outstanding_credits_report_' . date('Y-m-d') . '.pdf';
        return response()->stream(
            fn() => print($pdf->Output('S')),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"'
            ]
        );
    }

    // =====================================================
    // PDF COMPONENTS - Customer Info Box
    // =====================================================

    /**
     * Customer info box
     */
    private function addCustomerInfoBox($pdf, $customer, $summary, $currency, $primary, $danger)
    {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 6, 'CUSTOMER INFORMATION', 0, 1);
        $pdf->Ln(2);

        // Draw box
        $pdf->SetDrawColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetLineWidth(0.5);
        $pdf->Rect(15, $pdf->GetY(), 180, 35);

        $y = $pdf->GetY() + 3;

        // Left column
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(20, $y);
        $pdf->Cell(40, 5, 'Customer Number:', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 5, $customer->customer_number, 0, 1);

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY(20, $y + 6);
        $pdf->Cell(40, 5, 'Customer Name:', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 5, $customer->name, 0, 1);

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY(20, $y + 12);
        $pdf->Cell(40, 5, 'Phone:', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 5, $customer->phone ?? 'N/A', 0, 1);

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY(20, $y + 18);
        $pdf->Cell(40, 5, 'Email:', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 5, $customer->email ?? 'N/A', 0, 1);

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY(20, $y + 24);
        $pdf->Cell(40, 5, 'Customer Type:', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 5, ucfirst($customer->customer_type), 0, 1);

        // Right column - Financial info
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY(110, $y);
        $pdf->Cell(40, 5, 'Credit Limit:', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 5, $currency . ' ' . number_format($summary['credit_limit'], 2), 0, 1);

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY(110, $y + 6);
        $pdf->Cell(40, 5, 'Current Balance:', 0, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor($danger[0], $danger[1], $danger[2]);
        $pdf->Cell(0, 5, $currency . ' ' . number_format($summary['current_balance'], 2), 0, 1);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY(110, $y + 12);
        $pdf->Cell(40, 5, 'Available Credit:', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 5, $currency . ' ' . number_format($summary['available_credit'], 2), 0, 1);

        $pdf->SetY($pdf->GetY() + 25);
    }

    /**
     * Debt summary boxes
     */
    private function addDebtSummaryBoxes($pdf, $summary, $currency, $primary)
    {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 6, 'TRANSACTION SUMMARY', 0, 1);
        $pdf->Ln(2);

        $boxW = 90;
        $boxH = 22;
        $gap = 10;
        $startX = 15;
        $y = $pdf->GetY();

        // Row 1
        $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL SALES', $currency . ' ' . number_format($summary['total_sales'], 2), $this->colors['danger']);
        $this->drawMetricBox($pdf, $startX + $boxW + $gap, $y, $boxW, $boxH, 'TOTAL PAYMENTS', $currency . ' ' . number_format($summary['total_payments'], 2), $this->colors['success']);

        // Row 2
        $y += $boxH + $gap;
        $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'ADJUSTMENTS', $currency . ' ' . number_format($summary['total_adjustments'], 2), $this->colors['warning']);
        $this->drawMetricBox($pdf, $startX + $boxW + $gap, $y, $boxW, $boxH, 'TRANSACTIONS', number_format($summary['transaction_count']), $primary);

        $pdf->SetY($y + $boxH + 8);
    }

    /**
     * Transactions table
     */
    private function addTransactionsTable($pdf, $transactions, $currency, $primary)
    {
        if ($transactions->isEmpty()) {
            $pdf->SetFont('Arial', 'I', 11);
            $pdf->SetTextColor(128, 128, 128);
            $pdf->Cell(0, 10, 'No transactions found for this period.', 0, 1, 'C');
            return;
        }

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 6, 'TRANSACTION DETAILS', 0, 1);
        $pdf->Ln(2);

        // Table header
        $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);

        $pdf->Cell(22, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(32, 8, 'Reference', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Type', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Amount (' . $currency . ')', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Balance (' . $currency . ')', 1, 0, 'C', true);
        $pdf->Cell(28, 8, 'Payment Method', 1, 0, 'C', true);
        $pdf->Cell(23, 8, 'Processed By', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($transactions as $trans) {
            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);

            $pdf->Cell(22, 7, $trans->created_at->format('m/d/Y'), 1, 0, 'C', $fill);
            $pdf->Cell(32, 7, substr($trans->reference_number ?? 'N/A', 0, 15), 1, 0, 'L', $fill);
            $pdf->Cell(25, 7, ucfirst($trans->transaction_type), 1, 0, 'C', $fill);
            $pdf->Cell(30, 7, number_format($trans->amount, 2), 1, 0, 'R', $fill);
            $pdf->Cell(30, 7, number_format($trans->new_balance, 2), 1, 0, 'R', $fill);
            $pdf->Cell(28, 7, substr($trans->paymentMethod->name ?? 'N/A', 0, 12), 1, 0, 'C', $fill);
            $pdf->Cell(23, 7, substr($trans->processedBy->name ?? 'N/A', 0, 10), 1, 1, 'L', $fill);

            $fill = !$fill;

            // Check for page break
            if ($pdf->GetY() > 260) {
                $pdf->AddPage();
                // Repeat header
                $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->Cell(22, 8, 'Date', 1, 0, 'C', true);
                $pdf->Cell(32, 8, 'Reference', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Type', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Amount (' . $currency . ')', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Balance (' . $currency . ')', 1, 0, 'C', true);
                $pdf->Cell(28, 8, 'Payment Method', 1, 0, 'C', true);
                $pdf->Cell(23, 8, 'Processed By', 1, 1, 'C', true);
                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);
            }
        }
    }

    /**
     * Aging summary boxes (4 buckets)
     */
    private function addAgingSummaryBoxes($pdf, $summary, $currency, $primary, $warning, $danger)
    {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 6, 'AGING SUMMARY', 0, 1);
        $pdf->Ln(2);

        $boxW = 65;
        $boxH = 24;
        $gap = 5;
        $startX = 15;
        $y = $pdf->GetY();

        // Row 1
        $this->drawLargeMetricBox($pdf, $startX, $y, $boxW, $boxH,
            'CURRENT (0-30)',
            $currency . ' ' . number_format($summary['current_total'], 2),
            $summary['current_count'] . ' customers',
            $this->colors['success']
        );

        $this->drawLargeMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH,
            '31-60 DAYS',
            $currency . ' ' . number_format($summary['days_31_60_total'], 2),
            $summary['days_31_60_count'] . ' customers',
            $warning
        );

        $this->drawLargeMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH,
            '61-90 DAYS',
            $currency . ' ' . number_format($summary['days_61_90_total'], 2),
            $summary['days_61_90_count'] . ' customers',
            $this->colors['sun']
        );

        $this->drawLargeMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH,
            'OVER 90 DAYS',
            $currency . ' ' . number_format($summary['over_90_total'], 2),
            $summary['over_90_count'] . ' customers',
            $danger
        );

        $pdf->SetY($y + $boxH + 8);
    }

    /**
     * Aging bucket customers table
     */
    private function addAgingBucket($pdf, $title, $customers, $currency, $color)
    {
        if (empty($customers)) {
            return;
        }

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->Cell(0, 6, $title . ' (' . count($customers) . ' customers)', 0, 1);
        $pdf->Ln(1);

        // Table header
        $pdf->SetFillColor($color[0], $color[1], $color[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);

        $pdf->Cell(25, 7, 'Cust. No.', 1, 0, 'C', true);
        $pdf->Cell(60, 7, 'Customer Name', 1, 0, 'C', true);
        $pdf->Cell(35, 7, 'Phone', 1, 0, 'C', true);
        $pdf->Cell(40, 7, 'Balance (' . $currency . ')', 1, 0, 'C', true);
        $pdf->Cell(35, 7, 'Credit Limit', 1, 0, 'C', true);
        $pdf->Cell(25, 7, 'Days Old', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Last Sale', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($customers as $cust) {
            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);

            $pdf->Cell(25, 6, $cust['customer_number'], 1, 0, 'C', $fill);
            $pdf->Cell(60, 6, substr($cust['name'], 0, 30), 1, 0, 'L', $fill);
            $pdf->Cell(35, 6, $cust['phone'] ?? 'N/A', 1, 0, 'C', $fill);
            $pdf->Cell(40, 6, number_format($cust['balance'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(35, 6, number_format($cust['credit_limit'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(25, 6, $cust['days_old'], 1, 0, 'C', $fill);
            $pdf->Cell(30, 6, $cust['last_sale_date'], 1, 1, 'C', $fill);

            $fill = !$fill;

            // Check for page break
            if ($pdf->GetY() > 180) {
                $pdf->AddPage();
                // Repeat header
                $pdf->SetFillColor($color[0], $color[1], $color[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->Cell(25, 7, 'Cust. No.', 1, 0, 'C', true);
                $pdf->Cell(60, 7, 'Customer Name', 1, 0, 'C', true);
                $pdf->Cell(35, 7, 'Phone', 1, 0, 'C', true);
                $pdf->Cell(40, 7, 'Balance (' . $currency . ')', 1, 0, 'C', true);
                $pdf->Cell(35, 7, 'Credit Limit', 1, 0, 'C', true);
                $pdf->Cell(25, 7, 'Days Old', 1, 0, 'C', true);
                $pdf->Cell(30, 7, 'Last Sale', 1, 1, 'C', true);
                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);
            }
        }

        $pdf->Ln(6);
    }

    /**
     * Outstanding summary boxes
     */
    private function addOutstandingSummaryBoxes($pdf, $summary, $currency, $primary, $danger)
    {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 6, 'SUMMARY OVERVIEW', 0, 1);
        $pdf->Ln(2);

        $boxW = 65;
        $boxH = 24;
        $gap = 5;
        $startX = 15;
        $y = $pdf->GetY();

        // Row
        $this->drawLargeMetricBox($pdf, $startX, $y, $boxW, $boxH,
            'TOTAL CUSTOMERS',
            number_format($summary['total_customers']),
            'With outstanding debt',
            $primary
        );

        $this->drawLargeMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH,
            'TOTAL OUTSTANDING',
            $currency . ' ' . number_format($summary['total_outstanding'], 2),
            'Total debt owed',
            $danger
        );

        $this->drawLargeMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH,
            'TOTAL CREDIT LIMIT',
            $currency . ' ' . number_format($summary['total_credit_limit'], 2),
            'Combined limit',
            $this->colors['hippie_blue']
        );

        $this->drawLargeMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH,
            'AVERAGE BALANCE',
            $currency . ' ' . number_format($summary['average_balance'], 2),
            'Per customer',
            $this->colors['warning']
        );

        $pdf->SetY($y + $boxH + 8);
    }

    /**
     * Outstanding customers table
     */
    private function addOutstandingCustomersTable($pdf, $customers, $currency, $primary)
    {
        if ($customers->isEmpty()) {
            $pdf->SetFont('Arial', 'I', 11);
            $pdf->SetTextColor(128, 128, 128);
            $pdf->Cell(0, 10, 'No customers with outstanding credits found.', 0, 1, 'C');
            return;
        }

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 6, 'CUSTOMER DETAILS', 0, 1);
        $pdf->Ln(2);

        // Table header
        $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);

        $pdf->Cell(23, 8, 'Cust. No.', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Customer Name', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Phone', 1, 0, 'C', true);
        $pdf->Cell(22, 8, 'Type', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Balance (' . $currency . ')', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Credit Limit', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Available', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Last Payment', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($customers as $cust) {
            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);

            $pdf->Cell(23, 7, $cust['customer_number'], 1, 0, 'C', $fill);
            $pdf->Cell(50, 7, substr($cust['name'], 0, 25), 1, 0, 'L', $fill);
            $pdf->Cell(30, 7, $cust['phone'] ?? 'N/A', 1, 0, 'C', $fill);
            $pdf->Cell(22, 7, ucfirst($cust['customer_type']), 1, 0, 'C', $fill);
            $pdf->Cell(35, 7, number_format($cust['current_balance'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(35, 7, number_format($cust['credit_limit'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(35, 7, number_format($cust['available_credit'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(30, 7, $cust['last_payment_date'], 1, 1, 'C', $fill);

            $fill = !$fill;

            // Check for page break
            if ($pdf->GetY() > 180) {
                $pdf->AddPage();
                // Repeat header
                $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->Cell(23, 8, 'Cust. No.', 1, 0, 'C', true);
                $pdf->Cell(50, 8, 'Customer Name', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Phone', 1, 0, 'C', true);
                $pdf->Cell(22, 8, 'Type', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Balance (' . $currency . ')', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Credit Limit', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Available', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Last Payment', 1, 1, 'C', true);
                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);
            }
        }
    }

    // =====================================================
    // SHARED PDF COMPONENTS (from SalesPdfReportController)
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

        // Date or period info
        if (isset($data['as_of_date'])) {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 6, 'As of: ' . date('F d, Y', strtotime($data['as_of_date'])), 0, 1, 'C');
        } elseif (isset($data['filters']['start_date'])) {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor(0, 0, 0);
            $period = date('F d, Y', strtotime($data['filters']['start_date'])) .
                      ' - ' .
                      date('F d, Y', strtotime($data['filters']['end_date']));
            $pdf->Cell(0, 6, $period, 0, 1, 'C');
        }

        $pdf->Ln(6);
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

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetXY($x, $y + 11);
        $pdf->Cell($w, 6, $value, 0, 0, 'C');
    }

    /**
     * Large metric box
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
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetXY($x, $y + 5);
        $pdf->Cell($w, 5, $title, 0, 0, 'C');

        // Value - dynamic font sizing
        $valueLength = strlen($value);
        if ($valueLength > 20) {
            $pdf->SetFont('Arial', 'B', 10);
        } elseif ($valueLength > 15) {
            $pdf->SetFont('Arial', 'B', 12);
        } else {
            $pdf->SetFont('Arial', 'B', 13);
        }

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($x, $y + 11);
        $pdf->Cell($w, 6, $value, 0, 0, 'C');

        // Subtitle
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetXY($x, $y + 19);
        $pdf->Cell($w, 4, $subtitle, 0, 0, 'C');
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
 * REPORT 4: ALL CUSTOMERS DEBT REPORT (LIST)
 * =====================================================
 * Shows debt summary for ALL customers with outstanding balances
 */
public function generateAllCustomersDebtReport(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'branch_id' => 'nullable|exists:branches,id',
            'business_id' => 'nullable|exists:businesses,id',
            'customer_type' => 'nullable|in:regular,vip,wholesale',
            'min_balance' => 'nullable|numeric|min:0',
            'show_only_with_balance' => 'nullable|boolean',
            'currency_code' => 'nullable|string|size:3',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $reportData = $this->getAllCustomersDebtData($request);
        return $this->generateAllCustomersDebtPDF($reportData);

    } catch (\Exception $e) {
        Log::error('Failed to generate all customers debt report PDF', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return serverErrorResponse('Failed to generate all customers debt report', $e->getMessage());
    }
}

/**
 * Get all customers debt data
 */
private function getAllCustomersDebtData(Request $request)
{
    $user = Auth::user();

    // Get currency information
    $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
    $currency = Currency::where('code', $currencyCode)->first();
    $currencySymbol = $currency ? $currency->symbol : '$';

    $businessId = $request->business_id ?? $user->business_id;
    $minBalance = $request->min_balance ?? 0;
    $showOnlyWithBalance = $request->show_only_with_balance ?? true;

    // Build customer query
    $customerQuery = Customer::query()
        ->where('business_id', $businessId);

    if ($request->customer_type) {
        $customerQuery->where('customer_type', $request->customer_type);
    }

    if ($showOnlyWithBalance) {
        $customerQuery->where('current_credit_balance', '>', $minBalance);
    }

    $customers = $customerQuery->get();

    // Get transaction data for each customer within date range
    $customersData = [];
    $grandTotalSales = 0;
    $grandTotalPayments = 0;
    $grandTotalBalance = 0;

    foreach ($customers as $customer) {
        // Get transactions for this customer in date range
        $transactions = CustomerCreditTransaction::where('customer_id', $customer->id)
            ->whereDate('created_at', '>=', $request->start_date)
            ->whereDate('created_at', '<=', $request->end_date);

        if ($request->branch_id) {
            $transactions->where('branch_id', $request->branch_id);
        }

        $transactions = $transactions->get();

        // Calculate customer totals
        $totalSales = $transactions->where('transaction_type', 'sale')->sum('amount');
        $totalPayments = $transactions->where('transaction_type', 'payment')->sum('amount');
        $totalAdjustments = $transactions->where('transaction_type', 'adjustment')->sum('amount');

        // Get last transaction
        $lastTransaction = CustomerCreditTransaction::where('customer_id', $customer->id)
            ->latest()
            ->first();

        // Only include customers with transactions in the period OR current balance
        if ($transactions->count() > 0 || $customer->current_credit_balance > 0) {
            $customersData[] = [
                'customer_number' => $customer->customer_number,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'customer_type' => $customer->customer_type,
                'credit_limit' => $customer->credit_limit,
                'current_balance' => $customer->current_credit_balance,
                'available_credit' => $customer->credit_limit - $customer->current_credit_balance,
                'period_sales' => $totalSales,
                'period_payments' => $totalPayments,
                'period_adjustments' => $totalAdjustments,
                'transaction_count' => $transactions->count(),
                'last_transaction_date' => $lastTransaction ? $lastTransaction->created_at->format('Y-m-d') : 'N/A',
            ];

            $grandTotalSales += $totalSales;
            $grandTotalPayments += $totalPayments;
            $grandTotalBalance += $customer->current_credit_balance;
        }
    }

    // Sort by current balance (highest first)
    usort($customersData, function($a, $b) {
        return $b['current_balance'] <=> $a['current_balance'];
    });

    // Summary metrics
    $summary = [
        'total_customers' => count($customersData),
        'total_current_balance' => $grandTotalBalance,
        'total_period_sales' => $grandTotalSales,
        'total_period_payments' => $grandTotalPayments,
        'average_balance' => count($customersData) > 0 ? $grandTotalBalance / count($customersData) : 0,
        'total_credit_limit' => collect($customersData)->sum('credit_limit'),
    ];

    return [
        'customers' => $customersData,
        'summary' => $summary,
        'currency' => $currencySymbol,
        'currency_code' => $currencyCode,
        'filters' => [
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'branch_id' => $request->branch_id,
            'customer_type' => $request->customer_type,
            'min_balance' => $minBalance,
        ],
        'business' => $user->business,
        'branch' => $request->branch_id ? $user->primaryBranch : null,
        'generated_by' => $user->name,
        'generated_at' => now()->format('Y-m-d H:i:s'),
    ];
}

/**
 * Generate all customers debt PDF
 */
private function generateAllCustomersDebtPDF($data)
{
    $pdf = new \FPDF('L', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->AddPage();

    $primary = $this->colors['matisse'];
    $danger = $this->colors['danger'];

    // HEADER
    $this->addProfessionalHeader($pdf, 'ALL CUSTOMERS DEBT REPORT', $data, $primary);

    // SUMMARY BOXES
    $this->addAllCustomersDebtSummaryBoxes($pdf, $data['summary'], $data['currency'], $primary, $danger);

    // CUSTOMERS TABLE
    $this->addAllCustomersDebtTable($pdf, $data['customers'], $data['currency'], $primary);

    // FOOTER
    $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

    $filename = 'all_customers_debt_report_' . date('Y-m-d') . '.pdf';
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
 * All customers debt summary boxes
 */
private function addAllCustomersDebtSummaryBoxes($pdf, $summary, $currency, $primary, $danger)
{
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
    $pdf->Cell(0, 6, 'SUMMARY OVERVIEW', 0, 1);
    $pdf->Ln(2);

    $boxW = 65;
    $boxH = 24;
    $gap = 5;
    $startX = 15;
    $y = $pdf->GetY();

    // Row 1
    $this->drawLargeMetricBox($pdf, $startX, $y, $boxW, $boxH,
        'TOTAL CUSTOMERS',
        number_format($summary['total_customers']),
        'With debt activity',
        $primary
    );

    $this->drawLargeMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH,
        'CURRENT BALANCE',
        $currency . ' ' . number_format($summary['total_current_balance'], 2),
        'Total outstanding',
        $danger
    );

    $this->drawLargeMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH,
        'PERIOD SALES',
        $currency . ' ' . number_format($summary['total_period_sales'], 2),
        'Credit sales in period',
        $this->colors['warning']
    );

    $this->drawLargeMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH,
        'PERIOD PAYMENTS',
        $currency . ' ' . number_format($summary['total_period_payments'], 2),
        'Payments received',
        $this->colors['success']
    );

    $pdf->SetY($y + $boxH + 8);
}

/**
 * All customers debt table
 */
private function addAllCustomersDebtTable($pdf, $customers, $currency, $primary)
{
    if (empty($customers)) {
        $pdf->SetFont('Arial', 'I', 11);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 10, 'No customers with debt activity found for this period.', 0, 1, 'C');
        return;
    }

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
    $pdf->Cell(0, 6, 'CUSTOMER DETAILS', 0, 1);
    $pdf->Ln(2);

    // Table header
    $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 7);

    $pdf->Cell(20, 8, 'Cust. No.', 1, 0, 'C', true);
    $pdf->Cell(45, 8, 'Customer Name', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Phone', 1, 0, 'C', true);
    $pdf->Cell(18, 8, 'Type', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Current Bal.', 1, 0, 'C', true);
    $pdf->Cell(28, 8, 'Credit Limit', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Period Sales', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Period Pmts', 1, 0, 'C', true);
    $pdf->Cell(18, 8, 'Trans.', 1, 0, 'C', true);
    $pdf->Cell(26, 8, 'Last Trans.', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 6);
    $pdf->SetTextColor(0, 0, 0);

    $fill = false;
    foreach ($customers as $cust) {
        $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);

        $pdf->Cell(20, 7, $cust['customer_number'], 1, 0, 'C', $fill);
        $pdf->Cell(45, 7, substr($cust['name'], 0, 25), 1, 0, 'L', $fill);
        $pdf->Cell(25, 7, substr($cust['phone'] ?? 'N/A', 0, 12), 1, 0, 'C', $fill);
        $pdf->Cell(18, 7, ucfirst($cust['customer_type']), 1, 0, 'C', $fill);
        $pdf->Cell(30, 7, $currency . ' ' . number_format($cust['current_balance'], 2), 1, 0, 'R', $fill);
        $pdf->Cell(28, 7, $currency . ' ' . number_format($cust['credit_limit'], 2), 1, 0, 'R', $fill);
        $pdf->Cell(30, 7, $currency . ' ' . number_format($cust['period_sales'], 2), 1, 0, 'R', $fill);
        $pdf->Cell(30, 7, $currency . ' ' . number_format($cust['period_payments'], 2), 1, 0, 'R', $fill);
        $pdf->Cell(18, 7, number_format($cust['transaction_count']), 1, 0, 'C', $fill);
        $pdf->Cell(26, 7, $cust['last_transaction_date'], 1, 1, 'C', $fill);

        $fill = !$fill;

        // Check for page break
        if ($pdf->GetY() > 180) {
            $pdf->AddPage();
            // Repeat header
            $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->Cell(20, 8, 'Cust. No.', 1, 0, 'C', true);
            $pdf->Cell(45, 8, 'Customer Name', 1, 0, 'C', true);
            $pdf->Cell(25, 8, 'Phone', 1, 0, 'C', true);
            $pdf->Cell(18, 8, 'Type', 1, 0, 'C', true);
            $pdf->Cell(30, 8, 'Current Bal.', 1, 0, 'C', true);
            $pdf->Cell(28, 8, 'Credit Limit', 1, 0, 'C', true);
            $pdf->Cell(30, 8, 'Period Sales', 1, 0, 'C', true);
            $pdf->Cell(30, 8, 'Period Pmts', 1, 0, 'C', true);
            $pdf->Cell(18, 8, 'Trans.', 1, 0, 'C', true);
            $pdf->Cell(26, 8, 'Last Trans.', 1, 1, 'C', true);
            $pdf->SetFont('Arial', '', 6);
            $pdf->SetTextColor(0, 0, 0);
        }
    }
}
}