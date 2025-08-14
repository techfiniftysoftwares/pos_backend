<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
/**
 * Controller for handling PIN-based authentication and management
 */

class PinAuthController extends Controller
{
    /**
     * PIN-based login for POS terminals
     */
    public function pinLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|string',
            'pin' => 'required|string|min:4|max:6',
            'branch_id' => 'required|exists:branches,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = User::where('employee_id', $request->employee_id)
                       ->with(['role', 'business', 'primaryBranch'])
                       ->first();

            if (!$user) {
                return errorResponse('Invalid employee ID', 401);
            }

            // Check if user is active
            if (!$user->is_active) {
                return errorResponse('User account is inactive', 403);
            }

            // Check if business is active
            if (!$user->business || !$user->business->isActive()) {
                return errorResponse('Business account is inactive', 403);
            }

            // Check if user can access this branch
            if (!$user->canAccessBranch($request->branch_id)) {
                return errorResponse('Access denied to this branch', 403);
            }

            // Check if PIN is locked
            if ($user->isPinLocked()) {
                $lockTimeRemaining = $user->pin_locked_until->diffInMinutes(now());
                return errorResponse("PIN is locked. Try again in {$lockTimeRemaining} minutes.", 423);
            }

            // Verify PIN
            if (!$user->verifyPin($request->pin)) {
                $attemptsLeft = 3 - $user->failed_pin_attempts;

                if ($attemptsLeft <= 0) {
                    return errorResponse('PIN locked due to multiple failed attempts. Try again in 15 minutes.', 423);
                }

                return errorResponse("Invalid PIN. {$attemptsLeft} attempts remaining.", 401);
            }

            // Create access token
            $accessToken = $user->createToken('POS-Access', ['pos:access'])->accessToken;

            return successResponse('PIN login successful', [
                'token' => $accessToken,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'employee_id' => $user->employee_id,
                    'role' => $user->role->only(['id', 'name']),
                    'business' => $user->business->only(['id', 'name']),
                    'primary_branch' => $user->primaryBranch->only(['id', 'name', 'code']),
                    'accessible_branches' => $user->branches->map(function($branch) {
                        return $branch->only(['id', 'name', 'code']);
                    }),
                    'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('PIN login failed', [
                'employee_id' => $request->employee_id,
                'branch_id' => $request->branch_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return serverErrorResponse('PIN login failed', $e->getMessage());
        }
    }

    /**
     * Change PIN
     */
    public function changePin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_pin' => 'required|string|min:4|max:6',
            'new_pin' => 'required|string|min:4|max:6|different:current_pin',
            'new_pin_confirmation' => 'required|string|same:new_pin',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = $request->user();

            // Verify current PIN
            if (!$user->verifyPin($request->current_pin)) {
                return errorResponse('Current PIN is incorrect', 401);
            }

            // Update to new PIN
            $user->setPin($request->new_pin);
            $user->save();

            return successResponse('PIN changed successfully');

        } catch (\Exception $e) {
            Log::error('PIN change failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('PIN change failed', $e->getMessage());
        }
    }

    /**
     * Reset PIN (Admin only)
     */
    public function resetUserPin(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'new_pin' => 'required|string|min:4|max:6',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Check if requesting user can manage this user
            $requestingUser = $request->user();

            if ($requestingUser->business_id !== $user->business_id) {
                return errorResponse('Cannot manage users from different business', 403);
            }

            // Reset PIN
            $user->setPin($request->new_pin);
            $user->failed_pin_attempts = 0;
            $user->pin_locked_until = null;
            $user->save();

            // Revoke existing tokens for security
            $user->tokens()->delete();

            return successResponse('PIN reset successfully');

        } catch (\Exception $e) {
            Log::error('PIN reset failed', [
                'admin_user_id' => $request->user()->id,
                'target_user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('PIN reset failed', $e->getMessage());
        }
    }

    /**
     * Quick switch branch (for users with multi-branch access)
     */
    public function switchBranch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|exists:branches,id',
            'pin' => 'required|string|min:4|max:6',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = $request->user();

            // Verify PIN for security
            if (!$user->verifyPin($request->pin)) {
                return errorResponse('Invalid PIN', 401);
            }

            // Check if user can access this branch
            if (!$user->canAccessBranch($request->branch_id)) {
                return errorResponse('Access denied to this branch', 403);
            }

            // Create new token with branch context
            $branchToken = $user->createToken('POS-Branch-Access', ['pos:access'])->accessToken;

            $branch = $user->branches()->find($request->branch_id) ?? $user->primaryBranch;

            return successResponse('Branch switched successfully', [
                'token' => $branchToken,
                'current_branch' => $branch->only(['id', 'name', 'code'])
            ]);

        } catch (\Exception $e) {
            Log::error('Branch switch failed', [
                'user_id' => $request->user()->id,
                'branch_id' => $request->branch_id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Branch switch failed', $e->getMessage());
        }
    }

    /**
     * POS logout
     */
    public function pinLogout(Request $request)
    {
        try {
            $request->user()->token()->delete();
            return successResponse('Logged out successfully');
        } catch (\Exception $e) {
            Log::error('PIN logout failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Logout failed', $e->getMessage());
        }
    }
}
