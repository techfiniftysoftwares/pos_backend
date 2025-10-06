<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashReconciliation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class CashMovementController extends Controller
{
    /**
     * Display all cash movements
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $movementType = $request->input('movement_type');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $reconciliationId = $request->input('cash_reconciliation_id');

            $query = CashMovement::query()
                ->with(['business', 'branch', 'processedBy', 'cashReconciliation']);

            // Filter by business
            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            // Filter by branch
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            // Filter by movement type
            if ($movementType) {
                $query->where('movement_type', $movementType);
            }

            // Filter by reconciliation
            if ($reconciliationId) {
                $query->where('cash_reconciliation_id', $reconciliationId);
            }

            // Date range filter
            if ($dateFrom) {
                $query->whereDate('movement_time', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('movement_time', '<=', $dateTo);
            }

            $movements = $query->orderBy('movement_time', 'desc')
                ->paginate($perPage);

            return successResponse('Cash movements retrieved successfully', $movements);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve cash movements', $e->getMessage());
        }
    }

    /**
     * Record new cash movement
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'branch_id' => 'required|exists:branches,id',
            'cash_reconciliation_id' => 'nullable|exists:cash_reconciliations,id',
            'movement_type' => 'required|in:cash_in,cash_out,cash_drop,opening_float,expense',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|size:3',
            'reason' => 'required|string|max:255',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'movement_time' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $movement = CashMovement::create([
                'business_id' => $request->business_id,
                'branch_id' => $request->branch_id,
                'cash_reconciliation_id' => $request->cash_reconciliation_id,
                'movement_type' => $request->movement_type,
                'amount' => $request->amount,
                'currency' => $request->currency ?? 'USD',
                'reason' => $request->reason,
                'reference_number' => $request->reference_number,
                'notes' => $request->notes,
                'processed_by' => Auth::id(),
                'movement_time' => $request->movement_time ?? now(),
            ]);

            // Update reconciliation if linked
            if ($request->cash_reconciliation_id) {
                $reconciliation = CashReconciliation::find($request->cash_reconciliation_id);

                if ($reconciliation) {
                    if ($request->movement_type === 'cash_drop') {
                        $reconciliation->cash_drops += $request->amount;
                    } elseif ($request->movement_type === 'expense') {
                        $reconciliation->cash_expenses += $request->amount;
                    } elseif ($request->movement_type === 'opening_float') {
                        $reconciliation->opening_float = $request->amount;
                    }

                    $reconciliation->save();
                }
            }

            DB::commit();

            $movement->load(['business', 'branch', 'processedBy', 'cashReconciliation']);

            return successResponse('Cash movement recorded successfully', $movement, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to record cash movement', $e->getMessage());
        }
    }

    /**
     * Display specific cash movement
     */
    public function show(CashMovement $cashMovement)
    {
        try {
            $cashMovement->load([
                'business',
                'branch',
                'processedBy',
                'cashReconciliation'
            ]);

            return successResponse('Cash movement retrieved successfully', $cashMovement);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve cash movement', $e->getMessage());
        }
    }

    /**
     * Update cash movement
     */
    public function update(Request $request, CashMovement $cashMovement)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'sometimes|nullable|string',
            'reason' => 'sometimes|string|max:255',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $cashMovement->update($validator->validated());

            return updatedResponse($cashMovement, 'Cash movement updated successfully');
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to update cash movement', $e->getMessage());
        }
    }

    /**
     * Delete cash movement
     */
    public function destroy(CashMovement $cashMovement)
    {
        try {
            DB::beginTransaction();

            // Update reconciliation totals if linked
            if ($cashMovement->cash_reconciliation_id) {
                $reconciliation = $cashMovement->cashReconciliation;

                if ($reconciliation) {
                    if ($cashMovement->movement_type === 'cash_drop') {
                        $reconciliation->cash_drops -= $cashMovement->amount;
                    } elseif ($cashMovement->movement_type === 'expense') {
                        $reconciliation->cash_expenses -= $cashMovement->amount;
                    }

                    $reconciliation->save();
                }
            }

            $cashMovement->delete();

            DB::commit();

            return deleteResponse('Cash movement deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to delete cash movement', $e->getMessage());
        }
    }

    /**
     * Record cash drop
     */
    public function recordCashDrop(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|exists:branches,id',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'sometimes|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $movement = CashMovement::create([
                'business_id' => Auth::user()->business_id,
                'branch_id' => $request->branch_id,
                'movement_type' => 'cash_drop',
                'amount' => $request->amount,
                'currency' => 'USD',
                'reason' => $request->reason ?? 'Safe drop',
                'notes' => $request->notes,
                'processed_by' => Auth::id(),
            ]);

            return successResponse('Cash drop recorded successfully', $movement, 201);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to record cash drop', $e->getMessage());
        }
    }

    /**
     * Record opening float
     */
    public function recordOpeningFloat(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|exists:branches,id',
            'amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $movement = CashMovement::create([
                'business_id' => Auth::user()->business_id,
                'branch_id' => $request->branch_id,
                'movement_type' => 'opening_float',
                'amount' => $request->amount,
                'currency' => 'USD',
                'reason' => 'Opening float for shift',
                'notes' => $request->notes,
                'processed_by' => Auth::id(),
            ]);

            return successResponse('Opening float recorded successfully', $movement, 201);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to record opening float', $e->getMessage());
        }
    }

    /**
     * Record expense from register
     */
    public function recordExpense(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|exists:branches,id',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $movement = CashMovement::create([
                'business_id' => Auth::user()->business_id,
                'branch_id' => $request->branch_id,
                'movement_type' => 'expense',
                'amount' => $request->amount,
                'currency' => 'USD',
                'reason' => $request->reason,
                'notes' => $request->notes,
                'processed_by' => Auth::id(),
            ]);

            return successResponse('Expense recorded successfully', $movement, 201);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to record expense', $e->getMessage());
        }
    }

    /**
     * Get summary by movement type
     */
    public function summary(Request $request)
    {
        try {
            $branchId = $request->input('branch_id');
            $dateFrom = $request->input('date_from', now()->startOfDay());
            $dateTo = $request->input('date_to', now()->endOfDay());

            $query = CashMovement::query()->where('branch_id', $branchId);

            if ($dateFrom && $dateTo) {
                $query->whereBetween('movement_time', [$dateFrom, $dateTo]);
            }

            $movements = $query->get();

            $summary = [
                'cash_in' => $movements->where('movement_type', 'cash_in')->sum('amount'),
                'cash_out' => $movements->where('movement_type', 'cash_out')->sum('amount'),
                'cash_drops' => $movements->where('movement_type', 'cash_drop')->sum('amount'),
                'opening_float' => $movements->where('movement_type', 'opening_float')->sum('amount'),
                'expenses' => $movements->where('movement_type', 'expense')->sum('amount'),
                'total_movements' => $movements->count(),
            ];

            return successResponse('Cash movement summary retrieved successfully', $summary);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve cash movement summary', $e->getMessage());
        }
    }
}
