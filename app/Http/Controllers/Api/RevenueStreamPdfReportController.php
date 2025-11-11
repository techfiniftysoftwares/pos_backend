<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RevenueEntry;
use App\Models\RevenueStream;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

// FPDF
require_once(base_path('vendor/setasign/fpdf/fpdf.php'));

class RevenueStreamPdfReportController extends Controller
{
    // Color scheme - Matisse theme (matches your brand)
    private $colors = [
        'matisse' => [36, 116, 172],
        'sun' => [252, 172, 28],
        'hippie_blue' => [88, 154, 175],
        'text_dark' => [51, 51, 51],
        'text_light' => [102, 102, 102],
        'success' => [40, 167, 69],
        'danger' => [220, 53, 69],
        'warning' => [255, 193, 7],
        'gray_light' => [248, 249, 250],
        'gray_medium' => [233, 236, 239],
        'white' => [255, 255, 255]
    ];

    private $logoPath;

    public function __construct()
    {
        $this->logoPath = public_path('images/company/logo.png');
    }

    /**
     * =====================================================
     * REPORT 1: COMPREHENSIVE REVENUE STREAM REPORT
     * =====================================================
     */
    public function generateRevenueStreamReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'branch_id' => 'nullable|exists:branches,id',
                'business_id' => 'nullable|exists:businesses,id',
                'revenue_stream_id' => 'nullable|exists:revenue_streams,id',
                'status' => 'nullable|in:pending,approved,rejected',
                'currency_code' => 'nullable|string|size:3',
                'requires_approval' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            $reportData = $this->getRevenueStreamReportData($request);
            return $this->generateRevenueStreamPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate revenue stream report PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate PDF report', $e->getMessage());
        }
    }

    /**
     * Get revenue stream report data with comprehensive filtering
     */
    private function getRevenueStreamReportData(Request $request)
    {
        $user = Auth::user();

        // Get currency information
        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        $businessId = $request->business_id ?? $user->business_id;
        $branchId = $request->branch_id ?? $user->primary_branch_id;

        // Build query for revenue entries
        $query = RevenueEntry::with([
            'revenueStream',
            'branch',
            'business',
            'recordedBy',
            'approvedBy'
        ]);

        $query->where('business_id', $businessId);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        // Date filtering
        $query->whereDate('entry_date', '>=', $request->start_date)
              ->whereDate('entry_date', '<=', $request->end_date);

        // Apply filters
        if ($request->revenue_stream_id) {
            $query->where('revenue_stream_id', $request->revenue_stream_id);
        }
        if ($request->status) {
            $query->where('status', $request->status);
        }

        $query->orderBy('entry_date', 'desc');
        $entries = $query->get();

        // Get all revenue streams for the business
        $streamsQuery = RevenueStream::where('business_id', $businessId);
        if ($request->is_active !== null) {
            $streamsQuery->where('is_active', $request->is_active);
        }
        if ($request->requires_approval !== null) {
            $streamsQuery->where('requires_approval', $request->requires_approval);
        }
        $streams = $streamsQuery->get();

        // Calculate summary metrics
        $summary = [
            'total_entries' => $entries->count(),
            'total_amount' => $entries->sum('amount'),
            'approved_entries' => $entries->where('status', 'approved')->count(),
            'pending_entries' => $entries->where('status', 'pending')->count(),
            'rejected_entries' => $entries->where('status', 'rejected')->count(),
            'approved_amount' => $entries->where('status', 'approved')->sum('amount'),
            'pending_amount' => $entries->where('status', 'pending')->sum('amount'),
            'rejected_amount' => $entries->where('status', 'rejected')->sum('amount'),
            'total_streams' => $streams->count(),
            'active_streams' => $streams->where('is_active', true)->count(),
        ];

        // Revenue by stream
        $byStream = [];
        foreach ($streams as $stream) {
            $streamEntries = $entries->where('revenue_stream_id', $stream->id);
            $byStream[] = [
                'stream' => $stream,
                'count' => $streamEntries->count(),
                'total_amount' => $streamEntries->sum('amount'),
                'approved_amount' => $streamEntries->where('status', 'approved')->sum('amount'),
                'pending_amount' => $streamEntries->where('status', 'pending')->sum('amount'),
            ];
        }
        // Sort by total amount descending
        usort($byStream, fn($a, $b) => $b['total_amount'] <=> $a['total_amount']);

        // Revenue by status
        $byStatus = [
            ['status' => 'Approved', 'count' => $summary['approved_entries'], 'amount' => $summary['approved_amount']],
            ['status' => 'Pending', 'count' => $summary['pending_entries'], 'amount' => $summary['pending_amount']],
            ['status' => 'Rejected', 'count' => $summary['rejected_entries'], 'amount' => $summary['rejected_amount']],
        ];

        // Daily breakdown
        $dailyRevenue = $entries->groupBy(fn($entry) => $entry->entry_date->format('Y-m-d'))
            ->map(fn($group) => [
                'date' => $group->first()->entry_date->format('M d, Y'),
                'count' => $group->count(),
                'amount' => $group->sum('amount')
            ])->values();

        return [
            'entries' => $entries,
            'streams' => $streams,
            'summary' => $summary,
            'by_stream' => $byStream,
            'by_status' => $byStatus,
            'daily_revenue' => $dailyRevenue,
            'currency' => $currencySymbol,
            'currency_code' => $currencyCode,
            'filters' => [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'branch_id' => $branchId,
                'revenue_stream_id' => $request->revenue_stream_id,
                'status' => $request->status,
            ],
            'business' => $user->business,
            'branch' => $branchId ? $user->primaryBranch : null,
            'generated_by' => $user->name,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Generate beautiful revenue stream PDF
     */
    private function generateRevenueStreamPDF($data)
    {
        $pdf = new \FPDF('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $primary = $this->colors['matisse'];
        $accent = $this->colors['sun'];

        // HEADER
        $this->addProfessionalHeader($pdf, 'REVENUE STREAM REPORT', $data, $primary);

        // SUMMARY METRICS
        $this->addRevenueSummaryBoxes($pdf, $data['summary'], $data['currency'], $primary, $accent);

        // REVENUE BY STREAM
        if (!empty($data['by_stream'])) {
            $this->addRevenueByStream($pdf, $data['by_stream'], $data['currency'], $primary);
        }

        // REVENUE BY STATUS
        $this->addRevenueByStatus($pdf, $data['by_status'], $data['currency'], $accent);

        // DAILY BREAKDOWN
        if (!$data['daily_revenue']->isEmpty()) {
            $this->addDailyRevenueBreakdown($pdf, $data['daily_revenue'], $data['currency'], $primary);
        }

        // REVENUE ENTRIES TABLE
        $this->addRevenueEntriesTable($pdf, $data['entries'], $data['currency'], $primary);

        // FOOTER
        $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

        $filename = 'revenue_stream_report_' . date('Y-m-d') . '.pdf';
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
     * REPORT 2: REVENUE STREAM SUMMARY REPORT (COMPACT)
     * =====================================================
     */
    public function generateRevenueStreamSummaryReport(Request $request)
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

            $reportData = $this->getRevenueStreamSummaryData($request);
            return $this->generateRevenueStreamSummaryPDF($reportData);

        } catch (\Exception $e) {
            Log::error('Failed to generate revenue stream summary report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to generate summary report', $e->getMessage());
        }
    }

    /**
     * Get revenue stream summary data (aggregated metrics only)
     */
    private function getRevenueStreamSummaryData(Request $request)
    {
        $user = Auth::user();

        $currencyCode = $request->currency_code ?? $user->business->default_currency ?? 'USD';
        $currency = Currency::where('code', $currencyCode)->first();
        $currencySymbol = $currency ? $currency->symbol : '$';

        $businessId = $request->business_id ?? $user->business_id;
        $branchId = $request->branch_id ?? $user->primary_branch_id;

        // Get revenue entries
        $query = RevenueEntry::where('business_id', $businessId)
            ->whereDate('entry_date', '>=', $request->start_date)
            ->whereDate('entry_date', '<=', $request->end_date);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $entries = $query->get();

        // Get revenue streams
        $streams = RevenueStream::where('business_id', $businessId)->get();

        // Aggregate metrics
        $metrics = [
            'total_revenue' => $entries->sum('amount'),
            'total_entries' => $entries->count(),
            'approved_revenue' => $entries->where('status', 'approved')->sum('amount'),
            'pending_revenue' => $entries->where('status', 'pending')->sum('amount'),
            'approved_count' => $entries->where('status', 'approved')->count(),
            'pending_count' => $entries->where('status', 'pending')->count(),
            'total_streams' => $streams->count(),
            'active_streams' => $streams->where('is_active', true)->count(),
            'avg_entry_value' => $entries->count() > 0 ? $entries->sum('amount') / $entries->count() : 0,
        ];

        // Top revenue streams
        $topStreams = [];
        foreach ($streams as $stream) {
            $streamEntries = $entries->where('revenue_stream_id', $stream->id);
            if ($streamEntries->count() > 0) {
                $topStreams[] = [
                    'name' => $stream->name,
                    'count' => $streamEntries->count(),
                    'amount' => $streamEntries->sum('amount'),
                ];
            }
        }
        usort($topStreams, fn($a, $b) => $b['amount'] <=> $a['amount']);
        $topStreams = array_slice($topStreams, 0, 5);

        // Daily revenue
        $dailyRevenue = $entries->groupBy(fn($entry) => $entry->entry_date->format('Y-m-d'))
            ->map(fn($group) => [
                'date' => $group->first()->entry_date->format('M d, Y'),
                'count' => $group->count(),
                'amount' => $group->sum('amount')
            ])->values();

        return [
            'metrics' => $metrics,
            'top_streams' => $topStreams,
            'daily_revenue' => $dailyRevenue,
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
     * Generate beautiful revenue stream summary PDF
     */
    private function generateRevenueStreamSummaryPDF($data)
    {
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        $primary = $this->colors['matisse'];
        $accent = $this->colors['sun'];

        // HEADER
        $this->addProfessionalHeader($pdf, 'REVENUE STREAM SUMMARY', $data, $primary);

        // KEY METRICS
        $this->addCompactRevenueMetrics($pdf, $data['metrics'], $data['currency'], $primary, $accent);

        // TOP STREAMS
        if (!empty($data['top_streams'])) {
            $this->addCompactTopStreams($pdf, $data['top_streams'], $data['currency'], $accent);
        }

        // DAILY BREAKDOWN
        if (!$data['daily_revenue']->isEmpty()) {
            $this->addCompactDailyRevenue($pdf, $data['daily_revenue'], $data['currency'], $primary);
        }

        // FOOTER
        $this->addProfessionalFooter($pdf, $data['generated_by'], $data['generated_at'], $data['business'], $data['branch']);

        $filename = 'revenue_stream_summary_' . date('Y-m-d') . '.pdf';
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
    // PDF COMPONENTS
    // =====================================================

    /**
     * Revenue summary boxes - 2x4 grid
     */
    private function addRevenueSummaryBoxes($pdf, $summary, $currency, $primary, $accent)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'REVENUE OVERVIEW', 0, 1);
        $pdf->Ln(2);

        $boxW = 65;
        $boxH = 22;
        $gap = 5;
        $startX = 15;
        $y = $pdf->GetY();

        // Row 1
        $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL REVENUE', $currency . ' ' . number_format($summary['total_amount'], 2), $primary);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'TOTAL ENTRIES', number_format($summary['total_entries']), $accent);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'APPROVED', number_format($summary['approved_entries']) . ' (' . $currency . number_format($summary['approved_amount'], 0) . ')', $this->colors['success']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'PENDING', number_format($summary['pending_entries']) . ' (' . $currency . number_format($summary['pending_amount'], 0) . ')', $this->colors['warning']);

        // Row 2
        $y += $boxH + $gap;
        $this->drawMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL STREAMS', number_format($summary['total_streams']), $this->colors['hippie_blue']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap), $y, $boxW, $boxH, 'ACTIVE STREAMS', number_format($summary['active_streams']), $this->colors['success']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 2, $y, $boxW, $boxH, 'REJECTED', number_format($summary['rejected_entries']), $this->colors['danger']);
        $this->drawMetricBox($pdf, $startX + ($boxW + $gap) * 3, $y, $boxW, $boxH, 'REJECTED AMT', $currency . ' ' . number_format($summary['rejected_amount'], 2), $this->colors['danger']);

        $pdf->SetY($y + $boxH + 8);
    }

    /**
     * Compact revenue metrics for summary report
     */
    private function addCompactRevenueMetrics($pdf, $metrics, $currency, $primary, $accent)
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
        $this->drawLargeMetricBox($pdf, $startX, $y, $boxW, $boxH, 'TOTAL REVENUE', $currency . ' ' . number_format($metrics['total_revenue'], 2), 'From ' . $metrics['total_entries'] . ' entries', $primary);
        $this->drawLargeMetricBox($pdf, $startX + $boxW + $gap, $y, $boxW, $boxH, 'AVG ENTRY VALUE', $currency . ' ' . number_format($metrics['avg_entry_value'], 2), 'Per revenue entry', $accent);

        // Row 2
        $y += $boxH + $gap;
        $this->drawLargeMetricBox($pdf, $startX, $y, $boxW, $boxH, 'APPROVED REVENUE', $currency . ' ' . number_format($metrics['approved_revenue'], 2), number_format($metrics['approved_count']) . ' entries approved', $this->colors['success']);
        $this->drawLargeMetricBox($pdf, $startX + $boxW + $gap, $y, $boxW, $boxH, 'PENDING REVENUE', $currency . ' ' . number_format($metrics['pending_revenue'], 2), number_format($metrics['pending_count']) . ' entries pending', $this->colors['warning']);

        $pdf->SetY($y + $boxH + 6);
    }

    /**
     * Revenue by stream table
     */
    private function addRevenueByStream($pdf, $byStream, $currency, $primary)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'REVENUE BY STREAM', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);

        $pdf->Cell(100, 8, 'Revenue Stream', 1, 0, 'C', true);
        $pdf->Cell(45, 8, 'Entries', 1, 0, 'C', true);
        $pdf->Cell(55, 8, 'Approved (' . $currency . ')', 1, 0, 'C', true);
        $pdf->Cell(80, 8, 'Total Amount (' . $currency . ')', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($byStream as $item) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell(100, 7, substr($item['stream']->name, 0, 35), 1, 0, 'L', $fill);
            $pdf->Cell(45, 7, number_format($item['count']), 1, 0, 'C', $fill);
            $pdf->Cell(55, 7, number_format($item['approved_amount'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(80, 7, number_format($item['total_amount'], 2), 1, 1, 'R', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(6);
    }

    /**
     * Compact top streams for summary report
     */
    private function addCompactTopStreams($pdf, $topStreams, $currency, $accent)
    {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
        $pdf->Cell(0, 6, 'TOP 5 REVENUE STREAMS', 0, 1);
        $pdf->Ln(1);

        $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);

        $pdf->Cell(95, 7, 'Stream Name', 1, 0, 'C', true);
        $pdf->Cell(47, 7, 'Entries', 1, 0, 'C', true);
        $pdf->Cell(48, 7, 'Amount (' . $currency . ')', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($topStreams as $stream) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell(95, 6, substr($stream['name'], 0, 35), 1, 0, 'L', $fill);
            $pdf->Cell(47, 6, number_format($stream['count']), 1, 0, 'C', $fill);
            $pdf->Cell(48, 6, number_format($stream['amount'], 2), 1, 1, 'R', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(5);
    }

    /**
     * Revenue by status table
     */
    private function addRevenueByStatus($pdf, $byStatus, $currency, $accent)
    {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
        $pdf->Cell(0, 7, 'REVENUE BY STATUS', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);

        $pdf->Cell(93, 8, 'Status', 1, 0, 'C', true);
        $pdf->Cell(93, 8, 'Entries', 1, 0, 'C', true);
        $pdf->Cell(94, 8, 'Amount (' . $currency . ')', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($byStatus as $status) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell(93, 7, $status['status'], 1, 0, 'L', $fill);
            $pdf->Cell(93, 7, number_format($status['count']), 1, 0, 'C', $fill);
            $pdf->Cell(94, 7, number_format($status['amount'], 2), 1, 1, 'R', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(6);
    }

    /**
     * Daily revenue breakdown
     */
    private function addDailyRevenueBreakdown($pdf, $dailyRevenue, $currency, $primary)
    {
        if ($dailyRevenue->isEmpty()) {
            return;
        }

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'DAILY REVENUE BREAKDOWN', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);

        $pdf->Cell(93, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(93, 8, 'Entries', 1, 0, 'C', true);
        $pdf->Cell(94, 8, 'Amount (' . $currency . ')', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($dailyRevenue as $day) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell(93, 7, $day['date'], 1, 0, 'L', $fill);
            $pdf->Cell(93, 7, number_format($day['count']), 1, 0, 'C', $fill);
            $pdf->Cell(94, 7, number_format($day['amount'], 2), 1, 1, 'R', $fill);
            $fill = !$fill;

            if ($pdf->GetY() > 175) {
                $pdf->AddPage();
                // Repeat header
                $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->Cell(93, 8, 'Date', 1, 0, 'C', true);
                $pdf->Cell(93, 8, 'Entries', 1, 0, 'C', true);
                $pdf->Cell(94, 8, 'Amount (' . $currency . ')', 1, 1, 'C', true);
                $pdf->SetFont('Arial', '', 8);
                $pdf->SetTextColor(0, 0, 0);
            }
        }

        $pdf->Ln(6);
    }

    /**
     * Compact daily revenue for summary report
     */
    private function addCompactDailyRevenue($pdf, $dailyRevenue, $currency, $primary)
    {
        if ($dailyRevenue->isEmpty()) {
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
        $pdf->Cell(55, 7, 'Entries', 1, 0, 'C', true);
        $pdf->Cell(65, 7, 'Amount (' . $currency . ')', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($dailyRevenue as $day) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $pdf->Cell(70, 6, $day['date'], 1, 0, 'L', $fill);
            $pdf->Cell(55, 6, number_format($day['count']), 1, 0, 'C', $fill);
            $pdf->Cell(65, 6, number_format($day['amount'], 2), 1, 1, 'R', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(5);
    }

    /**
     * Revenue entries details table
     */
    private function addRevenueEntriesTable($pdf, $entries, $currency, $primary)
    {
        if ($entries->isEmpty()) {
            $pdf->SetFont('Arial', 'I', 11);
            $pdf->SetTextColor(128, 128, 128);
            $pdf->Cell(0, 10, 'No revenue entries found for this period.', 0, 1, 'C');
            return;
        }

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 7, 'REVENUE ENTRIES DETAILS', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);

        $pdf->Cell(28, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(70, 8, 'Revenue Stream', 1, 0, 'C', true);
        $pdf->Cell(47, 8, 'Reference', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Amount (' . $currency . ')', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Status', 1, 0, 'C', true);
        $pdf->Cell(65, 8, 'Description', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        $fill = false;
        foreach ($entries as $entry) {
            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);

            $date = $entry->entry_date->format('m/d/Y');
            $stream = substr($entry->revenueStream->name ?? 'N/A', 0, 25);
            $reference = substr($entry->reference_number ?? '-', 0, 18);
            $status = ucfirst($entry->status);
            $description = substr($entry->description ?? '', 0, 30);

            $pdf->Cell(28, 7, $date, 1, 0, 'C', $fill);
            $pdf->Cell(70, 7, $stream, 1, 0, 'L', $fill);
            $pdf->Cell(47, 7, $reference, 1, 0, 'C', $fill);
            $pdf->Cell(40, 7, number_format($entry->amount, 2), 1, 0, 'R', $fill);
            $pdf->Cell(30, 7, $status, 1, 0, 'C', $fill);
            $pdf->Cell(65, 7, $description, 1, 1, 'L', $fill);

            $fill = !$fill;

            if ($pdf->GetY() > 180) {
                $pdf->AddPage();
                // Repeat header
                $pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->Cell(28, 8, 'Date', 1, 0, 'C', true);
                $pdf->Cell(70, 8, 'Revenue Stream', 1, 0, 'C', true);
                $pdf->Cell(47, 8, 'Reference', 1, 0, 'C', true);
                $pdf->Cell(40, 8, 'Amount (' . $currency . ')', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Status', 1, 0, 'C', true);
                $pdf->Cell(65, 8, 'Description', 1, 1, 'C', true);
                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(0, 0, 0);
            }
        }
    }

    // =====================================================
    // SHARED COMPONENTS
    // =====================================================

    private function addProfessionalHeader($pdf, $title, $data, $primary)
    {
        if (file_exists($this->logoPath)) {
            $pageWidth = $pdf->GetPageWidth();
            $logoWidth = 35;
            $logoX = ($pageWidth - $logoWidth) / 2;
            $pdf->Image($this->logoPath, $logoX, 10, $logoWidth);
            $pdf->SetY(30);
        } else {
            $pdf->SetY(15);
        }

        $pdf->SetFont('Arial', 'B', 20);
        $pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
        $pdf->Cell(0, 10, $title, 0, 1, 'C');
        $pdf->Ln(2);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor(0, 0, 0);
        $period = date('F d, Y', strtotime($data['period']['start'] ?? $data['filters']['start_date'])) .
                  ' - ' .
                  date('F d, Y', strtotime($data['period']['end'] ?? $data['filters']['end_date']));
        $pdf->Cell(0, 6, $period, 0, 1, 'C');

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 5, 'Revenue Stream Analysis', 0, 1, 'C');

        $pdf->Ln(8);
    }

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

    private function drawLargeMetricBox($pdf, $x, $y, $w, $h, $title, $value, $subtitle, $color)
    {
        $pdf->SetDrawColor($color[0], $color[1], $color[2]);
        $pdf->SetLineWidth(0.5);
        $pdf->Rect($x, $y, $w, $h, 'D');

        $pdf->SetFillColor($color[0], $color[1], $color[2]);
        $pdf->Rect($x, $y, $w, 3, 'F');

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetXY($x, $y + 5);
        $pdf->Cell($w, 5, $title, 0, 0, 'C');

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

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetXY($x, $y + 21);
        $pdf->Cell($w, 4, $subtitle, 0, 0, 'C');
    }

    private function addProfessionalFooter($pdf, $generatedBy, $generatedAt, $business = null, $branch = null)
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
        $pdf->Cell(0, 4, 'Generated by: ' . $generatedBy . ' | ' . date('F d, Y h:i A', strtotime($generatedAt)), 0, 1, 'C');
        $pdf->Cell(0, 4, 'Page ' . $pdf->PageNo(), 0, 1, 'C');
        $pdf->Cell(0, 4, 'This is a computer-generated document and requires no signature', 0, 1, 'C');
    }
}