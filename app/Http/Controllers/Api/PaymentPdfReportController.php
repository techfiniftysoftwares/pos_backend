<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\CashReconciliation;
use App\Models\CashMovement;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

// FPDF
require_once(base_path('vendor/setasign/fpdf/fpdf.php'));

class PaymentPdfReportController extends Controller
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
     * REPORT 9: PAYMENT METHOD REPORT
     * =====================================================
     * Comprehensive breakdown by payment methods
     */
    public function generatePaymentMethodReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'business_id' => 'nullable|exists:businesses,id',
                'branch_id' => 'nullable|exists:branches,id',
                'payment_method_id' => 'nullable|exists:payment_methods,id',
                'status' => 'nullable|in:completed,failed,refunded,pending',
                'payment_type' => 'nullable|in:payment,refund',
                'customer_id' => 'nullable|exists:customers,id',
                'cashier_id' => 'nullable|exists:users,id',
                'currency_code' => 'nullable|string|size:3',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getPaymentMethodReportData($request);
            return $this->generatePaymentMethodPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate payment method report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate payment method report', $e->getMessage());
        }
    }

    /**
     * Get payment method report data
     */
    private function getPaymentMethodReportData(Request $request)
    {
        $user = Auth::user();

        // Get currency information
        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        $businessId = $request->business_id ?? $user->business_id;
        $branchId = $request->branch_id ?? $user->primary_branch_id;

        // Build query for payments
        $query = Payment::with([
            'paymentMethod',
            'customer',
            'processedBy',
            'branch',
            'business'
        ]);

        $query->where('business_id', $businessId);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        // Date filtering
        $query->whereDate('payment_date', '>=', $request->start_date)
              ->whereDate('payment_date', '<=', $request->end_date);

        // Apply filters
        if ($request->payment_method_id) $query->where('payment_method_id', $request->payment_method_id);
        if ($request->status) $query->where('status', $request->status);
        if ($request->payment_type) $query->where('payment_type', $request->payment_type);
        if ($request->customer_id) $query->where('customer_id', $request->customer_id);
        if ($request->cashier_id) $query->where('processed_by', $request->cashier_id);

        $query->orderBy('payment_date', 'desc');
        $payments = $query->get();

        // Calculate summary metrics
        $summary = [
            'total_payments' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'total_fees' => $payments->sum('fee_amount'),
            'net_amount' => $payments->sum('net_amount'),
            'completed_payments' => $payments->where('status', 'completed')->count(),
            'failed_payments' => $payments->where('status', 'failed')->count(),
            'refunded_payments' => $payments->where('status', 'refunded')->count(),
            'completed_amount' => $payments->where('status', 'completed')->sum('amount'),
            'refunded_amount' => $payments->where('payment_type', 'refund')->sum('amount'),
            'average_payment' => $payments->count() > 0 ? $payments->sum('amount') / $payments->count() : 0,
        ];

        // Payment method breakdown
        $methodBreakdown = [];
        foreach ($payments as $payment) {
            $methodName = $payment->paymentMethod->name ?? 'Unknown';
            $methodId = $payment->payment_method_id;

            if (!isset($methodBreakdown[$methodId])) {
                $methodBreakdown[$methodId] = [
                    'name' => $methodName,
                    'type' => $payment->paymentMethod->type ?? 'N/A',
                    'count' => 0,
                    'total_amount' => 0,
                    'total_fees' => 0,
                    'net_amount' => 0,
                    'avg_transaction' => 0,
                    'completed_count' => 0,
                    'failed_count' => 0,
                    'refund_count' => 0,
                ];
            }

            $methodBreakdown[$methodId]['count']++;
            $methodBreakdown[$methodId]['total_amount'] += $payment->amount;
            $methodBreakdown[$methodId]['total_fees'] += $payment->fee_amount;
            $methodBreakdown[$methodId]['net_amount'] += $payment->net_amount;

            if ($payment->status === 'completed') $methodBreakdown[$methodId]['completed_count']++;
            if ($payment->status === 'failed') $methodBreakdown[$methodId]['failed_count']++;
            if ($payment->payment_type === 'refund') $methodBreakdown[$methodId]['refund_count']++;
        }

        // Calculate averages
        foreach ($methodBreakdown as $key => $method) {
            $methodBreakdown[$key]['avg_transaction'] = $method['count'] > 0
                ? $method['total_amount'] / $method['count']
                : 0;
        }

        // Sort by total amount descending
        usort($methodBreakdown, fn($a, $b) => $b['total_amount'] <=> $a['total_amount']);

        // Daily breakdown
        $dailyBreakdown = $payments->groupBy(fn($payment) => $payment->payment_date->format('Y-m-d'))
            ->map(fn($group) => [
                'date' => $group->first()->payment_date->format('M d, Y'),
                'count' => $group->count(),
                'amount' => $group->sum('amount'),
                'fees' => $group->sum('fee_amount'),
            ])->values();

        // Top transactions
        $topTransactions = $payments->sortByDesc('amount')->take(10)->values();

        return [
            'payments' => $payments,
            'summary' => $summary,
            'method_breakdown' => $methodBreakdown,
            'daily_breakdown' => $dailyBreakdown,
            'top_transactions' => $topTransactions,
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
     * Generate payment method PDF
     */
    private function generatePaymentMethodPDF($data)
    {
        $pdf = new \FPDF('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $primary = $this->colors['matisse'];
        $accent = $this->colors['sun'];

        // HEADER
        $this->addProfessionalHeader($pdf, 'PAYMENT METHOD REPORT', $data, $primary);

        // SUMMARY METRICS
        $this->addPaymentMethodSummaryBoxes($pdf, $data['summary'], $data['currency'], $primary, $accent);

        // PAYMENT METHOD BREAKDOWN TABLE
        $this->addPaymentMethodBreakdownTable($pdf, $data['method_breakdown'], $data['currency'], $primary);

        // DAILY BREAKDOWN
        if (!empty($data['daily_breakdown']) && count($data['daily_breakdown']) > 0) {
            $this->addDailyPaymentBreakdown($pdf, $data['daily_breakdown'], $data['currency'], $accent);
        }

        // TOP TRANSACTIONS
        if (!empty($data['top_transactions']) && count($data['top_transactions']) > 0) {
            $this->addTopPaymentTransactions($pdf, $data['top_transactions'], $data['currency'], $this->colors['hippie_blue']);
        }

        // FOOTER
        $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

        $filename = 'payment_method_report_' . date('Y-m-d') . '.pdf';
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
     * REPORT 10: PAYMENT RECONCILIATION REPORT
     * =====================================================
     * Reconciliation status and variance analysis
     */
    public function generatePaymentReconciliationReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'business_id' => 'nullable|exists:businesses,id',
                'branch_id' => 'nullable|exists:branches,id',
                'reconciliation_status' => 'nullable|in:reconciled,unreconciled,all',
                'payment_method_id' => 'nullable|exists:payment_methods,id',
                'status' => 'nullable|in:completed,failed,refunded',
                'cashier_id' => 'nullable|exists:users,id',
                'reconciled_by' => 'nullable|exists:users,id',
                'currency_code' => 'nullable|string|size:3',
                'min_variance' => 'nullable|numeric',
                'max_variance' => 'nullable|numeric',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getPaymentReconciliationReportData($request);
            return $this->generatePaymentReconciliationPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate payment reconciliation report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate payment reconciliation report', $e->getMessage());
        }
    }

    /**
     * Get payment reconciliation report data
     */
    private function getPaymentReconciliationReportData(Request $request)
    {
        $user = Auth::user();

        // Get currency information
        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        $businessId = $request->business_id ?? $user->business_id;
        $branchId = $request->branch_id ?? $user->primary_branch_id;

        // Build query for payments
        $query = Payment::with([
            'paymentMethod',
            'customer',
            'processedBy',
            'reconciledBy',
            'branch',
            'business'
        ]);

        $query->where('business_id', $businessId);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        // Date filtering
        $query->whereDate('payment_date', '>=', $request->start_date)
              ->whereDate('payment_date', '<=', $request->end_date);

        // Reconciliation status filter
        $reconciledStatus = $request->reconciliation_status ?? 'all';
        if ($reconciledStatus === 'reconciled') {
            $query->whereNotNull('reconciled_at');
        } elseif ($reconciledStatus === 'unreconciled') {
            $query->whereNull('reconciled_at');
        }

        // Apply other filters
        if ($request->payment_method_id) $query->where('payment_method_id', $request->payment_method_id);
        if ($request->status) $query->where('status', $request->status);
        if ($request->cashier_id) $query->where('processed_by', $request->cashier_id);
        if ($request->reconciled_by) $query->where('reconciled_by', $request->reconciled_by);

        $query->orderBy('payment_date', 'desc');
        $payments = $query->get();

        // Get cash reconciliations for the period
        $reconciliationsQuery = CashReconciliation::with(['branch', 'user', 'reconciledBy', 'approvedBy'])
            ->where('business_id', $businessId);

        if ($branchId) {
            $reconciliationsQuery->where('branch_id', $branchId);
        }

        $reconciliationsQuery->whereDate('reconciliation_date', '>=', $request->start_date)
                             ->whereDate('reconciliation_date', '<=', $request->end_date);

        // Variance filters
        if ($request->min_variance !== null || $request->max_variance !== null) {
            $reconciliationsQuery->where(function($q) use ($request) {
                if ($request->min_variance !== null) {
                    $q->whereRaw('(actual_cash - expected_cash) >= ?', [$request->min_variance]);
                }
                if ($request->max_variance !== null) {
                    $q->whereRaw('(actual_cash - expected_cash) <= ?', [$request->max_variance]);
                }
            });
        }

        $reconciliations = $reconciliationsQuery->orderBy('reconciliation_date', 'desc')->get();

        // Calculate payment metrics
        $reconciledPayments = $payments->whereNotNull('reconciled_at');
        $unreconciledPayments = $payments->whereNull('reconciled_at');

        $paymentSummary = [
            'total_payments' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'reconciled_count' => $reconciledPayments->count(),
            'reconciled_amount' => $reconciledPayments->sum('amount'),
            'unreconciled_count' => $unreconciledPayments->count(),
            'unreconciled_amount' => $unreconciledPayments->sum('amount'),
            'completed_payments' => $payments->where('status', 'completed')->count(),
            'failed_payments' => $payments->where('status', 'failed')->count(),
            'reconciliation_rate' => $payments->count() > 0
                ? ($reconciledPayments->count() / $payments->count()) * 100
                : 0,
        ];

        // Calculate reconciliation metrics
        $reconciliationSummary = [
            'total_reconciliations' => $reconciliations->count(),
            'total_variance' => $reconciliations->sum('variance'),
            'total_expected' => $reconciliations->sum('expected_cash'),
            'total_actual' => $reconciliations->sum('actual_cash'),
            'reconciliations_with_variance' => $reconciliations->filter(fn($r) => $r->variance != 0)->count(),
            'overages' => $reconciliations->filter(fn($r) => $r->variance > 0)->sum('variance'),
            'shortages' => abs($reconciliations->filter(fn($r) => $r->variance < 0)->sum('variance')),
            'approved_count' => $reconciliations->where('status', 'approved')->count(),
            'pending_count' => $reconciliations->where('status', 'pending')->count(),
            'disputed_count' => $reconciliations->where('status', 'disputed')->count(),
            'average_variance' => $reconciliations->count() > 0
                ? $reconciliations->sum('variance') / $reconciliations->count()
                : 0,
        ];

        // Payment method reconciliation breakdown
        $methodReconciliation = [];
        foreach ($payments as $payment) {
            $methodName = $payment->paymentMethod->name ?? 'Unknown';
            $methodId = $payment->payment_method_id;

            if (!isset($methodReconciliation[$methodId])) {
                $methodReconciliation[$methodId] = [
                    'name' => $methodName,
                    'total_count' => 0,
                    'total_amount' => 0,
                    'reconciled_count' => 0,
                    'reconciled_amount' => 0,
                    'unreconciled_count' => 0,
                    'unreconciled_amount' => 0,
                ];
            }

            $methodReconciliation[$methodId]['total_count']++;
            $methodReconciliation[$methodId]['total_amount'] += $payment->amount;

            if ($payment->reconciled_at) {
                $methodReconciliation[$methodId]['reconciled_count']++;
                $methodReconciliation[$methodId]['reconciled_amount'] += $payment->amount;
            } else {
                $methodReconciliation[$methodId]['unreconciled_count']++;
                $methodReconciliation[$methodId]['unreconciled_amount'] += $payment->amount;
            }
        }

        // Reconciliation details with variance
        $varianceDetails = $reconciliations->filter(fn($r) => $r->variance != 0)->map(function($rec) {
            return [
                'date' => $rec->reconciliation_date->format('M d, Y'),
                'shift' => ucfirst(str_replace('_', ' ', $rec->shift_type)),
                'user' => $rec->user->name ?? 'N/A',
                'expected' => $rec->expected_cash,
                'actual' => $rec->actual_cash,
                'variance' => $rec->variance,
                'status' => ucfirst($rec->status),
            ];
        })->values();

        return [
            'payments' => $payments,
            'reconciliations' => $reconciliations,
            'payment_summary' => $paymentSummary,
            'reconciliation_summary' => $reconciliationSummary,
            'method_reconciliation' => array_values($methodReconciliation),
            'variance_details' => $varianceDetails,
            'unreconciled_payments' => $unreconciledPayments->take(20),
            'currency' => $currencySymbol,
            'currency_code' => $currencyCode,
            'filters' => [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'branch_id' => $branchId,
                'reconciliation_status' => $reconciledStatus,
            ],
            'business' => $user->business,
            'branch' => $branchId ? $user->primaryBranch : null,
            'generated_by' => $user->name,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Generate payment reconciliation PDF
     */
    private function generatePaymentReconciliationPDF($data)
    {
        $pdf = new \FPDF('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $primary = $this->colors['matisse'];
        $accent = $this->colors['sun'];

        // HEADER
        $this->addProfessionalHeader($pdf, 'PAYMENT RECONCILIATION REPORT', $data, $primary);

        // PAYMENT SUMMARY
        $this->addReconciliationPaymentSummary($pdf, $data['payment_summary'], $data['currency'], $primary, $accent);

        // RECONCILIATION SUMMARY
        $this->addReconciliationVarianceSummary($pdf, $data['reconciliation_summary'], $data['currency'], $primary, $accent);

        // METHOD RECONCILIATION BREAKDOWN
        if (!empty($data['method_reconciliation'])) {
            $this->addMethodReconciliationTable($pdf, $data['method_reconciliation'], $data['currency'], $primary);
        }

        // VARIANCE DETAILS
        if (!empty($data['variance_details']) && count($data['variance_details']) > 0) {
            $this->addVarianceDetailsTable($pdf, $data['variance_details'], $data['currency'], $this->colors['danger']);
        }

        // UNRECONCILED PAYMENTS
        if (!empty($data['unreconciled_payments']) && count($data['unreconciled_payments']) > 0) {
            $this->addUnreconciledPaymentsTable($pdf, $data['unreconciled_payments'], $data['currency'], $this->colors['warning']);
        }

        // FOOTER
        $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

        $filename = 'payment_reconciliation_report_' . date('Y-m-d') . '.pdf';
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
     * REPORT 11: CASH RECONCILIATION REPORT
     * =====================================================
     * Detailed cash reconciliation and variance analysis
     */
    public function generateCashReconciliationReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'business_id' => 'nullable|exists:businesses,id',
                'branch_id' => 'nullable|exists:branches,id',
                'status' => 'nullable|in:pending,completed,approved,disputed',
                'shift_type' => 'nullable|in:morning,afternoon,evening,full_day',
                'cashier_id' => 'nullable|exists:users,id',
                'reconciled_by' => 'nullable|exists:users,id',
                'approved_by' => 'nullable|exists:users,id',
                'has_variance' => 'nullable|boolean',
                'variance_type' => 'nullable|in:over,short',
                'currency_code' => 'nullable|string|size:3',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getCashReconciliationReportData($request);
            return $this->generateCashReconciliationPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate cash reconciliation report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate cash reconciliation report', $e->getMessage());
        }
    }

    /**
     * Get cash reconciliation report data
     */
    private function getCashReconciliationReportData(Request $request)
    {
        $user = Auth::user();

        // Get currency information
        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        $businessId = $request->business_id ?? $user->business_id;
        $branchId = $request->branch_id ?? $user->primary_branch_id;

        // Build query for cash reconciliations
        $query = CashReconciliation::with([
            'branch',
            'user',
            'reconciledBy',
            'approvedBy',
            'cashMovements'
        ]);

        $query->where('business_id', $businessId);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        // Date filtering
        $query->whereDate('reconciliation_date', '>=', $request->start_date)
              ->whereDate('reconciliation_date', '<=', $request->end_date);

        // Apply filters
        if ($request->status) $query->where('status', $request->status);
        if ($request->shift_type) $query->where('shift_type', $request->shift_type);
        if ($request->cashier_id) $query->where('user_id', $request->cashier_id);
        if ($request->reconciled_by) $query->where('reconciled_by', $request->reconciled_by);
        if ($request->approved_by) $query->where('approved_by', $request->approved_by);

        // Variance filters
        if ($request->has_variance !== null) {
            if ($request->has_variance) {
                $query->whereRaw('(actual_cash - expected_cash) != 0');
            } else {
                $query->whereRaw('(actual_cash - expected_cash) = 0');
            }
        }

        if ($request->variance_type === 'over') {
            $query->whereRaw('(actual_cash - expected_cash) > 0');
        } elseif ($request->variance_type === 'short') {
            $query->whereRaw('(actual_cash - expected_cash) < 0');
        }

        $query->orderBy('reconciliation_date', 'desc');
        $reconciliations = $query->get();

        // Calculate summary metrics
        $summary = [
            'total_reconciliations' => $reconciliations->count(),
            'total_expected' => $reconciliations->sum('expected_cash'),
            'total_actual' => $reconciliations->sum('actual_cash'),
            'total_variance' => $reconciliations->sum('variance'),
            'total_opening_float' => $reconciliations->sum('opening_float'),
            'total_cash_sales' => $reconciliations->sum('cash_sales'),
            'total_cash_drops' => $reconciliations->sum('cash_drops'),
            'total_expenses' => $reconciliations->sum('cash_expenses'),
            'approved_count' => $reconciliations->where('status', 'approved')->count(),
            'pending_count' => $reconciliations->where('status', 'pending')->count(),
            'disputed_count' => $reconciliations->where('status', 'disputed')->count(),
            'completed_count' => $reconciliations->where('status', 'completed')->count(),
            'with_variance_count' => $reconciliations->filter(fn($r) => $r->variance != 0)->count(),
            'overages' => $reconciliations->filter(fn($r) => $r->variance > 0)->sum('variance'),
            'shortages' => abs($reconciliations->filter(fn($r) => $r->variance < 0)->sum('variance')),
            'average_variance' => $reconciliations->count() > 0
                ? $reconciliations->sum('variance') / $reconciliations->count()
                : 0,
            'variance_rate' => $reconciliations->count() > 0
                ? ($reconciliations->filter(fn($r) => $r->variance != 0)->count() / $reconciliations->count()) * 100
                : 0,
        ];

        // Shift type breakdown
        $shiftBreakdown = [];
        foreach ($reconciliations as $rec) {
            $shift = ucfirst(str_replace('_', ' ', $rec->shift_type));

            if (!isset($shiftBreakdown[$shift])) {
                $shiftBreakdown[$shift] = [
                    'count' => 0,
                    'expected' => 0,
                    'actual' => 0,
                    'variance' => 0,
                ];
            }

            $shiftBreakdown[$shift]['count']++;
            $shiftBreakdown[$shift]['expected'] += $rec->expected_cash;
            $shiftBreakdown[$shift]['actual'] += $rec->actual_cash;
            $shiftBreakdown[$shift]['variance'] += $rec->variance;
        }

        // Daily reconciliation summary
        $dailySummary = $reconciliations->groupBy(fn($rec) => $rec->reconciliation_date->format('Y-m-d'))
            ->map(fn($group) => [
                'date' => $group->first()->reconciliation_date->format('M d, Y'),
                'count' => $group->count(),
                'expected' => $group->sum('expected_cash'),
                'actual' => $group->sum('actual_cash'),
                'variance' => $group->sum('variance'),
            ])->values();

        // Variance details (top variances)
        $varianceDetails = $reconciliations->filter(fn($r) => $r->variance != 0)
            ->sortByDesc(fn($r) => abs($r->variance))
            ->take(15)
            ->map(function($rec) {
                return [
                    'date' => $rec->reconciliation_date->format('M d, Y'),
                    'shift' => ucfirst(str_replace('_', ' ', $rec->shift_type)),
                    'user' => $rec->user->name ?? 'N/A',
                    'expected' => $rec->expected_cash,
                    'actual' => $rec->actual_cash,
                    'variance' => $rec->variance,
                    'variance_pct' => $rec->expected_cash > 0
                        ? ($rec->variance / $rec->expected_cash) * 100
                        : 0,
                    'status' => ucfirst($rec->status),
                ];
            })->values();

        // Cashier performance
        $cashierPerformance = [];
        foreach ($reconciliations as $rec) {
            $userId = $rec->user_id;
            $userName = $rec->user->name ?? 'Unknown';

            if (!isset($cashierPerformance[$userId])) {
                $cashierPerformance[$userId] = [
                    'name' => $userName,
                    'reconciliations' => 0,
                    'total_variance' => 0,
                    'overages' => 0,
                    'shortages' => 0,
                ];
            }

            $cashierPerformance[$userId]['reconciliations']++;
            $cashierPerformance[$userId]['total_variance'] += $rec->variance;

            if ($rec->variance > 0) {
                $cashierPerformance[$userId]['overages'] += $rec->variance;
            } else {
                $cashierPerformance[$userId]['shortages'] += abs($rec->variance);
            }
        }

        // Sort cashiers by absolute variance
        usort($cashierPerformance, fn($a, $b) => abs($b['total_variance']) <=> abs($a['total_variance']));

        return [
            'reconciliations' => $reconciliations,
            'summary' => $summary,
            'shift_breakdown' => array_values($shiftBreakdown),
            'daily_summary' => $dailySummary,
            'variance_details' => $varianceDetails,
            'cashier_performance' => array_slice($cashierPerformance, 0, 10),
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
     * Generate cash reconciliation PDF
     */
    private function generateCashReconciliationPDF($data)
    {
        $pdf = new \FPDF('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $primary = $this->colors['matisse'];
        $accent = $this->colors['sun'];

        // HEADER
        $this->addProfessionalHeader($pdf, 'CASH RECONCILIATION REPORT', $data, $primary);

        // SUMMARY METRICS
        $this->addCashReconciliationSummaryBoxes($pdf, $data['summary'], $data['currency'], $primary, $accent);

        // SHIFT BREAKDOWN
        if (!empty($data['shift_breakdown'])) {
            $this->addShiftBreakdownTable($pdf, $data['shift_breakdown'], $data['currency'], $primary);
        }

        // DAILY SUMMARY
        if (!empty($data['daily_summary']) && count($data['daily_summary']) > 0) {
            $this->addDailyCashReconciliationTable($pdf, $data['daily_summary'], $data['currency'], $accent);
        }

        // VARIANCE DETAILS (Top Issues)
        if (!empty($data['variance_details']) && count($data['variance_details']) > 0) {
            $this->addCashVarianceDetailsTable($pdf, $data['variance_details'], $data['currency'], $this->colors['danger']);
        }

        // CASHIER PERFORMANCE
        if (!empty($data['cashier_performance'])) {
            $this->addCashierPerformanceTable($pdf, $data['cashier_performance'], $data['currency'], $this->colors['hippie_blue']);
        }

        // FOOTER
        $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

        $filename = 'cash_reconciliation_report_' . date('Y-m-d') . '.pdf';
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
    // SHARED PDF COMPONENTS
    // =====================================================

    /**
     * Professional header
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
        $period = date('F d, Y', strtotime($data['filters']['start_date'])) .
                  ' - ' .
                  date('F d, Y', strtotime($data['filters']['end_date']));
        $pdf->Cell(0, 6, $period, 0, 1, 'C');

        // Subtitle
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(100, 100, 100);

        $subtitle = '';
        if (isset($data['business'])) {
            $subtitle .= $data['business']->name;
        }
        if (isset($data['branch'])) {
            $subtitle .= ' - ' . $data['branch']->name;
        }

        $pdf->Cell(0, 5, $subtitle ?: 'All Branches', 0, 1, 'C');

        $pdf->Ln(8);
    }

    /**
     * Payment method summary boxes
     */
    private function addPaymentMethodSummaryBoxes($pdf, $summary, $currency, $primary, $accent)
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
        $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL PAYMENTS', number_format($summary['total_payments']), $primary);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'TOTAL AMOUNT', $currency . ' ' . number_format($summary['total_amount'], 2), $accent);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'TOTAL FEES', $currency . ' ' . number_format($summary['total_fees'], 2), $this->colors['warning']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'NET AMOUNT', $currency . ' ' . number_format($summary['net_amount'], 2), $this->colors['success']);

        // Row 2
        $y += $boxH + $gap;
        $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'COMPLETED', number_format($summary['completed_payments']), $this->colors['success']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'FAILED', number_format($summary['failed_payments']), $this->colors['danger']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'REFUNDED', number_format($summary['refunded_payments']), $this->colors['warning']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'AVG PAYMENT', $currency . ' ' . number_format($summary['average_payment'], 2), $this->colors['hippie_blue']);

        $pdf->SetY($y + $boxH + 8);
    }

    /**
     * Payment method breakdown table
     */
    private function addPaymentMethodBreakdownTable($pdf, $methods, $currency, $primary)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'PAYMENT METHOD BREAKDOWN', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);

        $pdf->Cell(55, 8, 'Payment Method', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Type', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Count', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Amount', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Fees', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Net', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Avg', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($methods as $method) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

            $pdf->Cell(55, 7, substr($method['name'], 0, 25), 1, 0, 'L', $fill);
            $pdf->Cell(25, 7, substr(ucfirst($method['type']), 0, 12), 1, 0, 'L', $fill);
            $pdf->Cell(30, 7, number_format($method['count']), 1, 0, 'C', $fill);
            $pdf->Cell(40, 7, $currency . ' ' . number_format($method['total_amount'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(35, 7, $currency . ' ' . number_format($method['total_fees'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(40, 7, $currency . ' ' . number_format($method['net_amount'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(30, 7, $currency . ' ' . number_format($method['avg_transaction'], 2), 1, 1, 'R', $fill);

            $fill = !$fill;

            if ($pdf->GetY() > 180) {
                $pdf->AddPage();
                // Repeat header
                $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->Cell(55, 8, 'Payment Method', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Type', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Count', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Amount', 1, 0, 'C', true);
                $pdf->Cell(35, 8, 'Fees', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Net', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Avg', 1, 1, 'C', true);
                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);
            }
        }

        $pdf->Ln(6);
    }

    /**
     * Daily payment breakdown
     */
    private function addDailyPaymentBreakdown($pdf, $daily, $currency, $accent)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
        $pdf->Cell(0, 7, 'DAILY PAYMENT BREAKDOWN', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);

        $pdf->Cell(70, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(70, 8, 'Transaction Count', 1, 0, 'C', true);
        $pdf->Cell(70, 8, 'Total Amount', 1, 0, 'C', true);
        $pdf->Cell(70, 8, 'Total Fees', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($daily as $day) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

            $pdf->Cell(70, 7, $day['date'], 1, 0, 'L', $fill);
            $pdf->Cell(70, 7, number_format($day['count']), 1, 0, 'C', $fill);
            $pdf->Cell(70, 7, $currency . ' ' . number_format($day['amount'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(70, 7, $currency . ' ' . number_format($day['fees'], 2), 1, 1, 'R', $fill);

            $fill = !$fill;
        }

        $pdf->Ln(6);
    }

    /**
     * Top payment transactions
     */
    private function addTopPaymentTransactions($pdf, $transactions, $currency, $color)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->Cell(0, 7, 'TOP 10 PAYMENT TRANSACTIONS', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($color[0], $color[1], $color[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);

        $pdf->Cell(15, 8, '#', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Reference', 1, 0, 'C', true);
        $pdf->Cell(45, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(60, 8, 'Payment Method', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Customer', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Amount', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        $rank = 1;
        foreach ($transactions as $payment) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

            $pdf->Cell(15, 7, $rank, 1, 0, 'C', $fill);
            $pdf->Cell(50, 7, substr($payment->reference_number, 0, 20), 1, 0, 'L', $fill);
            $pdf->Cell(45, 7, $payment->payment_date->format('M d, Y H:i'), 1, 0, 'C', $fill);
            $pdf->Cell(60, 7, substr($payment->paymentMethod->name ?? 'N/A', 0, 25), 1, 0, 'L', $fill);
            $pdf->Cell(50, 7, substr($payment->customer->name ?? 'Walk-in', 0, 20), 1, 0, 'L', $fill);
            $pdf->Cell(35, 7, $currency . ' ' . number_format($payment->amount, 2), 1, 1, 'R', $fill);

            $fill = !$fill;
            $rank++;
        }

        $pdf->Ln(6);
    }

    /**
     * Reconciliation payment summary boxes
     */
    private function addReconciliationPaymentSummary($pdf, $summary, $currency, $primary, $accent)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'PAYMENT RECONCILIATION SUMMARY', 0, 1);
        $pdf->Ln(2);

        $boxW = 65;
        $boxH = 22;
        $gap = 5;
        $startX = 15;
        $y = $pdf->GetY();

        // Row 1
        $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL PAYMENTS', number_format($summary['total_payments']), $primary);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'TOTAL AMOUNT', $currency . ' ' . number_format($summary['total_amount'], 2), $accent);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'RECONCILED', number_format($summary['reconciled_count']) . ' (' . number_format($summary['reconciliation_rate'], 1) . '%)', $this->colors['success']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'UNRECONCILED', number_format($summary['unreconciled_count']), $this->colors['warning']);

        $pdf->SetY($y + $boxH + 6);
    }

    /**
     * Reconciliation variance summary boxes
     */
    private function addReconciliationVarianceSummary($pdf, $summary, $currency, $primary, $accent)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
        $pdf->Cell(0, 7, 'CASH RECONCILIATION VARIANCE SUMMARY', 0, 1);
        $pdf->Ln(2);

        $boxW = 65;
        $boxH = 22;
        $gap = 5;
        $startX = 15;
        $y = $pdf->GetY();

        // Row 1
        $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL VARIANCE', $currency . ' ' . number_format($summary['total_variance'], 2), $primary);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'AVG VARIANCE', $currency . ' ' . number_format($summary['average_variance'], 2), $accent);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'OVERAGES', $currency . ' ' . number_format($summary['overages'], 2), $this->colors['success']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'SHORTAGES', $currency . ' ' . number_format($summary['shortages'], 2), $this->colors['danger']);

        $pdf->SetY($y + $boxH + 6);
    }

    /**
     * Method reconciliation table
     */
    private function addMethodReconciliationTable($pdf, $methods, $currency, $primary)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'RECONCILIATION STATUS BY PAYMENT METHOD', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);

        $pdf->Cell(70, 8, 'Payment Method', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Total', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Reconciled', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Unreconciled', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Reconciled Amt', 1, 0, 'C', true);
        $pdf->Cell(45, 8, 'Unreconciled Amt', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($methods as $method) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

            $pdf->Cell(70, 7, substr($method['name'], 0, 30), 1, 0, 'L', $fill);
            $pdf->Cell(35, 7, number_format($method['total_count']), 1, 0, 'C', $fill);
            $pdf->Cell(35, 7, number_format($method['reconciled_count']), 1, 0, 'C', $fill);
            $pdf->Cell(40, 7, number_format($method['unreconciled_count']), 1, 0, 'C', $fill);
            $pdf->Cell(40, 7, $currency . ' ' . number_format($method['reconciled_amount'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(45, 7, $currency . ' ' . number_format($method['unreconciled_amount'], 2), 1, 1, 'R', $fill);

            $fill = !$fill;
        }

        $pdf->Ln(6);
    }

    /**
     * Variance details table
     */
    private function addVarianceDetailsTable($pdf, $variances, $currency, $color)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->Cell(0, 7, 'CASH RECONCILIATION VARIANCES', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($color[0], $color[1], $color[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);

        $pdf->Cell(45, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(45, 8, 'Shift', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'User', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Expected', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Actual', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Variance', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Status', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($variances as $variance) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

            $pdf->Cell(45, 7, $variance['date'], 1, 0, 'L', $fill);
            $pdf->Cell(45, 7, $variance['shift'], 1, 0, 'L', $fill);
            $pdf->Cell(50, 7, substr($variance['user'], 0, 20), 1, 0, 'L', $fill);
            $pdf->Cell(40, 7, $currency . ' ' . number_format($variance['expected'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(40, 7, $currency . ' ' . number_format($variance['actual'], 2), 1, 0, 'R', $fill);

            // Color code variance
            $varianceValue = $variance['variance'];
            if ($varianceValue > 0) {
                $pdf->SetTextColor($this->colors['success'][0], $this->colors['success'][1], $this->colors['success'][2]);
            } elseif ($varianceValue < 0) {
                $pdf->SetTextColor($this->colors['danger'][0], $this->colors['danger'][1], $this->colors['danger'][2]);
            }

            $pdf->Cell(40, 7, $currency . ' ' . number_format($varianceValue, 2), 1, 0, 'R', $fill);

            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(25, 7, substr($variance['status'], 0, 10), 1, 1, 'C', $fill);

            $fill = !$fill;
        }

        $pdf->Ln(6);
    }

    /**
     * Unreconciled payments table
     */
    private function addUnreconciledPaymentsTable($pdf, $payments, $currency, $color)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->Cell(0, 7, 'UNRECONCILED PAYMENTS (Top 20)', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($color[0], $color[1], $color[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);

        $pdf->Cell(50, 8, 'Reference', 1, 0, 'C', true);
        $pdf->Cell(45, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(60, 8, 'Payment Method', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Customer', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Amount', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Status', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($payments as $payment) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

            $pdf->Cell(50, 7, substr($payment->reference_number, 0, 20), 1, 0, 'L', $fill);
            $pdf->Cell(45, 7, $payment->payment_date->format('M d, Y H:i'), 1, 0, 'C', $fill);
            $pdf->Cell(60, 7, substr($payment->paymentMethod->name ?? 'N/A', 0, 25), 1, 0, 'L', $fill);
            $pdf->Cell(50, 7, substr($payment->customer->name ?? 'Walk-in', 0, 20), 1, 0, 'L', $fill);
            $pdf->Cell(30, 7, $currency . ' ' . number_format($payment->amount, 2), 1, 0, 'R', $fill);
            $pdf->Cell(25, 7, ucfirst($payment->status), 1, 1, 'C', $fill);

            $fill = !$fill;

            if ($pdf->GetY() > 180) {
                $pdf->AddPage();
                // Repeat header
                $pdf->SetFillColor($color[0], $color[1], $color[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->Cell(50, 8, 'Reference', 1, 0, 'C', true);
                $pdf->Cell(45, 8, 'Date', 1, 0, 'C', true);
                $pdf->Cell(60, 8, 'Payment Method', 1, 0, 'C', true);
                $pdf->Cell(50, 8, 'Customer', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Amount', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Status', 1, 1, 'C', true);
                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);
            }
        }

        $pdf->Ln(6);
    }

    /**
     * Cash reconciliation summary boxes
     */
    private function addCashReconciliationSummaryBoxes($pdf, $summary, $currency, $primary, $accent)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'RECONCILIATION SUMMARY', 0, 1);
        $pdf->Ln(2);

        $boxW = 65;
        $boxH = 22;
        $gap = 5;
        $startX = 15;
        $y = $pdf->GetY();

        // Row 1
        $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL RECONCILIATIONS', number_format($summary['total_reconciliations']), $primary);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'EXPECTED CASH', $currency . ' ' . number_format($summary['total_expected'], 2), $accent);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'ACTUAL CASH', $currency . ' ' . number_format($summary['total_actual'], 2), $this->colors['hippie_blue']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'TOTAL VARIANCE', $currency . ' ' . number_format($summary['total_variance'], 2), $this->colors['warning']);

        // Row 2
        $y += $boxH + $gap;
        $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'APPROVED', number_format($summary['approved_count']), $this->colors['success']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'PENDING', number_format($summary['pending_count']), $this->colors['warning']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'OVERAGES', $currency . ' ' . number_format($summary['overages'], 2), $this->colors['success']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'SHORTAGES', $currency . ' ' . number_format($summary['shortages'], 2), $this->colors['danger']);

        $pdf->SetY($y + $boxH + 8);
    }

    /**
     * Shift breakdown table
     */
    private function addShiftBreakdownTable($pdf, $shifts, $currency, $primary)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'BREAKDOWN BY SHIFT TYPE', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);

        $pdf->Cell(70, 8, 'Shift Type', 1, 0, 'C', true);
        $pdf->Cell(55, 8, 'Count', 1, 0, 'C', true);
        $pdf->Cell(55, 8, 'Expected', 1, 0, 'C', true);
        $pdf->Cell(55, 8, 'Actual', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Variance', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($shifts as $shift) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

            $pdf->Cell(70, 7, $shift['count'] > 0 ? array_keys($shifts)[array_search($shift, $shifts)] : '', 1, 0, 'L', $fill);
            $pdf->Cell(55, 7, number_format($shift['count']), 1, 0, 'C', $fill);
            $pdf->Cell(55, 7, $currency . ' ' . number_format($shift['expected'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(55, 7, $currency . ' ' . number_format($shift['actual'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(50, 7, $currency . ' ' . number_format($shift['variance'], 2), 1, 1, 'R', $fill);

            $fill = !$fill;
        }

        $pdf->Ln(6);
    }

    /**
     * Daily cash reconciliation table
     */
    private function addDailyCashReconciliationTable($pdf, $daily, $currency, $accent)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
        $pdf->Cell(0, 7, 'DAILY RECONCILIATION SUMMARY', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);

        $pdf->Cell(58, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(45, 8, 'Sessions', 1, 0, 'C', true);
        $pdf->Cell(57, 8, 'Expected', 1, 0, 'C', true);
        $pdf->Cell(57, 8, 'Actual', 1, 0, 'C', true);
        $pdf->Cell(58, 8, 'Variance', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($daily as $day) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

            $pdf->Cell(58, 7, $day['date'], 1, 0, 'L', $fill);
            $pdf->Cell(45, 7, number_format($day['count']), 1, 0, 'C', $fill);
            $pdf->Cell(57, 7, $currency . ' ' . number_format($day['expected'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(57, 7, $currency . ' ' . number_format($day['actual'], 2), 1, 0, 'R', $fill);

            // Color code variance
            $varianceValue = $day['variance'];
            if ($varianceValue > 0) {
                $pdf->SetTextColor($this->colors['success'][0], $this->colors['success'][1], $this->colors['success'][2]);
            } elseif ($varianceValue < 0) {
                $pdf->SetTextColor($this->colors['danger'][0], $this->colors['danger'][1], $this->colors['danger'][2]);
            }

            $pdf->Cell(58, 7, $currency . ' ' . number_format($varianceValue, 2), 1, 1, 'R', $fill);

            $pdf->SetTextColor(0, 0, 0);
            $fill = !$fill;
        }

        $pdf->Ln(6);
    }

    /**
     * Cash variance details table
     */
    private function addCashVarianceDetailsTable($pdf, $variances, $currency, $color)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->Cell(0, 7, 'TOP VARIANCES (Highest Issues)', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($color[0], $color[1], $color[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);

        $pdf->Cell(40, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Shift', 1, 0, 'C', true);
        $pdf->Cell(45, 8, 'Cashier', 1, 0, 'C', true);
        $pdf->Cell(37, 8, 'Expected', 1, 0, 'C', true);
        $pdf->Cell(37, 8, 'Actual', 1, 0, 'C', true);
        $pdf->Cell(37, 8, 'Variance', 1, 0, 'C', true);
        $pdf->Cell(25, 8, '%', 1, 0, 'C', true);
        $pdf->Cell(24, 8, 'Status', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($variances as $variance) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

            $pdf->Cell(40, 7, $variance['date'], 1, 0, 'L', $fill);
            $pdf->Cell(40, 7, $variance['shift'], 1, 0, 'L', $fill);
            $pdf->Cell(45, 7, substr($variance['user'], 0, 18), 1, 0, 'L', $fill);
            $pdf->Cell(37, 7, $currency . number_format($variance['expected'], 0), 1, 0, 'R', $fill);
            $pdf->Cell(37, 7, $currency . number_format($variance['actual'], 0), 1, 0, 'R', $fill);

            // Color code variance
            $varianceValue = $variance['variance'];
            if ($varianceValue > 0) {
                $pdf->SetTextColor($this->colors['success'][0], $this->colors['success'][1], $this->colors['success'][2]);
            } elseif ($varianceValue < 0) {
                $pdf->SetTextColor($this->colors['danger'][0], $this->colors['danger'][1], $this->colors['danger'][2]);
            }

            $pdf->Cell(37, 7, $currency . number_format($varianceValue, 2), 1, 0, 'R', $fill);

            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(25, 7, number_format($variance['variance_pct'], 1) . '%', 1, 0, 'R', $fill);
            $pdf->Cell(24, 7, substr($variance['status'], 0, 8), 1, 1, 'C', $fill);

            $fill = !$fill;
        }

        $pdf->Ln(6);
    }

    /**
     * Cashier performance table
     */
    private function addCashierPerformanceTable($pdf, $cashiers, $currency, $color)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->Cell(0, 7, 'CASHIER PERFORMANCE ANALYSIS', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($color[0], $color[1], $color[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);

        $pdf->Cell(80, 8, 'Cashier Name', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Reconciliations', 1, 0, 'C', true);
        $pdf->Cell(55, 8, 'Total Variance', 1, 0, 'C', true);
        $pdf->Cell(55, 8, 'Overages', 1, 0, 'C', true);
        $pdf->Cell(45, 8, 'Shortages', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($cashiers as $cashier) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

            $pdf->Cell(80, 7, substr($cashier['name'], 0, 35), 1, 0, 'L', $fill);
            $pdf->Cell(50, 7, number_format($cashier['reconciliations']), 1, 0, 'C', $fill);

            // Color code total variance
            $totalVariance = $cashier['total_variance'];
            if ($totalVariance > 0) {
                $pdf->SetTextColor($this->colors['success'][0], $this->colors['success'][1], $this->colors['success'][2]);
            } elseif ($totalVariance < 0) {
                $pdf->SetTextColor($this->colors['danger'][0], $this->colors['danger'][1], $this->colors['danger'][2]);
            }

            $pdf->Cell(55, 7, $currency . ' ' . number_format($totalVariance, 2), 1, 0, 'R', $fill);

            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(55, 7, $currency . ' ' . number_format($cashier['overages'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(45, 7, $currency . ' ' . number_format($cashier['shortages'], 2), 1, 1, 'R', $fill);

            $fill = !$fill;
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

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetXY($x, $y + 11);
        $pdf->Cell($w, 6, $value, 0, 0, 'C');
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
 * REPORT 12: CASH REGISTER SESSION REPORT
 * =====================================================
 * Detailed individual or multiple register sessions
 */
public function generateCashRegisterSessionReport(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'business_id' => 'nullable|exists:businesses,id',
            'branch_id' => 'nullable|exists:branches,id',
            'session_id' => 'nullable|exists:cash_reconciliations,id',
            'cashier_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:pending,completed,approved,disputed',
            'shift_type' => 'nullable|in:morning,afternoon,evening,full_day',
            'currency_code' => 'nullable|string|size:3',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $reportData = $this->getCashRegisterSessionReportData($request);
        return $this->generateCashRegisterSessionPDF($reportData);

    } catch (\Exception $e) {
        Log::error('Failed to generate cash register session report', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return serverErrorResponse('Failed to generate cash register session report', $e->getMessage());
    }
}

/**
 * Get cash register session report data
 */
private function getCashRegisterSessionReportData(Request $request)
{
    $user = Auth::user();

    // Get currency information
    $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
    $currency = Currency::where('code', $currencyCode)->first();
    $currencySymbol = $currency ? $currency->symbol : '$';

    $businessId = $request->business_id ?? $user->business_id;
    $branchId = $request->branch_id ?? $user->primary_branch_id;

    // Build query for sessions
    $query = CashReconciliation::with([
        'branch',
        'user',
        'reconciledBy',
        'approvedBy',
        'cashMovements.processedBy'
    ]);

    $query->where('business_id', $businessId);
    if ($branchId) {
        $query->where('branch_id', $branchId);
    }

    // Specific session or date range
    if ($request->session_id) {
        $query->where('id', $request->session_id);
    } else {
        $query->whereDate('reconciliation_date', '>=', $request->start_date)
              ->whereDate('reconciliation_date', '<=', $request->end_date);
    }

    // Apply filters
    if ($request->cashier_id) $query->where('user_id', $request->cashier_id);
    if ($request->status) $query->where('status', $request->status);
    if ($request->shift_type) $query->where('shift_type', $request->shift_type);

    $query->orderBy('reconciliation_date', 'desc');
    $sessions = $query->get();

    // Get payments for these sessions (cash payments during session times)
    $paymentsBySession = [];
    foreach ($sessions as $session) {
        $sessionStart = $session->created_at;
        $sessionEnd = $session->updated_at ?? now();

        $payments = Payment::with(['paymentMethod', 'customer'])
            ->where('business_id', $businessId)
            ->where('branch_id', $session->branch_id)
            ->where('status', 'completed')
            ->whereBetween('payment_date', [$sessionStart, $sessionEnd])
            ->whereHas('paymentMethod', function($q) {
                $q->where('type', 'cash');
            })
            ->get();

        $paymentsBySession[$session->id] = $payments;
    }

    // Calculate summary metrics
    $summary = [
        'total_sessions' => $sessions->count(),
        'total_opening_float' => $sessions->sum('opening_float'),
        'total_expected_cash' => $sessions->sum('expected_cash'),
        'total_actual_cash' => $sessions->sum('actual_cash'),
        'total_variance' => $sessions->sum('variance'),
        'total_cash_sales' => $sessions->sum('cash_sales'),
        'total_cash_drops' => $sessions->sum('cash_drops'),
        'total_expenses' => $sessions->sum('cash_expenses'),
        'total_refunds' => $sessions->sum('cash_refunds'),
        'approved_sessions' => $sessions->where('status', 'approved')->count(),
        'pending_sessions' => $sessions->where('status', 'pending')->count(),
        'sessions_with_variance' => $sessions->filter(fn($s) => $s->variance != 0)->count(),
        'average_session_value' => $sessions->count() > 0
            ? $sessions->sum('expected_cash') / $sessions->count()
            : 0,
    ];

    // Session details with movements
    $sessionDetails = $sessions->map(function($session) use ($paymentsBySession, $currencySymbol) {
        $movements = $session->cashMovements;
        $payments = $paymentsBySession[$session->id] ?? collect();

        return [
            'session' => $session,
            'movements' => $movements,
            'payments' => $payments,
            'movement_summary' => [
                'cash_drops' => $movements->where('movement_type', 'cash_drop')->sum('amount'),
                'expenses' => $movements->where('movement_type', 'expense')->sum('amount'),
                'cash_in' => $movements->where('movement_type', 'cash_in')->sum('amount'),
                'cash_out' => $movements->where('movement_type', 'cash_out')->sum('amount'),
            ],
            'payment_summary' => [
                'count' => $payments->count(),
                'total' => $payments->sum('amount'),
            ],
        ];
    });

    return [
        'sessions' => $sessions,
        'session_details' => $sessionDetails,
        'summary' => $summary,
        'currency' => $currencySymbol,
        'currency_code' => $currencyCode,
        'filters' => [
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'branch_id' => $branchId,
            'session_id' => $request->session_id,
        ],
        'business' => $user->business,
        'branch' => $branchId ? $user->primaryBranch : null,
        'generated_by' => $user->name,
        'generated_at' => now()->format('Y-m-d H:i:s'),
    ];
}

/**
 * Generate cash register session PDF
 */
private function generateCashRegisterSessionPDF($data)
{
    $pdf = new \FPDF('L', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    $primary = $this->colors['matisse'];
    $accent = $this->colors['sun'];

    // HEADER
    $this->addProfessionalHeader($pdf, 'CASH REGISTER SESSION REPORT', $data, $primary);

    // SUMMARY METRICS
    $this->addCashRegisterSessionSummaryBoxes($pdf, $data['summary'], $data['currency'], $primary, $accent);

    // SESSION DETAILS
    foreach ($data['session_details'] as $sessionDetail) {
        $this->addSessionDetailCard($pdf, $sessionDetail, $data['currency'], $primary, $accent);
    }

    // FOOTER
    $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

    $filename = 'cash_register_session_report_' . date('Y-m-d') . '.pdf';
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
 * REPORT 13: CASH MOVEMENT SUMMARY REPORT
 * =====================================================
 * Comprehensive cash movement tracking and analysis
 */
public function generateCashMovementSummaryReport(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'business_id' => 'nullable|exists:businesses,id',
            'branch_id' => 'nullable|exists:branches,id',
            'movement_type' => 'nullable|in:cash_in,cash_out,cash_drop,opening_float,expense',
            'processed_by' => 'nullable|exists:users,id',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $reportData = $this->getCashMovementSummaryReportData($request);
        return $this->generateCashMovementSummaryPDF($reportData);

    } catch (\Exception $e) {
        Log::error('Failed to generate cash movement summary report', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return serverErrorResponse('Failed to generate cash movement summary report', $e->getMessage());
    }
}

/**
 * Get cash movement summary report data
 */
private function getCashMovementSummaryReportData(Request $request)
{
    $user = Auth::user();

    // Get currency information
    $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
    $currency = Currency::where('code', $currencyCode)->first();
    $currencySymbol = $currency ? $currency->symbol : '$';

    $businessId = $request->business_id ?? $user->business_id;
    $branchId = $request->branch_id ?? $user->primary_branch_id;

    // Build query for cash movements
    $query = CashMovement::with([
        'cashReconciliation.user',
        'processedBy',
        'branch'
    ]);

    $query->where('business_id', $businessId);
    if ($branchId) {
        $query->where('branch_id', $branchId);
    }

    // Date filtering
    $query->whereDate('movement_time', '>=', $request->start_date)
          ->whereDate('movement_time', '<=', $request->end_date);

    // Apply filters
    if ($request->movement_type) $query->where('movement_type', $request->movement_type);
    if ($request->processed_by) $query->where('processed_by', $request->processed_by);

    // Amount range filters
    if ($request->min_amount !== null) {
        $query->where('amount', '>=', $request->min_amount);
    }
    if ($request->max_amount !== null) {
        $query->where('amount', '<=', $request->max_amount);
    }

    $query->orderBy('movement_time', 'desc');
    $movements = $query->get();

    // Calculate summary metrics
    $summary = [
        'total_movements' => $movements->count(),
        'total_cash_in' => $movements->where('movement_type', 'cash_in')->sum('amount'),
        'total_cash_out' => $movements->where('movement_type', 'cash_out')->sum('amount'),
        'total_cash_drops' => $movements->where('movement_type', 'cash_drop')->sum('amount'),
        'total_expenses' => $movements->where('movement_type', 'expense')->sum('amount'),
        'total_opening_floats' => $movements->where('movement_type', 'opening_float')->sum('amount'),
        'net_movement' => $movements->where('movement_type', 'cash_in')->sum('amount')
                        - $movements->where('movement_type', 'cash_out')->sum('amount')
                        - $movements->where('movement_type', 'expense')->sum('amount'),
        'cash_in_count' => $movements->where('movement_type', 'cash_in')->count(),
        'cash_out_count' => $movements->where('movement_type', 'cash_out')->count(),
        'cash_drop_count' => $movements->where('movement_type', 'cash_drop')->count(),
        'expense_count' => $movements->where('movement_type', 'expense')->count(),
        'average_cash_drop' => $movements->where('movement_type', 'cash_drop')->count() > 0
            ? $movements->where('movement_type', 'cash_drop')->sum('amount') / $movements->where('movement_type', 'cash_drop')->count()
            : 0,
        'average_expense' => $movements->where('movement_type', 'expense')->count() > 0
            ? $movements->where('movement_type', 'expense')->sum('amount') / $movements->where('movement_type', 'expense')->count()
            : 0,
    ];

    // Movement type breakdown
    $typeBreakdown = [
        [
            'type' => 'Cash In',
            'count' => $summary['cash_in_count'],
            'amount' => $summary['total_cash_in'],
            'avg' => $summary['cash_in_count'] > 0 ? $summary['total_cash_in'] / $summary['cash_in_count'] : 0,
        ],
        [
            'type' => 'Cash Out',
            'count' => $summary['cash_out_count'],
            'amount' => $summary['total_cash_out'],
            'avg' => $summary['cash_out_count'] > 0 ? $summary['total_cash_out'] / $summary['cash_out_count'] : 0,
        ],
        [
            'type' => 'Cash Drop',
            'count' => $summary['cash_drop_count'],
            'amount' => $summary['total_cash_drops'],
            'avg' => $summary['average_cash_drop'],
        ],
        [
            'type' => 'Expense',
            'count' => $summary['expense_count'],
            'amount' => $summary['total_expenses'],
            'avg' => $summary['average_expense'],
        ],
        [
            'type' => 'Opening Float',
            'count' => $movements->where('movement_type', 'opening_float')->count(),
            'amount' => $summary['total_opening_floats'],
            'avg' => $movements->where('movement_type', 'opening_float')->count() > 0
                ? $summary['total_opening_floats'] / $movements->where('movement_type', 'opening_float')->count()
                : 0,
        ],
    ];

    // Daily movement summary
    $dailySummary = $movements->groupBy(fn($m) => $m->movement_time->format('Y-m-d'))
        ->map(fn($group) => [
            'date' => $group->first()->movement_time->format('M d, Y'),
            'count' => $group->count(),
            'cash_in' => $group->where('movement_type', 'cash_in')->sum('amount'),
            'cash_out' => $group->where('movement_type', 'cash_out')->sum('amount'),
            'cash_drops' => $group->where('movement_type', 'cash_drop')->sum('amount'),
            'expenses' => $group->where('movement_type', 'expense')->sum('amount'),
            'net' => $group->where('movement_type', 'cash_in')->sum('amount')
                   - $group->where('movement_type', 'cash_out')->sum('amount')
                   - $group->where('movement_type', 'expense')->sum('amount'),
        ])->values();

    // Staff performance (who processes movements)
    $staffPerformance = [];
    foreach ($movements as $movement) {
        $staffId = $movement->processed_by;
        $staffName = $movement->processedBy->name ?? 'Unknown';

        if (!isset($staffPerformance[$staffId])) {
            $staffPerformance[$staffId] = [
                'name' => $staffName,
                'total_movements' => 0,
                'cash_drops' => 0,
                'expenses' => 0,
                'total_amount' => 0,
            ];
        }

        $staffPerformance[$staffId]['total_movements']++;
        $staffPerformance[$staffId]['total_amount'] += $movement->amount;

        if ($movement->movement_type === 'cash_drop') {
            $staffPerformance[$staffId]['cash_drops']++;
        }
        if ($movement->movement_type === 'expense') {
            $staffPerformance[$staffId]['expenses']++;
        }
    }

    // Sort by total movements
    usort($staffPerformance, fn($a, $b) => $b['total_movements'] <=> $a['total_movements']);

    // Expense breakdown (top expenses)
    $expenseMovements = $movements->where('movement_type', 'expense')
        ->sortByDesc('amount')
        ->take(15)
        ->map(function($movement) {
            return [
                'date' => $movement->movement_time->format('M d, Y H:i'),
                'reason' => $movement->reason,
                'amount' => $movement->amount,
                'processed_by' => $movement->processedBy->name ?? 'N/A',
                'reference' => $movement->reference_number,
            ];
        })->values();

    // Large cash drops (top 10)
    $largeCashDrops = $movements->where('movement_type', 'cash_drop')
        ->sortByDesc('amount')
        ->take(10)
        ->map(function($movement) {
            return [
                'date' => $movement->movement_time->format('M d, Y H:i'),
                'amount' => $movement->amount,
                'processed_by' => $movement->processedBy->name ?? 'N/A',
                'session' => $movement->cashReconciliation ?
                    $movement->cashReconciliation->user->name . ' - ' .
                    ucfirst(str_replace('_', ' ', $movement->cashReconciliation->shift_type))
                    : 'N/A',
            ];
        })->values();

    return [
        'movements' => $movements,
        'summary' => $summary,
        'type_breakdown' => $typeBreakdown,
        'daily_summary' => $dailySummary,
        'staff_performance' => array_slice($staffPerformance, 0, 10),
        'expense_details' => $expenseMovements,
        'large_cash_drops' => $largeCashDrops,
        'currency' => $currencySymbol,
        'currency_code' => $currencyCode,
        'filters' => [
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'branch_id' => $branchId,
            'movement_type' => $request->movement_type,
        ],
        'business' => $user->business,
        'branch' => $branchId ? $user->primaryBranch : null,
        'generated_by' => $user->name,
        'generated_at' => now()->format('Y-m-d H:i:s'),
    ];
}

/**
 * Generate cash movement summary PDF
 */
private function generateCashMovementSummaryPDF($data)
{
    $pdf = new \FPDF('L', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    $primary = $this->colors['matisse'];
    $accent = $this->colors['sun'];

    // HEADER
    $this->addProfessionalHeader($pdf, 'CASH MOVEMENT SUMMARY REPORT', $data, $primary);

    // SUMMARY METRICS
    $this->addCashMovementSummaryBoxes($pdf, $data['summary'], $data['currency'], $primary, $accent);

    // MOVEMENT TYPE BREAKDOWN
    $this->addMovementTypeBreakdownTable($pdf, $data['type_breakdown'], $data['currency'], $primary);

    // DAILY SUMMARY
    if (!empty($data['daily_summary']) && count($data['daily_summary']) > 0) {
        $this->addDailyMovementSummaryTable($pdf, $data['daily_summary'], $data['currency'], $accent);
    }

    // STAFF PERFORMANCE
    if (!empty($data['staff_performance'])) {
        $this->addStaffMovementPerformanceTable($pdf, $data['staff_performance'], $data['currency'], $this->colors['hippie_blue']);
    }

    // EXPENSE DETAILS
    if (!empty($data['expense_details']) && count($data['expense_details']) > 0) {
        $this->addExpenseDetailsTable($pdf, $data['expense_details'], $data['currency'], $this->colors['danger']);
    }

    // LARGE CASH DROPS
    if (!empty($data['large_cash_drops']) && count($data['large_cash_drops']) > 0) {
        $this->addLargeCashDropsTable($pdf, $data['large_cash_drops'], $data['currency'], $this->colors['warning']);
    }

    // FOOTER
    $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

    $filename = 'cash_movement_summary_report_' . date('Y-m-d') . '.pdf';
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
// PDF COMPONENTS FOR NEW REPORTS
// =====================================================

/**
 * Cash register session summary boxes
 */
private function addCashRegisterSessionSummaryBoxes($pdf, $summary, $currency, $primary, $accent)
{
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
    $pdf->Cell(0, 7, 'SESSION SUMMARY', 0, 1);
    $pdf->Ln(2);

    $boxW = 65;
    $boxH = 22;
    $gap = 5;
    $startX = 15;
    $y = $pdf->GetY();

    // Row 1
    $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL SESSIONS', number_format($summary['total_sessions']), $primary);
    $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'OPENING FLOAT', $currency . ' ' . number_format($summary['total_opening_float'], 2), $accent);
    $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'EXPECTED CASH', $currency . ' ' . number_format($summary['total_expected_cash'], 2), $this->colors['hippie_blue']);
    $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'ACTUAL CASH', $currency . ' ' . number_format($summary['total_actual_cash'], 2), $this->colors['success']);

    // Row 2
    $y += $boxH + $gap;
    $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'CASH SALES', $currency . ' ' . number_format($summary['total_cash_sales'], 2), $this->colors['success']);
    $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'CASH DROPS', $currency . ' ' . number_format($summary['total_cash_drops'], 2), $this->colors['warning']);
    $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'EXPENSES', $currency . ' ' . number_format($summary['total_expenses'], 2), $this->colors['danger']);
    $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'VARIANCE', $currency . ' ' . number_format($summary['total_variance'], 2), $summary['total_variance'] >= 0 ? $this->colors['success'] : $this->colors['danger']);

    $pdf->SetY($y + $boxH + 8);
}

/**
 * Session detail card
 */
private function addSessionDetailCard($pdf, $sessionDetail, $currency, $primary, $accent)
{
    $session = $sessionDetail['session'];

    // Check if we need a new page
    if ($pdf->GetY() > 160) {
        $pdf->AddPage();
    }

    $startY = $pdf->GetY();

    // Session header box
    $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'SESSION: ' . $session->reconciliation_date->format('M d, Y') . ' - ' . ucfirst(str_replace('_', ' ', $session->shift_type)) . ' - ' . ($session->user->name ?? 'N/A'), 0, 1, 'L', true);

    // Session info grid
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);

    $colW = 70;
    $pdf->Cell($colW, 6, 'Opening Float: ' . $currency . ' ' . number_format($session->opening_float, 2), 1, 0, 'L', true);
    $pdf->Cell($colW, 6, 'Expected: ' . $currency . ' ' . number_format($session->expected_cash, 2), 1, 0, 'L', true);
    $pdf->Cell($colW, 6, 'Actual: ' . $currency . ' ' . number_format($session->actual_cash, 2), 1, 0, 'L', true);

    // Color variance
    $variance = $session->variance;
    if ($variance > 0) {
        $pdf->SetTextColor($this->colors['success'][0], $this->colors['success'][1], $this->colors['success'][2]);
    } elseif ($variance < 0) {
        $pdf->SetTextColor($this->colors['danger'][0], $this->colors['danger'][1], $this->colors['danger'][2]);
    }
    $pdf->Cell($colW, 6, 'Variance: ' . $currency . ' ' . number_format($variance, 2), 1, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);

    // Movements if any
    if ($sessionDetail['movements']->count() > 0) {
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
        $pdf->Cell(0, 6, 'Cash Movements (' . $sessionDetail['movements']->count() . ')', 0, 1);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
        $pdf->SetTextColor(255, 255, 255);

        $pdf->Cell(40, 6, 'Time', 1, 0, 'C', true);
        $pdf->Cell(40, 6, 'Type', 1, 0, 'C', true);
        $pdf->Cell(35, 6, 'Amount', 1, 0, 'C', true);
        $pdf->Cell(90, 6, 'Reason', 1, 0, 'C', true);
        $pdf->Cell(75, 6, 'Processed By', 1, 1, 'C', true);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 7);

        foreach ($sessionDetail['movements']->take(5) as $movement) {
            $pdf->Cell(40, 5, $movement->movement_time->format('H:i'), 1, 0, 'C');
            $pdf->Cell(40, 5, ucfirst(str_replace('_', ' ', $movement->movement_type)), 1, 0, 'L');
            $pdf->Cell(35, 5, $currency . ' ' . number_format($movement->amount, 2), 1, 0, 'R');
            $pdf->Cell(90, 5, substr($movement->reason ?? '-', 0, 40), 1, 0, 'L');
            $pdf->Cell(75, 5, $movement->processedBy->name ?? 'N/A', 1, 1, 'L');
        }
    }

    $pdf->Ln(6);
}

/**
 * Cash movement summary boxes
 */
private function addCashMovementSummaryBoxes($pdf, $summary, $currency, $primary, $accent)
{
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
    $pdf->Cell(0, 7, 'MOVEMENT SUMMARY', 0, 1);
    $pdf->Ln(2);

    $boxW = 65;
    $boxH = 22;
    $gap = 5;
    $startX = 15;
    $y = $pdf->GetY();

    // Row 1
    $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL MOVEMENTS', number_format($summary['total_movements']), $primary);
    $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'CASH IN', $currency . ' ' . number_format($summary['total_cash_in'], 2), $this->colors['success']);
    $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'CASH OUT', $currency . ' ' . number_format($summary['total_cash_out'], 2), $this->colors['danger']);
    $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'NET MOVEMENT', $currency . ' ' . number_format($summary['net_movement'], 2), $accent);

    // Row 2
    $y += $boxH + $gap;
    $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'CASH DROPS', $currency . ' ' . number_format($summary['total_cash_drops'], 2), $this->colors['warning']);
    $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'EXPENSES', $currency . ' ' . number_format($summary['total_expenses'], 2), $this->colors['danger']);
    $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'AVG CASH DROP', $currency . ' ' . number_format($summary['average_cash_drop'], 2), $this->colors['hippie_blue']);
    $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'AVG EXPENSE', $currency . ' ' . number_format($summary['average_expense'], 2), $this->colors['hippie_blue']);

    $pdf->SetY($y + $boxH + 8);
}

/**
 * Movement type breakdown table
 */
private function addMovementTypeBreakdownTable($pdf, $breakdown, $currency, $primary)
{
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
    $pdf->Cell(0, 7, 'BREAKDOWN BY MOVEMENT TYPE', 0, 1);
    $pdf->Ln(2);

    $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);

    $pdf->Cell(70, 8, 'Movement Type', 1, 0, 'C', true);
    $pdf->Cell(70, 8, 'Count', 1, 0, 'C', true);
    $pdf->Cell(75, 8, 'Total Amount', 1, 0, 'C', true);
    $pdf->Cell(70, 8, 'Average', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);

    $fill = false;
    foreach ($breakdown as $type) {
        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

        $pdf->Cell(70, 7, $type['type'], 1, 0, 'L', $fill);
        $pdf->Cell(70, 7, number_format($type['count']), 1, 0, 'C', $fill);
        $pdf->Cell(75, 7, $currency . ' ' . number_format($type['amount'], 2), 1, 0, 'R', $fill);
        $pdf->Cell(70, 7, $currency . ' ' . number_format($type['avg'], 2), 1, 1, 'R', $fill);

        $fill = !$fill;
    }

    $pdf->Ln(6);
}

/**
 * Daily movement summary table
 */
private function addDailyMovementSummaryTable($pdf, $daily, $currency, $accent)
{
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
    $pdf->Cell(0, 7, 'DAILY MOVEMENT SUMMARY', 0, 1);
    $pdf->Ln(2);

    $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);

    $pdf->Cell(45, 8, 'Date', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Count', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Cash In', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Cash Out', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Drops', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Expenses', 1, 0, 'C', true);
    $pdf->Cell(50, 8, 'Net', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);

    $fill = false;
    foreach ($daily as $day) {
        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

        $pdf->Cell(45, 7, $day['date'], 1, 0, 'L', $fill);
        $pdf->Cell(30, 7, number_format($day['count']), 1, 0, 'C', $fill);
        $pdf->Cell(40, 7, $currency . ' ' . number_format($day['cash_in'], 2), 1, 0, 'R', $fill);
        $pdf->Cell(40, 7, $currency . ' ' . number_format($day['cash_out'], 2), 1, 0, 'R', $fill);
        $pdf->Cell(40, 7, $currency . ' ' . number_format($day['cash_drops'], 2), 1, 0, 'R', $fill);
        $pdf->Cell(40, 7, $currency . ' ' . number_format($day['expenses'], 2), 1, 0, 'R', $fill);

        // Color code net
        $net = $day['net'];
        if ($net > 0) {
            $pdf->SetTextColor($this->colors['success'][0], $this->colors['success'][1], $this->colors['success'][2]);
        } elseif ($net < 0) {
            $pdf->SetTextColor($this->colors['danger'][0], $this->colors['danger'][1], $this->colors['danger'][2]);
        }
        $pdf->Cell(50, 7, $currency . ' ' . number_format($net, 2), 1, 1, 'R', $fill);
        $pdf->SetTextColor(0, 0, 0);

        $fill = !$fill;
    }

    $pdf->Ln(6);
}

/**
 * Staff movement performance table
 */
private function addStaffMovementPerformanceTable($pdf, $staff, $currency, $color)
{
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor($color[0], $color[1], $color[2]);
    $pdf->Cell(0, 7, 'STAFF PERFORMANCE (Top 10)', 0, 1);
    $pdf->Ln(2);

    $pdf->SetFillColor($color[0], $color[1], $color[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);

    $pdf->Cell(80, 8, 'Staff Name', 1, 0, 'C', true);
    $pdf->Cell(60, 8, 'Total Movements', 1, 0, 'C', true);
    $pdf->Cell(55, 8, 'Cash Drops', 1, 0, 'C', true);
    $pdf->Cell(50, 8, 'Expenses', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Amount', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);

    $fill = false;
    foreach ($staff as $person) {
        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

        $pdf->Cell(80, 7, substr($person['name'], 0, 35), 1, 0, 'L', $fill);
        $pdf->Cell(60, 7, number_format($person['total_movements']), 1, 0, 'C', $fill);
        $pdf->Cell(55, 7, number_format($person['cash_drops']), 1, 0, 'C', $fill);
        $pdf->Cell(50, 7, number_format($person['expenses']), 1, 0, 'C', $fill);
        $pdf->Cell(40, 7, $currency . ' ' . number_format($person['total_amount'], 2), 1, 1, 'R', $fill);

        $fill = !$fill;
    }

    $pdf->Ln(6);
}

/**
 * Expense details table
 */
private function addExpenseDetailsTable($pdf, $expenses, $currency, $color)
{
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor($color[0], $color[1], $color[2]);
    $pdf->Cell(0, 7, 'TOP EXPENSES', 0, 1);
    $pdf->Ln(2);

    $pdf->SetFillColor($color[0], $color[1], $color[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);

    $pdf->Cell(45, 8, 'Date/Time', 1, 0, 'C', true);
    $pdf->Cell(100, 8, 'Reason', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Amount', 1, 0, 'C', true);
    $pdf->Cell(60, 8, 'Processed By', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Reference', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);

    $fill = false;
    foreach ($expenses as $expense) {
        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

        $pdf->Cell(45, 7, substr($expense['date'], 0, 18), 1, 0, 'L', $fill);
        $pdf->Cell(100, 7, substr($expense['reason'], 0, 45), 1, 0, 'L', $fill);
        $pdf->Cell(40, 7, $currency . ' ' . number_format($expense['amount'], 2), 1, 0, 'R', $fill);
        $pdf->Cell(60, 7, substr($expense['processed_by'], 0, 25), 1, 0, 'L', $fill);
        $pdf->Cell(40, 7, substr($expense['reference'] ?? '-', 0, 15), 1, 1, 'L', $fill);

        $fill = !$fill;

        if ($pdf->GetY() > 180) {
            $pdf->AddPage();
            // Repeat header
            $pdf->SetFillColor($color[0], $color[1], $color[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(45, 8, 'Date/Time', 1, 0, 'C', true);
            $pdf->Cell(100, 8, 'Reason', 1, 0, 'C', true);
            $pdf->Cell(40, 8, 'Amount', 1, 0, 'C', true);
            $pdf->Cell(60, 8, 'Processed By', 1, 0, 'C', true);
            $pdf->Cell(40, 8, 'Reference', 1, 1, 'C', true);
            $pdf->SetFont('Arial', '', 7);
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    $pdf->Ln(6);
}

/**
 * Large cash drops table
 */
private function addLargeCashDropsTable($pdf, $drops, $currency, $color)
{
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor($color[0], $color[1], $color[2]);
    $pdf->Cell(0, 7, 'LARGEST CASH DROPS', 0, 1);
    $pdf->Ln(2);

    $pdf->SetFillColor($color[0], $color[1], $color[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);

    $pdf->Cell(55, 8, 'Date/Time', 1, 0, 'C', true);
    $pdf->Cell(50, 8, 'Amount', 1, 0, 'C', true);
    $pdf->Cell(75, 8, 'Processed By', 1, 0, 'C', true);
    $pdf->Cell(105, 8, 'Session', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);

    $fill = false;
    foreach ($drops as $drop) {
        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

        $pdf->Cell(55, 7, $drop['date'], 1, 0, 'L', $fill);
        $pdf->Cell(50, 7, $currency . ' ' . number_format($drop['amount'], 2), 1, 0, 'R', $fill);
        $pdf->Cell(75, 7, substr($drop['processed_by'], 0, 30), 1, 0, 'L', $fill);
        $pdf->Cell(105, 7, substr($drop['session'], 0, 45), 1, 1, 'L', $fill);

        $fill = !$fill;
    }

    $pdf->Ln(6);
}
}
