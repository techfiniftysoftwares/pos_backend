<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashReconciliation;
use App\Models\CashMovement;
use App\Models\Sale;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CashRegisterController extends Controller
{
    /**
     * Get all cash register sessions
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $branchId = $request->input('branch_id');
            $status = $request->input('status');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');

            $query = CashReconciliation::query()
                ->with(['business', 'branch', 'openedBy', 'closedBy', 'cashMovements']);

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            if ($status) {
                $query->where('status', $status);
            }

            if ($dateFrom) {
                $query->whereDate('opened_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('opened_at', '<=', $dateTo);
            }

            $sessions = $query->orderBy('opened_at', 'desc')
                ->paginate($perPage);

            return successResponse('Cash register sessions retrieved successfully', $sessions);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve cash register sessions', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve sessions', $e->getMessage());
        }
    }

    /**
     * Open cash register (start shift)
     */
    public function open(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'branch_id' => 'required|exists:branches,id',
            'opening_float' => 'required|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            // Check if there's already an open session for this branch
            $openSession = CashReconciliation::where('branch_id', $request->branch_id)
                ->where('status', 'open')
                ->first();

            if ($openSession) {
                return errorResponse('There is already an open cash register session for this branch', 400);
            }

            // Create cash reconciliation record
            $session = CashReconciliation::create([
                'business_id' => $request->business_id,
                'branch_id' => $request->branch_id,
                'opened_by' => Auth::id(),
                'opened_at' => now(),
                'opening_float' => $request->opening_float,
                'currency' => $request->currency ?? 'USD',
                'status' => 'open',
                'notes' => $request->notes,
            ]);

            // Record opening float as cash movement
            CashMovement::create([
                'cash_reconciliation_id' => $session->id,
                'business_id' => $request->business_id,
                'branch_id' => $request->branch_id,
                'movement_type' => 'opening_float',
                'amount' => $request->opening_float,
                'currency' => $request->currency ?? 'USD',
                'reason' => 'Opening float',
                'notes' => 'Cash register opened',
                'processed_by' => Auth::id(),
            ]);

            DB::commit();

            $session->load(['business', 'branch', 'openedBy']);

            return successResponse('Cash register opened successfully', $session, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to open cash register', [
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to open cash register', $e->getMessage());
        }
    }

    /**
     * Close cash register (end shift with reconciliation)
     */
    public function close(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'session_id' => 'required|exists:cash_reconciliations,id',
            'actual_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $session = CashReconciliation::findOrFail($request->session_id);

            // Validate session is open
            if ($session->status !== 'open') {
                return errorResponse('This cash register session is already closed', 400);
            }

            // Calculate expected cash from sales
            $cashSales = Payment::where('branch_id', $session->branch_id)
                ->whereHas('paymentMethod', function($query) {
                    $query->where('type', 'cash');
                })
                ->where('status', 'completed')
                ->where('payment_type', 'payment')
                ->whereBetween('payment_date', [$session->opened_at, now()])
                ->sum('amount');

            // Get cash movements (drops and expenses)
            $cashDrops = CashMovement::where('cash_reconciliation_id', $session->id)
                ->where('movement_type', 'cash_drop')
                ->sum('amount');

            $expenses = CashMovement::where('cash_reconciliation_id', $session->id)
                ->where('movement_type', 'expense')
                ->sum('amount');

            // Calculate expected cash
            $expectedCash = $session->opening_float + $cashSales - $cashDrops - $expenses;
            $variance = $request->actual_cash - $expectedCash;

            // Update session
            $session->update([
                'closed_by' => Auth::id(),
                'closed_at' => now(),
                'expected_cash' => $expectedCash,
                'actual_cash' => $request->actual_cash,
                'variance' => $variance,
                'total_sales' => $cashSales,
                'cash_drops' => $cashDrops,
                'expenses' => $expenses,
                'status' => 'closed',
                'notes' => $session->notes . ($request->notes ? "\nClosing notes: {$request->notes}" : ''),
            ]);

            DB::commit();

            $session->load(['business', 'branch', 'openedBy', 'closedBy', 'cashMovements']);

            return successResponse('Cash register closed successfully', $session);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to close cash register', [
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to close cash register', $e->getMessage());
        }
    }

    /**
     * Get current active session
     */
    public function currentSession(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|exists:branches,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $session = CashReconciliation::where('branch_id', $request->branch_id)
                ->where('status', 'open')
                ->with(['business', 'branch', 'openedBy', 'cashMovements'])
                ->first();

            if (!$session) {
                return notFoundResponse('No active cash register session found for this branch');
            }

            // Calculate current totals
            $cashSales = Payment::where('branch_id', $session->branch_id)
                ->whereHas('paymentMethod', function($query) {
                    $query->where('type', 'cash');
                })
                ->where('status', 'completed')
                ->where('payment_type', 'payment')
                ->whereBetween('payment_date', [$session->opened_at, now()])
                ->sum('amount');

            $cashDrops = CashMovement::where('cash_reconciliation_id', $session->id)
                ->where('movement_type', 'cash_drop')
                ->sum('amount');

            $expenses = CashMovement::where('cash_reconciliation_id', $session->id)
                ->where('movement_type', 'expense')
                ->sum('amount');

            $currentCash = $session->opening_float + $cashSales - $cashDrops - $expenses;

            $sessionData = $session->toArray();
            $sessionData['current_cash_sales'] = round($cashSales, 2);
            $sessionData['current_cash_drops'] = round($cashDrops, 2);
            $sessionData['current_expenses'] = round($expenses, 2);
            $sessionData['current_expected_cash'] = round($currentCash, 2);

            return successResponse('Current session retrieved successfully', $sessionData);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve current session', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve current session', $e->getMessage());
        }
    }

    /**
     * Record cash drop during shift
     */
    public function cashDrop(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'session_id' => 'required|exists:cash_reconciliations,id',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $session = CashReconciliation::findOrFail($request->session_id);

            // Validate session is open
            if ($session->status !== 'open') {
                return errorResponse('Cannot record cash drop on a closed session', 400);
            }

            // Create cash movement
            $cashDrop = CashMovement::create([
                'cash_reconciliation_id' => $session->id,
                'business_id' => $session->business_id,
                'branch_id' => $session->branch_id,
                'movement_type' => 'cash_drop',
                'amount' => $request->amount,
                'currency' => $session->currency,
                'reason' => $request->reason,
                'notes' => $request->notes,
                'processed_by' => Auth::id(),
            ]);

            DB::commit();

            $cashDrop->load(['processedBy']);

            return successResponse('Cash drop recorded successfully', $cashDrop, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to record cash drop', [
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to record cash drop', $e->getMessage());
        }
    }

    /**
     * Display specific session
     */
    public function show(CashReconciliation $cashReconciliation)
    {
        try {
            $cashReconciliation->load([
                'business',
                'branch',
                'openedBy',
                'closedBy',
                'cashMovements.processedBy'
            ]);

            return successResponse('Session retrieved successfully', $cashReconciliation);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve session', [
                'session_id' => $cashReconciliation->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve session', $e->getMessage());
        }
    }
}
