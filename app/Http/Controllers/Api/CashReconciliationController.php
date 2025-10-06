<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashReconciliation;
use App\Models\CashMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class CashReconciliationController extends Controller
{
    /**
     * Display all cash reconciliations
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $status = $request->input('status');
            $shiftType = $request->input('shift_type');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');

            $query = CashReconciliation::query()
                ->with(['business', 'branch', 'user', 'reconciledBy', 'approvedBy']);

            // Filter by business
            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            // Filter by branch
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            // Filter by status
            if ($status) {
                $query->where('status', $status);
            }

            // Filter by shift type
            if ($shiftType) {
                $query->where('shift_type', $shiftType);
            }

            // Date range filter
            if ($dateFrom) {
                $query->whereDate('reconciliation_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('reconciliation_date', '<=', $dateTo);
            }

            $reconciliations = $query->orderBy('reconciliation_date', 'desc')
                ->paginate($perPage);

            return successResponse('Cash reconciliations retrieved successfully', $reconciliations);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve cash reconciliations', $e->getMessage());
        }
    }

    /**
     * Create new cash reconciliation
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'branch_id' => 'required|exists:branches,id',
            'user_id' => 'required|exists:users,id',
            'reconciliation_date' => 'nullable|date',
            'shift_type' => 'sometimes|in:morning,afternoon,evening,full_day',
            'opening_float' => 'required|numeric|min:0',
            'actual_cash' => 'required|numeric|min:0',
            'cash_sales' => 'nullable|numeric|min:0',
            'cash_payments_received' => 'nullable|numeric|min:0',
            'cash_refunds' => 'nullable|numeric|min:0',
            'cash_expenses' => 'nullable|numeric|min:0',
            'cash_drops' => 'nullable|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'currency_breakdown' => 'nullable|array',
            'currency_breakdown.*.denomination' => 'required|numeric',
            'currency_breakdown.*.quantity' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $reconciliation = CashReconciliation::create([
                'business_id' => $request->business_id,
                'branch_id' => $request->branch_id,
                'user_id' => $request->user_id,
                'reconciliation_date' => $request->reconciliation_date ?? now()->toDateString(),
                'shift_type' => $request->shift_type ?? 'full_day',
                'opening_float' => $request->opening_float,
                'actual_cash' => $request->actual_cash,
                'cash_sales' => $request->cash_sales ?? 0,
                'cash_payments_received' => $request->cash_payments_received ?? 0,
                'cash_refunds' => $request->cash_refunds ?? 0,
                'cash_expenses' => $request->cash_expenses ?? 0,
                'cash_drops' => $request->cash_drops ?? 0,
                'currency' => $request->currency ?? 'USD',
                'currency_breakdown' => $request->currency_breakdown,
                'notes' => $request->notes,
                'reconciled_by' => Auth::id(),
            ]);

            DB::commit();

            $reconciliation->load(['business', 'branch', 'user', 'reconciledBy']);

            return successResponse('Cash reconciliation created successfully', $reconciliation, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to create cash reconciliation', $e->getMessage());
        }
    }

    /**
     * Display specific cash reconciliation
     */
    public function show(CashReconciliation $cashReconciliation)
    {
        try {
            $cashReconciliation->load([
                'business',
                'branch',
                'user',
                'reconciledBy',
                'approvedBy',
                'cashMovements.processedBy'
            ]);

            $reconciliationData = [
                'id' => $cashReconciliation->id,
                'reconciliation_date' => $cashReconciliation->reconciliation_date->format('Y-m-d'),
                'shift_type' => $cashReconciliation->shift_type,
                'opening_float' => $cashReconciliation->opening_float,
                'expected_cash' => $cashReconciliation->expected_cash,
                'actual_cash' => $cashReconciliation->actual_cash,
                'variance' => $cashReconciliation->variance,
                'variance_percentage' => $cashReconciliation->expected_cash > 0
                    ? round(($cashReconciliation->variance / $cashReconciliation->expected_cash) * 100, 2)
                    : 0,
                'cash_sales' => $cashReconciliation->cash_sales,
                'cash_payments_received' => $cashReconciliation->cash_payments_received,
                'cash_refunds' => $cashReconciliation->cash_refunds,
                'cash_expenses' => $cashReconciliation->cash_expenses,
                'cash_drops' => $cashReconciliation->cash_drops,
                'currency' => $cashReconciliation->currency,
                'currency_breakdown' => $cashReconciliation->currency_breakdown,
                'status' => $cashReconciliation->status,
                'notes' => $cashReconciliation->notes,
                'approved_at' => $cashReconciliation->approved_at?->format('Y-m-d H:i:s'),
                'business' => $cashReconciliation->business->only(['id', 'name']),
                'branch' => $cashReconciliation->branch->only(['id', 'name', 'code']),
                'user' => $cashReconciliation->user->only(['id', 'name', 'employee_id']),
                'reconciled_by' => $cashReconciliation->reconciledBy?->only(['id', 'name']),
                'approved_by' => $cashReconciliation->approvedBy?->only(['id', 'name']),
                'cash_movements' => $cashReconciliation->cashMovements->map(function ($movement) {
                    return [
                        'id' => $movement->id,
                        'movement_type' => $movement->movement_type,
                        'amount' => $movement->amount,
                        'reason' => $movement->reason,
                        'reference_number' => $movement->reference_number,
                        'movement_time' => $movement->movement_time->format('Y-m-d H:i:s'),
                        'processed_by' => $movement->processedBy->name,
                    ];
                }),
            ];

            return successResponse('Cash reconciliation retrieved successfully', $reconciliationData);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve cash reconciliation', $e->getMessage());
        }
    }

    /**
     * Update cash reconciliation
     */
    public function update(Request $request, CashReconciliation $cashReconciliation)
    {
        $validator = Validator::make($request->all(), [
            'actual_cash' => 'sometimes|numeric|min:0',
            'cash_sales' => 'sometimes|numeric|min:0',
            'cash_payments_received' => 'sometimes|numeric|min:0',
            'cash_refunds' => 'sometimes|numeric|min:0',
            'cash_expenses' => 'sometimes|numeric|min:0',
            'cash_drops' => 'sometimes|numeric|min:0',
            'currency_breakdown' => 'sometimes|nullable|array',
            'notes' => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            if ($cashReconciliation->status === 'approved') {
                return errorResponse('Cannot update approved reconciliation', 400);
            }

            $cashReconciliation->update($validator->validated());

            return updatedResponse($cashReconciliation, 'Cash reconciliation updated successfully');
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to update cash reconciliation', $e->getMessage());
        }
    }

    /**
     * Delete cash reconciliation
     */
    public function destroy(CashReconciliation $cashReconciliation)
    {
        try {
            if ($cashReconciliation->status === 'approved') {
                return errorResponse('Cannot delete approved reconciliation', 400);
            }

            DB::beginTransaction();

            $cashReconciliation->delete();

            DB::commit();

            return deleteResponse('Cash reconciliation deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to delete cash reconciliation', $e->getMessage());
        }
    }

    /**
     * Complete reconciliation
     */
    public function complete(CashReconciliation $cashReconciliation)
    {
        try {
            if ($cashReconciliation->status !== 'pending') {
                return errorResponse('Only pending reconciliations can be completed', 400);
            }

            $cashReconciliation->status = 'completed';
            $cashReconciliation->save();

            return successResponse('Cash reconciliation completed successfully', $cashReconciliation);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to complete cash reconciliation', $e->getMessage());
        }
    }

    /**
     * Approve reconciliation
     */
    public function approve(CashReconciliation $cashReconciliation)
    {
        try {
            if ($cashReconciliation->status === 'approved') {
                return errorResponse('Reconciliation already approved', 400);
            }

            $cashReconciliation->status = 'approved';
            $cashReconciliation->approved_by = Auth::id();
            $cashReconciliation->approved_at = now();
            $cashReconciliation->save();

            return successResponse('Cash reconciliation approved successfully', $cashReconciliation);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to approve cash reconciliation', $e->getMessage());
        }
    }

    /**
     * Dispute reconciliation
     */
    public function dispute(Request $request, CashReconciliation $cashReconciliation)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $cashReconciliation->status = 'disputed';
            $cashReconciliation->notes = ($cashReconciliation->notes ? $cashReconciliation->notes . "\n\n" : '')
                . "DISPUTED: " . $request->reason;
            $cashReconciliation->save();

            return successResponse('Cash reconciliation marked as disputed', $cashReconciliation);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to dispute cash reconciliation', $e->getMessage());
        }
    }

    /**
 * Get variance report
 */
