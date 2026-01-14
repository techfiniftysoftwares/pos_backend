<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendUserAccountCreatedNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Regular email/password login (for admin/web access)
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        // Determine if identifier is email or name/username
        $field = filter_var($request->identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'name';

        $user = User::where($field, $request->identifier)
            ->with(['business.baseCurrency', 'primaryBranch', 'branches', 'branchRoles'])
            ->first();

        if (!$user) {
            return notFoundResponse('User not found');
        }

        if (!$user->is_active) {
            $user->tokens()->delete();
            return errorResponse('User account is inactive', 403);
        }

        if (!$user->business || !$user->business->isActive()) {
            return errorResponse('Business account is inactive', 403);
        }

        // Attempt authentication
        if (
            Auth::attempt([
                $field => $request->identifier,
                'password' => $request->password
            ])
        ) {
            // Create access token
            $accessToken = $user->createToken('Web-Access')->accessToken;

            // Update last successful login
            $user->update(['last_login_at' => now()]);

            // Get the role for primary branch
            $primaryRole = $user->getPrimaryRole();

            return successResponse('Login successful', [
                'token' => $accessToken,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'employee_id' => $user->employee_id,
                    'role' => $primaryRole,
                    'business' => $user->business ? [
                        'id' => $user->business->id,
                        'name' => $user->business->name,
                        'currency' => $user->business->baseCurrency ? $user->business->baseCurrency->only(['id', 'code', 'symbol', 'name']) : null,
                    ] : null,
                    'primary_branch' => $user->primaryBranch?->only(['id', 'name', 'code']),
                    'accessible_branches' => $user->branches->map(function ($branch) use ($user) {
                        $branchRole = $user->getRoleForBranch($branch->id);
                        return [
                            'id' => $branch->id,
                            'name' => $branch->name,
                            'code' => $branch->code,
                            'role' => $branchRole ? $branchRole->only(['id', 'name']) : null,
                        ];
                    })
                ]
            ]);
        }

        return errorResponse('Invalid credentials', 401);
    }

    /**
     * Create new user with PIN
     */
    public function signup(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'phone' => 'nullable|string|max:20',
                'role_id' => 'required|exists:roles,id',
                'business_id' => 'required|exists:businesses,id',
                'primary_branch_id' => 'required|exists:branches,id',
                'pin' => 'required|string|min:4|max:6',
                'employee_id' => 'nullable|string|unique:users,employee_id',
                'is_active' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            // Validate that branch belongs to business
            $branch = \App\Models\Branch::find($request->primary_branch_id);
            if ($branch->business_id !== $request->business_id) {
                return errorResponse('Branch does not belong to the specified business', 400);
            }

            // Generate password and employee ID if not provided
            $password = $this->generatePassword(10);
            $employeeId = $request->employee_id ?? $this->generateEmployeeId($request->business_id);

            $authUser = Auth::user();

            // Create user
            $user = new User();
            $user->name = $request->input('name');
            $user->email = $request->input('email');
            $user->password = Hash::make($password);
            $user->phone = $request->input('phone');
            $user->role_id = $request->input('role_id');
            $user->business_id = $request->input('business_id');
            $user->primary_branch_id = $request->input('primary_branch_id');
            $user->pin = $request->input('pin');// Will be hashed by mutator
            $user->employee_id = $employeeId;
            $user->is_active = $request->input('is_active', true);
            $user->save();

            // Load relationships
            $user = $user->fresh(['role', 'business.baseCurrency', 'primaryBranch']);

            // Send account created notification
            SendUserAccountCreatedNotification::dispatch($user, $password, $authUser);

            return successResponse(
                'User created successfully. Account details will be sent to their email.',
                [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'employee_id' => $user->employee_id,
                        'role' => $user->role->only(['id', 'name']),
                        'business' => $user->business ? [
                            'id' => $user->business->id,
                            'name' => $user->business->name,
                            'currency' => $user->business->baseCurrency ? $user->business->baseCurrency->only(['id', 'code', 'symbol', 'name']) : null,
                        ] : null,
                        'primary_branch' => $user->primaryBranch->only(['id', 'name', 'code'])
                    ]
                ],
                201
            );
        } catch (\Exception $e) {
            Log::error('Failed to create user', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->except(['password', 'pin'])
            ]);

            return serverErrorResponse('Failed to create user', $e->getMessage());
        }
    }

    /**
     * Update user profile
     */
    public function profileChange(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:8|confirmed'
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $updateData = collect($request->only([
                'name',
                'email',
                'phone'
            ]))->filter()->toArray();

            if ($request->filled('password')) {
                $updateData['password'] = Hash::make($request->password);
            }

            $user->update($updateData);

            return updatedResponse($user, 'Profile updated successfully');
        } catch (\Exception $e) {
            Log::error('Failed to update profile', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to update profile', $e->getMessage());
        }
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        try {
            $request->user()->tokens()->delete();
            return successResponse('Successfully logged out');
        } catch (\Exception $e) {
            Log::error('Failed to logout', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to logout', $e->getMessage());
        }
    }

    /**
     * Generate employee ID
     */
    private function generateEmployeeId($businessId): string
    {
        $business = \App\Models\Business::find($businessId);
        $businessPrefix = strtoupper(substr($business->name, 0, 2));
        $userCount = User::where('business_id', $businessId)->count();

        return $businessPrefix . str_pad($userCount + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a secure random password
     */
    private function generatePassword($length = 12)
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '!@#$%^&*()_-=+;:,.?';

        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        $all = $uppercase . $lowercase . $numbers . $special;
        for ($i = strlen($password); $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }
}