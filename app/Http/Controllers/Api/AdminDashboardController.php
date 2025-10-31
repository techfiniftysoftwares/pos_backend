<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Business;
use App\Models\Branch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminDashboardController extends Controller
{
    /**
     * Function 1: System Overview Summary Cards
     * Simple 6 cards showing key metrics
     */
    public function getSystemOverview(Request $request)
    {
        try {
            $user = $request->user();
            $userBranchId = $user->primary_branch_id;
            $businessId = $user->business_id;

            // Check branch access
            $branch = Branch::find($userBranchId);
            if (!$branch || $branch->business_id !== $businessId) {
                return errorResponse('Unauthorized access to this branch', 403);
            }

            // ==========================================
            // SIMPLE COUNTS
            // ==========================================

            // Total users in this branch
            $totalUsers = User::where('primary_branch_id', $userBranchId)->count();
            $activeUsers = User::where('primary_branch_id', $userBranchId)
                ->where('is_active', true)
                ->count();

            // Total branches in this business
            $totalBranches = Branch::where('business_id', $businessId)->count();
            $activeBranches = Branch::where('business_id', $businessId)
                ->where('is_active', true)
                ->count();

            // Users logged in today
            $todayLogins = User::where('primary_branch_id', $userBranchId)
                ->whereDate('last_login_at', today())
                ->count();

            // Locked accounts
            $lockedUsers = User::where('primary_branch_id', $userBranchId)
                ->whereNotNull('pin_locked_until')
                ->where('pin_locked_until', '>', now())
                ->count();

            // ==========================================
            // SIMPLE RESPONSE
            // ==========================================
            $data = [
                'cards' => [
                    [
                        'title' => 'Total Users',
                        'value' => $totalUsers,
                        'subtitle' => "{$activeUsers} active",
                        'icon' => '👥',
                        'color' => 'blue'
                    ],
                    [
                        'title' => 'Active Today',
                        'value' => $todayLogins,
                        'subtitle' => 'Logged in today',
                        'icon' => '🟢',
                        'color' => 'green'
                    ],
                    [
                        'title' => 'Total Branches',
                        'value' => $totalBranches,
                        'subtitle' => "{$activeBranches} active",
                        'icon' => '🏪',
                        'color' => 'purple'
                    ],
                    [
                        'title' => 'Inactive Users',
                        'value' => $totalUsers - $activeUsers,
                        'subtitle' => 'Not active',
                        'icon' => '⚠️',
                        'color' => 'orange'
                    ],
                    [
                        'title' => 'Locked Accounts',
                        'value' => $lockedUsers,
                        'subtitle' => 'PIN locked',
                        'icon' => '🔒',
                        'color' => $lockedUsers > 0 ? 'red' : 'green'
                    ],
                    [
                        'title' => 'Your Branch',
                        'value' => $branch->name,
                        'subtitle' => $branch->is_main_branch ? 'Main Branch' : 'Branch',
                        'icon' => '🏢',
                        'color' => 'indigo'
                    ]
                ]
            ];

            return successResponse('System overview retrieved successfully', $data);

        } catch (\Exception $e) {
            Log::error('Failed to fetch system overview', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to fetch system overview', $e->getMessage());
        }
    }

    /**
     * Function 2: User Growth - Last 6 Months
     * Simple line chart showing new users per month
     */
    public function getUserGrowthTrend(Request $request)
    {
        try {
            $user = $request->user();
            $userBranchId = $user->primary_branch_id;
            $businessId = $user->business_id;

            // Check branch access
            $branch = Branch::find($userBranchId);
            if (!$branch || $branch->business_id !== $businessId) {
                return errorResponse('Unauthorized access to this branch', 403);
            }

            // Get filter (default 6 months)
            $monthsBack = $request->input('months', 6);

            // ==========================================
            // GET MONTHLY DATA - SIMPLE
            // ==========================================
            $startDate = now()->subMonths($monthsBack)->startOfMonth();
            $endDate = now();

            $monthlyData = User::where('primary_branch_id', $userBranchId)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('
                    DATE_FORMAT(created_at, "%Y-%m") as month,
                    COUNT(*) as new_users
                ')
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->keyBy('month');

            // Build simple month array
            $chartData = [];
            $currentDate = $startDate->copy();

            while ($currentDate <= $endDate) {
                $monthKey = $currentDate->format('Y-m');
                $monthData = $monthlyData->get($monthKey);

                $chartData[] = [
                    'month' => $currentDate->format('M Y'),
                    'new_users' => $monthData ? $monthData->new_users : 0
                ];

                $currentDate->addMonth();
            }

            // Simple summary
            $totalNewUsers = collect($chartData)->sum('new_users');

            $data = [
                'chart_data' => $chartData,
                'summary' => [
                    'total_new_users' => $totalNewUsers,
                    'period' => "Last {$monthsBack} months"
                ]
            ];

            return successResponse('User growth trend retrieved successfully', $data);

        } catch (\Exception $e) {
            Log::error('Failed to fetch user growth trend', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to fetch user growth trend', $e->getMessage());
        }
    }

    /**
     * Function 3: Users by Role
     * Simple bar chart showing user distribution by role
     */
    public function getUsersByRole(Request $request)
    {
        try {
            $user = $request->user();
            $userBranchId = $user->primary_branch_id;
            $businessId = $user->business_id;

            // Check branch access
            $branch = Branch::find($userBranchId);
            if (!$branch || $branch->business_id !== $businessId) {
                return errorResponse('Unauthorized access to this branch', 403);
            }

            // ==========================================
            // GET USERS BY ROLE - SIMPLE
            // ==========================================
            $roleData = User::where('primary_branch_id', $userBranchId)
                ->join('roles', 'users.role_id', '=', 'roles.id')
                ->select([
                    'roles.name as role_name',
                    DB::raw('COUNT(users.id) as user_count'),
                    DB::raw('SUM(CASE WHEN users.is_active = 1 THEN 1 ELSE 0 END) as active_count')
                ])
                ->groupBy('roles.id', 'roles.name')
                ->orderByDesc('user_count')
                ->get();

            // Simple color array
            $colors = ['#3B82F6', '#8B5CF6', '#10B981', '#F59E0B', '#EF4444', '#06B6D4'];

            $chartData = $roleData->map(function($role, $index) use ($colors) {
                return [
                    'role' => $role->role_name,
                    'total' => $role->user_count,
                    'active' => $role->active_count,
                    'color' => $colors[$index % count($colors)]
                ];
            });

            $data = [
                'chart_data' => $chartData,
                'summary' => [
                    'total_roles' => $roleData->count(),
                    'total_users' => $roleData->sum('user_count')
                ]
            ];

            return successResponse('Users by role retrieved successfully', $data);

        } catch (\Exception $e) {
            Log::error('Failed to fetch users by role', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to fetch users by role', $e->getMessage());
        }
    }
}