public function varianceReport(Request $request)
{
    try {
        $businessId = $request->input('business_id');
        $branchId = $request->input('branch_id');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = CashReconciliation::query();

        if ($businessId) {
            $query->where('business_id', $businessId);
        }

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        if ($dateFrom && $dateTo) {
            $query->whereBetween('reconciliation_date', [$dateFrom, $dateTo]);
        }

        $reconciliations = $query->get();

        $reconciliationsWithVariance = $reconciliations->filter(function($rec) {
            return $rec->variance != 0;
        });

        $reconciliationsOver = $reconciliations->filter(function($rec) {
            return $rec->variance > 0;
        });

        $reconciliationsShort = $reconciliations->filter(function($rec) {
            return $rec->variance < 0;
        });

        $report = [
            'total_reconciliations' => $reconciliations->count(),
            'total_variance' => $reconciliations->sum('variance'),
            'total_over' => $reconciliationsOver->sum('variance'),
            'total_short' => abs($reconciliationsShort->sum('variance')),
            'reconciliations_with_variance' => $reconciliationsWithVariance->count(),
            'reconciliations_over' => $reconciliationsOver->count(),
            'reconciliations_short' => $reconciliationsShort->count(),
            'average_variance' => $reconciliations->avg('variance'),
            'details' => $reconciliationsWithVariance->map(function ($rec) {
                return [
                    'id' => $rec->id,
                    'date' => $rec->reconciliation_date ? $rec->reconciliation_date->format('Y-m-d') : null,
                    'shift' => $rec->shift_type,
                    'expected' => $rec->expected_cash,
                    'actual' => $rec->actual_cash,
                    'variance' => $rec->variance,
                    'status' => $rec->status,
                ];
            })->values(),
        ];

        return successResponse('Variance report generated successfully', $report);
    } catch (\Exception $e) {
        return queryErrorResponse('Failed to generate variance report', $e->getMessage());
    }
}
    /**
     * Record cash movement
     */
    public function recordCashMovement(Request $request, CashReconciliation $cashReconciliation)
    {
        $validator = Validator::make($request->all(), [
            'movement_type' => 'required|in:cash_in,cash_out,cash_drop,opening_float,expense',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|size:3',
            'reason' => 'required|string',
            'reference_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $movement = CashMovement::create([
                'cash_reconciliation_id' => $cashReconciliation->id,
                'business_id' => $cashReconciliation->business_id,
                'branch_id' => $cashReconciliation->branch_id,
                'movement_type' => $request->movement_type,
                'amount' => $request->amount,
                'currency' => $request->currency ?? $cashReconciliation->currency,
                'reason' => $request->reason,
                'reference_number' => $request->reference_number,
                'notes' => $request->notes,
                'processed_by' => Auth::id(),
                'movement_time' => now(),
            ]);

            // Update reconciliation totals based on movement type
            if ($request->movement_type === 'cash_drop') {
                $cashReconciliation->cash_drops += $request->amount;
            } elseif ($request->movement_type === 'expense') {
                $cashReconciliation->cash_expenses += $request->amount;
            } elseif ($request->movement_type === 'opening_float') {
                $cashReconciliation->opening_float = $request->amount;
            }

            $cashReconciliation->save();

            DB::commit();

            $movement->load('processedBy');

            return successResponse('Cash movement recorded successfully', $movement, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to record cash movement', $e->getMessage());
        }
    }

    /**
     * Get cash movements for reconciliation
     */
    public function getCashMovements(CashReconciliation $cashReconciliation)
    {
        try {
            $movements = $cashReconciliation->cashMovements()
                ->with('processedBy')
                ->orderBy('movement_time', 'asc')
                ->get();

            return successResponse('Cash movements retrieved successfully', $movements);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve cash movements', $e->getMessage());
        }
    }
}
