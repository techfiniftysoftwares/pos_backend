<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RevenueEntry;
use App\Models\RevenueStream;
use App\Models\Branch;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RevenueEntryController extends Controller
{
    /**
     * Display revenue entries with filtering
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $search = $request->input('search');
            $status = $request->input('status');
            $branchId = $request->input('branch_id');
            $streamId = $request->input('revenue_stream_id');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $businessId = $request->user()->business_id;

            $query = RevenueEntry::with(['branch', 'revenueStream', 'currency', 'recordedBy', 'approvedBy', 'business.baseCurrency'])
                ->where('business_id', $businessId);

            // Branch filter
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            // Stream filter
            if ($streamId) {
                $query->where('revenue_stream_id', $streamId);
            }

            // Status filter
            if ($status) {
                $query->where('status', $status);
            }

            // Date range filter
            if ($dateFrom) {
                $query->whereDate('entry_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('entry_date', '<=', $dateTo);
            }

            // Search filter (by entry_number, receipt_number, or notes)
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('entry_number', 'like', "%{$search}%")
                        ->orWhere('receipt_number', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%");
                });
            }

            $entries = $query->orderBy('entry_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            // Transform the data
            $entries->getCollection()->transform(function ($entry) {
                return $this->transformRevenueEntry($entry);
            });

            return successResponse('Revenue entries retrieved successfully', $entries);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve revenue entries', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return queryErrorResponse('Failed to retrieve revenue entries', $e->getMessage());
        }
    }

    /**
     * Create new revenue entry with robust validations
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|exists:branches,id',
            'revenue_stream_id' => 'required|exists:revenue_streams,id',
            'amount' => 'required|numeric|min:0.01',
            'currency_id' => 'required|exists:currencies,id',
            'exchange_rate' => 'nullable|numeric|min:0.0001', // Now optional
            'entry_date' => 'required|date|before_or_equal:today',
            'receipt_number' => 'nullable|string|max:255',
            'receipt_attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB max
            'notes' => 'nullable|string|max:1000',
        ], [
            'entry_date.before_or_equal' => 'Entry date cannot be in the future.',
            'receipt_attachment.max' => 'Receipt attachment must not exceed 5MB.',
            'receipt_attachment.mimes' => 'Receipt must be a JPG, PNG, or PDF file.',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;

            // VALIDATION 1: Branch must belong to user's business
            $branch = Branch::where('id', $request->branch_id)
                ->where('business_id', $businessId)
                ->first();

            if (!$branch) {
                return errorResponse('Branch does not belong to your business.', 403);
            }

            // VALIDATION 2: Revenue stream must belong to business and be active
            $stream = RevenueStream::where('id', $request->revenue_stream_id)
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->first();

            if (!$stream) {
                return errorResponse('Revenue stream not found or inactive.', 400);
            }

            // VALIDATION 3: Stream-branch alignment (if stream has a branch, entry must match)
            if ($stream->branch_id && $stream->branch_id !== (int) $request->branch_id) {
                return errorResponse('This revenue stream is assigned to a different branch.', 400);
            }

            // VALIDATION 4: Receipt number uniqueness within business
            if ($request->receipt_number) {
                $existingReceipt = RevenueEntry::where('business_id', $businessId)
                    ->where('receipt_number', $request->receipt_number)
                    ->exists();

                if ($existingReceipt) {
                    return errorResponse('A revenue entry with this receipt number already exists.', 400);
                }
            }

            // Get business with base currency
            $business = Business::with('baseCurrency')->findOrFail($businessId);

            if (!$business->base_currency_id) {
                return errorResponse('Business does not have a base currency configured.', 400);
            }

            // Get exchange rate - use provided rate or fetch from system
            $exchangeRate = $request->exchange_rate;

            if (!$exchangeRate) {
                // Auto-fetch exchange rate from the ExchangeRate model
                $currency = \App\Models\Currency::find($request->currency_id);

                if ($currency && $currency->id === $business->base_currency_id) {
                    // Same currency, rate is 1
                    $exchangeRate = 1;
                } else {
                    // Try to get rate from ExchangeRate table
                    $rateRecord = \App\Models\ExchangeRate::getCurrentRate(
                        $request->currency_id,
                        $business->base_currency_id,
                        $businessId
                    );

                    if ($rateRecord) {
                        $exchangeRate = $rateRecord->rate;
                    } else {
                        // Fallback: check if currency has a default rate
                        $exchangeRate = $currency->exchange_rate ?? 1;
                    }
                }
            }

            DB::beginTransaction();

            // Handle file upload
            $receiptPath = null;
            if ($request->hasFile('receipt_attachment')) {
                $file = $request->file('receipt_attachment');
                $filename = 'receipt_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $receiptPath = $file->storeAs(
                    "revenue-receipts/{$businessId}",
                    $filename,
                    'public'
                );
            }

            // Calculate base currency amount
            $amountInBaseCurrency = round($request->amount * $exchangeRate, 2);

            // Determine initial status based on stream's requires_approval setting
            $status = $stream->requires_approval ? 'pending' : 'approved';

            $entryData = [
                'business_id' => $businessId,
                'branch_id' => $request->branch_id,
                'revenue_stream_id' => $request->revenue_stream_id,
                // entry_number auto-generated in model boot
                'amount' => $request->amount,
                'currency_id' => $request->currency_id,
                'exchange_rate' => $exchangeRate,
                'amount_in_base_currency' => $amountInBaseCurrency,
                'entry_date' => $request->entry_date,
                'receipt_number' => $request->receipt_number,
                'receipt_attachment' => $receiptPath,
                'notes' => $request->notes,
                'status' => $status,
                'recorded_by' => Auth::id(),
                'approved_by' => $status === 'approved' ? Auth::id() : null,
                'approved_at' => $status === 'approved' ? now() : null,
            ];

            $entry = RevenueEntry::create($entryData);
            $entry->load(['branch', 'revenueStream', 'currency', 'recordedBy', 'approvedBy']);

            DB::commit();

            return successResponse(
                'Revenue entry created successfully',
                $this->transformRevenueEntry($entry),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();

            // Clean up uploaded file on failure
            if (isset($receiptPath) && $receiptPath) {
                Storage::disk('public')->delete($receiptPath);
            }

            Log::error('Failed to create revenue entry', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->except(['receipt_attachment'])
            ]);
            return serverErrorResponse('Failed to create revenue entry', $e->getMessage());
        }
    }

    /**
     * Display specific revenue entry
     */
    public function show(RevenueEntry $revenueEntry)
    {
        try {
            // Check business access
            if ($revenueEntry->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this revenue entry', 403);
            }

            $revenueEntry->load(['branch', 'revenueStream', 'currency', 'recordedBy', 'approvedBy']);

            return successResponse('Revenue entry retrieved successfully', $this->transformRevenueEntry($revenueEntry));
        } catch (\Exception $e) {
            Log::error('Failed to retrieve revenue entry', [
                'entry_id' => $revenueEntry->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve revenue entry', $e->getMessage());
        }
    }

    /**
     * Update revenue entry (only pending entries can be fully edited)
     */
    public function update(Request $request, RevenueEntry $revenueEntry)
    {
        // Check business access
        if ($revenueEntry->business_id !== $request->user()->business_id) {
            return errorResponse('Unauthorized access to this revenue entry', 403);
        }

        // Only pending entries can be fully edited
        if ($revenueEntry->status !== 'pending') {
            return errorResponse('Only pending entries can be edited. Approved/rejected entries are locked.', 400);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => 'sometimes|exists:branches,id',
            'revenue_stream_id' => 'sometimes|exists:revenue_streams,id',
            'amount' => 'sometimes|numeric|min:0.01',
            'currency_id' => 'sometimes|exists:currencies,id',
            'exchange_rate' => 'sometimes|numeric|min:0.0001',
            'entry_date' => 'sometimes|date|before_or_equal:today',
            'receipt_number' => 'nullable|string|max:255',
            'receipt_attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;

            // Validate branch ownership if changing
            if ($request->has('branch_id')) {
                $branch = Branch::where('id', $request->branch_id)
                    ->where('business_id', $businessId)
                    ->first();

                if (!$branch) {
                    return errorResponse('Branch does not belong to your business.', 403);
                }
            }

            // Validate stream if changing
            if ($request->has('revenue_stream_id')) {
                $stream = RevenueStream::where('id', $request->revenue_stream_id)
                    ->where('business_id', $businessId)
                    ->where('is_active', true)
                    ->first();

                if (!$stream) {
                    return errorResponse('Revenue stream not found or inactive.', 400);
                }

                // Check stream-branch alignment
                $branchToCheck = $request->input('branch_id', $revenueEntry->branch_id);
                if ($stream->branch_id && $stream->branch_id !== $branchToCheck) {
                    return errorResponse('This revenue stream is assigned to a different branch.', 400);
                }
            }

            // Validate receipt uniqueness if changing
            if ($request->receipt_number && $request->receipt_number !== $revenueEntry->receipt_number) {
                $existingReceipt = RevenueEntry::where('business_id', $businessId)
                    ->where('receipt_number', $request->receipt_number)
                    ->where('id', '!=', $revenueEntry->id)
                    ->exists();

                if ($existingReceipt) {
                    return errorResponse('A revenue entry with this receipt number already exists.', 400);
                }
            }

            DB::beginTransaction();

            $updateData = $request->only([
                'branch_id',
                'revenue_stream_id',
                'amount',
                'currency_id',
                'exchange_rate',
                'entry_date',
                'receipt_number',
                'notes'
            ]);

            // Handle file upload
            if ($request->hasFile('receipt_attachment')) {
                // Delete old file if exists
                if ($revenueEntry->receipt_attachment) {
                    Storage::disk('public')->delete($revenueEntry->receipt_attachment);
                }

                $file = $request->file('receipt_attachment');
                $filename = 'receipt_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $updateData['receipt_attachment'] = $file->storeAs(
                    "revenue-receipts/{$businessId}",
                    $filename,
                    'public'
                );
            }

            // Recalculate base currency amount if amount or exchange_rate changed
            $amount = $request->input('amount', $revenueEntry->amount);
            $exchangeRate = $request->input('exchange_rate', $revenueEntry->exchange_rate);
            $updateData['amount_in_base_currency'] = round($amount * $exchangeRate, 2);

            $revenueEntry->update($updateData);
            $revenueEntry->load(['branch', 'revenueStream', 'currency', 'recordedBy', 'approvedBy']);

            DB::commit();

            return updatedResponse(
                $this->transformRevenueEntry($revenueEntry),
                'Revenue entry updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to update revenue entry', [
                'entry_id' => $revenueEntry->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to update revenue entry', $e->getMessage());
        }
    }

    /**
     * Delete revenue entry (only pending entries can be deleted)
     */
    public function destroy(RevenueEntry $revenueEntry)
    {
        try {
            // Check business access
            if ($revenueEntry->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this revenue entry', 403);
            }

            // Only pending entries can be deleted
            if ($revenueEntry->status !== 'pending') {
                return errorResponse('Only pending entries can be deleted.', 400);
            }

            DB::beginTransaction();

            // Delete receipt file if exists
            if ($revenueEntry->receipt_attachment) {
                Storage::disk('public')->delete($revenueEntry->receipt_attachment);
            }

            $revenueEntry->delete();

            DB::commit();

            return deleteResponse('Revenue entry deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete revenue entry', [
                'entry_id' => $revenueEntry->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete revenue entry', $e->getMessage());
        }
    }

    /**
     * Approve a pending revenue entry
     */
    public function approve(RevenueEntry $revenueEntry)
    {
        try {
            // Check business access
            if ($revenueEntry->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this revenue entry', 403);
            }

            if ($revenueEntry->status !== 'pending') {
                return errorResponse('Only pending entries can be approved.', 400);
            }

            DB::beginTransaction();

            $revenueEntry->update([
                'status' => 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            $revenueEntry->load(['branch', 'revenueStream', 'currency', 'recordedBy', 'approvedBy']);

            DB::commit();

            return successResponse('Revenue entry approved successfully', $this->transformRevenueEntry($revenueEntry));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve revenue entry', [
                'entry_id' => $revenueEntry->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to approve revenue entry', $e->getMessage());
        }
    }

    /**
     * Reject a pending revenue entry
     */
    public function reject(Request $request, RevenueEntry $revenueEntry)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Check business access
            if ($revenueEntry->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this revenue entry', 403);
            }

            if ($revenueEntry->status !== 'pending') {
                return errorResponse('Only pending entries can be rejected.', 400);
            }

            DB::beginTransaction();

            $revenueEntry->update([
                'status' => 'rejected',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'notes' => $revenueEntry->notes . "\n\n[REJECTED]: " . $request->reason,
            ]);

            $revenueEntry->load(['branch', 'revenueStream', 'currency', 'recordedBy', 'approvedBy']);

            DB::commit();

            return successResponse('Revenue entry rejected', $this->transformRevenueEntry($revenueEntry));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reject revenue entry', [
                'entry_id' => $revenueEntry->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to reject revenue entry', $e->getMessage());
        }
    }

    /**
     * Get summary statistics for revenue entries
     */
    public function summary(Request $request)
    {
        try {
            $businessId = $request->user()->business_id;
            $branchId = $request->input('branch_id');
            $streamId = $request->input('revenue_stream_id');
            $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
            $dateTo = $request->input('date_to', now()->endOfMonth()->toDateString());

            $query = RevenueEntry::where('business_id', $businessId)
                ->where('status', 'approved')
                ->whereBetween('entry_date', [$dateFrom, $dateTo]);

            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            if ($streamId) {
                $query->where('revenue_stream_id', $streamId);
            }

            $totalInBaseCurrency = $query->sum('amount_in_base_currency');
            $entryCount = $query->count();

            // Get pending count
            $pendingQuery = RevenueEntry::where('business_id', $businessId)
                ->where('status', 'pending');

            if ($branchId) {
                $pendingQuery->where('branch_id', $branchId);
            }

            $pendingCount = $pendingQuery->count();
            $pendingAmount = $pendingQuery->sum('amount_in_base_currency');

            return successResponse('Revenue summary retrieved successfully', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'approved_entries' => $entryCount,
                'approved_total_base_currency' => round($totalInBaseCurrency, 2),
                'pending_entries' => $pendingCount,
                'pending_total_base_currency' => round($pendingAmount, 2),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve revenue summary', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve revenue summary', $e->getMessage());
        }
    }

    /**
     * Helper: Transform revenue entry data
     */
    private function transformRevenueEntry($entry)
    {
        // Get base currency from business
        $baseCurrency = $entry->business?->baseCurrency;

        return [
            'id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'business_id' => $entry->business_id,
            'branch_id' => $entry->branch_id,
            'branch' => $entry->branch ? [
                'id' => $entry->branch->id,
                'name' => $entry->branch->name,
            ] : null,
            'revenue_stream_id' => $entry->revenue_stream_id,
            'revenue_stream' => $entry->revenueStream ? [
                'id' => $entry->revenueStream->id,
                'name' => $entry->revenueStream->name,
            ] : null,
            'amount' => $entry->amount,
            'currency_id' => $entry->currency_id,
            'currency' => $entry->currency ? [
                'id' => $entry->currency->id,
                'code' => $entry->currency->code,
                'symbol' => $entry->currency->symbol,
            ] : null,
            'exchange_rate' => $entry->exchange_rate,
            'amount_in_base_currency' => $entry->amount_in_base_currency,
            'base_currency_id' => $baseCurrency?->id,
            'base_currency_code' => $baseCurrency?->code,
            'base_currency_symbol' => $baseCurrency?->symbol,
            'entry_date' => $entry->entry_date->format('Y-m-d'),
            'receipt_number' => $entry->receipt_number,
            'receipt_attachment' => $entry->receipt_attachment,
            'receipt_attachment_url' => $entry->receipt_attachment
                ? asset('storage/' . $entry->receipt_attachment)
                : null,
            'notes' => $entry->notes,
            'status' => $entry->status,
            'recorded_by' => $entry->recordedBy ? [
                'id' => $entry->recordedBy->id,
                'name' => $entry->recordedBy->name,
            ] : null,
            'approved_by' => $entry->approvedBy ? [
                'id' => $entry->approvedBy->id,
                'name' => $entry->approvedBy->name,
            ] : null,
            'approved_at' => $entry->approved_at?->format('Y-m-d H:i:s'),
            'created_at' => $entry->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $entry->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
