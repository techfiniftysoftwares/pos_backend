<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Business;
use App\Models\Role;
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
    /**
 * Function 4: User Summary Cards
 * Shows key user metrics in simple cards
 */
public function getUserSummary(Request $request)
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

        // Get date range filter (optional)
        $dateRange = $request->input('date_range', 30); // Default 30 days

        // ==========================================
        // CARD 1: TOTAL USERS
        // ==========================================
        $totalUsers = User::where('primary_branch_id', $userBranchId)->count();

        // ==========================================
        // CARD 2: NEW USERS THIS MONTH
        // ==========================================
        $newUsersThisMonth = User::where('primary_branch_id', $userBranchId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        // Last month for comparison
        $newUsersLastMonth = User::where('primary_branch_id', $userBranchId)
            ->whereYear('created_at', now()->subMonth()->year)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->count();

        $monthlyTrend = $newUsersLastMonth > 0
            ? (($newUsersThisMonth - $newUsersLastMonth) / $newUsersLastMonth) * 100
            : 0;

        // ==========================================
        // CARD 3: ACTIVE USERS (LAST 7 DAYS)
        // ==========================================
        $activeUsersLast7Days = User::where('primary_branch_id', $userBranchId)
            ->where('last_login_at', '>=', now()->subDays(7))
            ->count();

        $activePercentage = $totalUsers > 0
            ? round(($activeUsersLast7Days / $totalUsers) * 100, 1)
            : 0;

        // ==========================================
        // CARD 4: LOCKED ACCOUNTS
        // ==========================================
        $lockedAccounts = User::where('primary_branch_id', $userBranchId)
            ->whereNotNull('pin_locked_until')
            ->where('pin_locked_until', '>', now())
            ->count();

        $failedAttempts = User::where('primary_branch_id', $userBranchId)
            ->where('failed_pin_attempts', '>', 0)
            ->sum('failed_pin_attempts');

        // ==========================================
        // CARD 5: AVERAGE USERS PER BRANCH
        // ==========================================
        $totalBranches = Branch::where('business_id', $businessId)->count();
        $totalUsersInBusiness = User::whereHas('primaryBranch', function($q) use ($businessId) {
            $q->where('business_id', $businessId);
        })->count();

        $averageUsersPerBranch = $totalBranches > 0
            ? round($totalUsersInBusiness / $totalBranches, 1)
            : 0;

        // ==========================================
        // RESPONSE
        // ==========================================
        $data = [
            'cards' => [
                [
                    'title' => 'Total Users',
                    'value' => $totalUsers,
                    'subtitle' => 'In your branch',
                    'icon' => '👥',
                    'color' => 'blue'
                ],
                [
                    'title' => 'New This Month',
                    'value' => $newUsersThisMonth,
                    'subtitle' => $monthlyTrend > 0
                        ? "↑ " . round(abs($monthlyTrend), 1) . "% vs last month"
                        : ($monthlyTrend < 0
                            ? "↓ " . round(abs($monthlyTrend), 1) . "% vs last month"
                            : "No change"),
                    'icon' => '✨',
                    'color' => $monthlyTrend >= 0 ? 'green' : 'orange'
                ],
                [
                    'title' => 'Active (Last 7 Days)',
                    'value' => $activeUsersLast7Days,
                    'subtitle' => $activePercentage . "% of total users",
                    'icon' => '🟢',
                    'color' => 'green'
                ],
                [
                    'title' => 'Locked Accounts',
                    'value' => $lockedAccounts,
                    'subtitle' => $failedAttempts . " total failed attempts",
                    'icon' => '🔒',
                    'color' => $lockedAccounts > 0 ? 'red' : 'gray'
                ],
                [
                    'title' => 'Avg per Branch',
                    'value' => $averageUsersPerBranch,
                    'subtitle' => "Across {$totalBranches} branches",
                    'icon' => '📊',
                    'color' => 'purple'
                ]
            ],

            'summary' => [
                'total_users' => $totalUsers,
                'new_this_month' => $newUsersThisMonth,
                'active_last_7_days' => $activeUsersLast7Days,
                'locked_accounts' => $lockedAccounts,
                'average_per_branch' => $averageUsersPerBranch
            ]
        ];

        return successResponse('User summary retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch user summary', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch user summary', $e->getMessage());
    }
}

/**
 * Function 5: User Login Activity (Quick Fix)
 * Shows simple login statistics
 */
public function getUserLoginActivity(Request $request)
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
        // SIMPLE LOGIN STATS
        // ==========================================

        // Logged in today
        $loginsToday = User::where('primary_branch_id', $userBranchId)
            ->whereDate('last_login_at', today())
            ->count();

        // Logged in yesterday
        $loginsYesterday = User::where('primary_branch_id', $userBranchId)
            ->whereDate('last_login_at', now()->subDay()->toDateString())
            ->count();

        // Logged in this week (last 7 days)
        $loginsThisWeek = User::where('primary_branch_id', $userBranchId)
            ->where('last_login_at', '>=', now()->subDays(7))
            ->count();

        // Logged in this month
        $loginsThisMonth = User::where('primary_branch_id', $userBranchId)
            ->whereYear('last_login_at', now()->year)
            ->whereMonth('last_login_at', now()->month)
            ->count();

        // Never logged in
        $neverLoggedIn = User::where('primary_branch_id', $userBranchId)
            ->whereNull('last_login_at')
            ->count();

        // Total users for percentage
        $totalUsers = User::where('primary_branch_id', $userBranchId)->count();

        // ==========================================
        // SIMPLE CHART DATA (Last 7 days)
        // ==========================================
        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $count = User::where('primary_branch_id', $userBranchId)
                ->whereDate('last_login_at', $date->toDateString())
                ->count();

            $chartData[] = [
                'period' => $date->format('M d'),
                'logins' => $count
            ];
        }

        // ==========================================
        // RESPONSE
        // ==========================================
        $data = [
            'chart_data' => $chartData,

            'summary' => [
                'logins_today' => $loginsToday,
                'logins_yesterday' => $loginsYesterday,
                'logins_this_week' => $loginsThisWeek,
                'logins_this_month' => $loginsThisMonth,
                'never_logged_in' => $neverLoggedIn,
                'total_users' => $totalUsers,
                'active_percentage' => $totalUsers > 0
                    ? round(($loginsThisWeek / $totalUsers) * 100, 1)
                    : 0
            ],

            'cards' => [
                [
                    'title' => 'Today',
                    'value' => $loginsToday,
                    'icon' => '📅',
                    'color' => 'blue'
                ],
                [
                    'title' => 'Yesterday',
                    'value' => $loginsYesterday,
                    'icon' => '🕐',
                    'color' => 'gray'
                ],
                [
                    'title' => 'This Week',
                    'value' => $loginsThisWeek,
                    'icon' => '📊',
                    'color' => 'green'
                ],
                [
                    'title' => 'This Month',
                    'value' => $loginsThisMonth,
                    'icon' => '📈',
                    'color' => 'purple'
                ],
                [
                    'title' => 'Never Logged In',
                    'value' => $neverLoggedIn,
                    'icon' => '⚠️',
                    'color' => 'red'
                ]
            ]
        ];

        return successResponse('User login activity retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch user login activity', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch user login activity', $e->getMessage());
    }
}
/**
 * Function 6: Inactive Users
 * Lists users who haven't logged in recently
 */
public function getInactiveUsers(Request $request)
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

        // Get filters
        $daysInactive = $request->input('days_inactive', 30); // Default 30 days
        $perPage = $request->input('per_page', 20);
        $sortBy = $request->input('sort_by', 'last_login_at'); // or 'name', 'created_at'

        // Calculate cutoff date
        $cutoffDate = now()->subDays($daysInactive);

        // ==========================================
        // GET INACTIVE USERS
        // ==========================================
        $query = User::where('primary_branch_id', $userBranchId)
            ->where(function($q) use ($cutoffDate) {
                $q->whereNull('last_login_at')
                  ->orWhere('last_login_at', '<', $cutoffDate);
            })
            ->with(['role:id,name']);

        // Apply sorting
        if ($sortBy === 'name') {
            $query->orderBy('name', 'asc');
        } elseif ($sortBy === 'created_at') {
            $query->orderBy('created_at', 'desc');
        } else {
            $query->orderBy('last_login_at', 'asc');
        }

        $inactiveUsers = $query->paginate($perPage);

        // Transform data
        $userData = $inactiveUsers->getCollection()->map(function($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_id' => $user->employee_id,
                'role' => $user->role ? $user->role->name : 'N/A',
                'last_login' => $user->last_login_at
                    ? $user->last_login_at->format('Y-m-d H:i:s')
                    : 'Never logged in',
                'days_inactive' => $user->last_login_at
                    ? now()->diffInDays($user->last_login_at)
                    : now()->diffInDays($user->created_at),
                'is_active' => $user->is_active,
                'created_at' => $user->created_at->format('Y-m-d')
            ];
        });

        $data = [
            'users' => $userData,
            'pagination' => [
                'current_page' => $inactiveUsers->currentPage(),
                'per_page' => $inactiveUsers->perPage(),
                'total' => $inactiveUsers->total(),
                'last_page' => $inactiveUsers->lastPage()
            ],
            'summary' => [
                'total_inactive' => $inactiveUsers->total(),
                'days_threshold' => $daysInactive
            ]
        ];

        return successResponse('Inactive users retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch inactive users', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch inactive users', $e->getMessage());
    }
}

/**
 * Function 7: Recent Registrations
 * Shows recently registered users
 */
public function getRecentRegistrations(Request $request)
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

        // Get filters
        $daysBack = $request->input('days_back', 7); // Default last 7 days
        $perPage = $request->input('per_page', 20);

        $startDate = now()->subDays($daysBack)->startOfDay();

        // ==========================================
        // GET RECENT REGISTRATIONS
        // ==========================================
        $recentUsers = User::where('primary_branch_id', $userBranchId)
            ->where('created_at', '>=', $startDate)
            ->with(['role:id,name'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Transform data
        $userData = $recentUsers->getCollection()->map(function($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'employee_id' => $user->employee_id,
                'role' => $user->role ? $user->role->name : 'N/A',
                'is_active' => $user->is_active,
                'has_logged_in' => $user->last_login_at ? true : false,
                'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                'created_ago' => $user->created_at->diffForHumans()
            ];
        });

        // Summary stats
        $totalNew = $recentUsers->total();
        $activeNew = $recentUsers->filter(fn($u) => $u->is_active)->count();
        $loggedIn = $recentUsers->filter(fn($u) => $u->last_login_at !== null)->count();

        $data = [
            'users' => $userData,
            'pagination' => [
                'current_page' => $recentUsers->currentPage(),
                'per_page' => $recentUsers->perPage(),
                'total' => $recentUsers->total(),
                'last_page' => $recentUsers->lastPage()
            ],
            'summary' => [
                'total_new_users' => $totalNew,
                'active_users' => $activeNew,
                'logged_in_count' => $loggedIn,
                'period' => "Last {$daysBack} days"
            ]
        ];

        return successResponse('Recent registrations retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch recent registrations', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch recent registrations', $e->getMessage());
    }
}
/**
 * Function 8: Business Summary
 * Shows key business and branch metrics
 */
public function getBusinessSummary(Request $request)
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
        // GET BUSINESS INFO
        // ==========================================
        $business = Business::find($businessId);

        // ==========================================
        // BRANCH STATISTICS
        // ==========================================
        $totalBranches = Branch::where('business_id', $businessId)->count();
        $activeBranches = Branch::where('business_id', $businessId)
            ->where('is_active', true)
            ->count();
        $mainBranch = Branch::where('business_id', $businessId)
            ->where('is_main_branch', true)
            ->first();

        // ==========================================
        // USER DISTRIBUTION
        // ==========================================
        $totalUsersInBusiness = User::whereHas('primaryBranch', function($q) use ($businessId) {
            $q->where('business_id', $businessId);
        })->count();

        $activeUsersInBusiness = User::whereHas('primaryBranch', function($q) use ($businessId) {
            $q->where('business_id', $businessId);
        })->where('is_active', true)->count();

        // ==========================================
        // FIND LARGEST BRANCH (by user count)
        // ==========================================
        $branches = Branch::where('business_id', $businessId)
            ->withCount('primaryUsers')
            ->get();

        $largestBranch = $branches->sortByDesc('primary_users_count')->first();
        $newestBranch = Branch::where('business_id', $businessId)
            ->orderBy('created_at', 'desc')
            ->first();

        // ==========================================
        // DISTRIBUTION SCORE
        // Calculate how evenly users are distributed across branches
        // ==========================================
        $userCounts = $branches->pluck('primary_users_count')->filter()->toArray();
        $averageUsers = count($userCounts) > 0 ? array_sum($userCounts) / count($userCounts) : 0;

        // Calculate standard deviation
        $variance = 0;
        if (count($userCounts) > 0 && $averageUsers > 0) {
            foreach ($userCounts as $count) {
                $variance += pow($count - $averageUsers, 2);
            }
            $variance = $variance / count($userCounts);
            $stdDev = sqrt($variance);

            // Distribution score (0-100, higher = more even distribution)
            $distributionScore = $averageUsers > 0
                ? max(0, 100 - (($stdDev / $averageUsers) * 100))
                : 0;
        } else {
            $distributionScore = 0;
        }

        // ==========================================
        // RESPONSE
        // ==========================================
        $data = [
            'cards' => [
                [
                    'title' => 'Your Business',
                    'value' => $business->name,
                    'subtitle' => 'Status: ' . ucfirst($business->status),
                    'icon' => '🏢',
                    'color' => $business->status === 'active' ? 'green' : 'orange'
                ],
                [
                    'title' => 'Total Branches',
                    'value' => $totalBranches,
                    'subtitle' => "{$activeBranches} active",
                    'icon' => '🏪',
                    'color' => 'blue'
                ],
                [
                    'title' => 'Total Users',
                    'value' => $totalUsersInBusiness,
                    'subtitle' => "{$activeUsersInBusiness} active",
                    'icon' => '👥',
                    'color' => 'purple'
                ],
                [
                    'title' => 'Largest Branch',
                    'value' => $largestBranch ? $largestBranch->name : 'N/A',
                    'subtitle' => $largestBranch ? "{$largestBranch->primary_users_count} users" : 'No data',
                    'icon' => '🏆',
                    'color' => 'yellow'
                ],
                [
                    'title' => 'Main Branch',
                    'value' => $mainBranch ? $mainBranch->name : 'N/A',
                    'subtitle' => $mainBranch ? $mainBranch->code : 'Not set',
                    'icon' => '⭐',
                    'color' => 'indigo'
                ],
                [
                    'title' => 'Distribution Score',
                    'value' => round($distributionScore, 1),
                    'subtitle' => $distributionScore > 70 ? 'Well balanced' : 'Uneven distribution',
                    'icon' => '📊',
                    'color' => $distributionScore > 70 ? 'green' : 'orange'
                ]
            ],

            'business_info' => [
                'id' => $business->id,
                'name' => $business->name,
                'email' => $business->email,
                'phone' => $business->phone,
                'status' => $business->status,
                'created_at' => $business->created_at->format('Y-m-d')
            ],

            'summary' => [
                'total_branches' => $totalBranches,
                'active_branches' => $activeBranches,
                'total_users' => $totalUsersInBusiness,
                'active_users' => $activeUsersInBusiness,
                'average_users_per_branch' => $totalBranches > 0
                    ? round($totalUsersInBusiness / $totalBranches, 1)
                    : 0,
                'distribution_score' => round($distributionScore, 1)
            ]
        ];

        return successResponse('Business summary retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch business summary', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch business summary', $e->getMessage());
    }
}

/**
 * Function 9: Branch Distribution
 * Shows user distribution across branches (donut chart data)
 */
public function getBranchDistribution(Request $request)
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
        // GET BRANCH DISTRIBUTION
        // ==========================================
        $branches = Branch::where('business_id', $businessId)
            ->withCount([
                'primaryUsers',
                'primaryUsers as active_users_count' => function($q) {
                    $q->where('is_active', true);
                }
            ])
            ->get();

        $totalUsers = $branches->sum('primary_users_count');

        // Define colors for donut chart
        $colors = ['#3B82F6', '#8B5CF6', '#10B981', '#F59E0B', '#EF4444', '#06B6D4', '#EC4899', '#14B8A6'];

        $chartData = $branches->map(function($branch, $index) use ($totalUsers, $colors, $userBranchId) {
            $percentage = $totalUsers > 0
                ? round(($branch->primary_users_count / $totalUsers) * 100, 1)
                : 0;

            return [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'branch_code' => $branch->code,
                'is_main' => $branch->is_main_branch,
                'is_current' => $branch->id === $userBranchId,
                'total_users' => $branch->primary_users_count,
                'active_users' => $branch->active_users_count,
                'percentage' => $percentage,
                'color' => $colors[$index % count($colors)],
                'label' => $branch->name . ' (' . $percentage . '%)'
            ];
        })
        ->sortByDesc('total_users')
        ->values();

        // ==========================================
        // SUMMARY
        // ==========================================
        $data = [
            'chart_data' => $chartData,

            'summary' => [
                'total_branches' => $branches->count(),
                'total_users' => $totalUsers,
                'largest_branch' => $chartData->first() ? [
                    'name' => $chartData->first()['branch_name'],
                    'users' => $chartData->first()['total_users'],
                    'percentage' => $chartData->first()['percentage']
                ] : null,
                'smallest_branch' => $chartData->last() ? [
                    'name' => $chartData->last()['branch_name'],
                    'users' => $chartData->last()['total_users'],
                    'percentage' => $chartData->last()['percentage']
                ] : null
            ]
        ];

        return successResponse('Branch distribution retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch branch distribution', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch branch distribution', $e->getMessage());
    }
}

/**
 * Function 10: Business Performance Table
 * Shows performance metrics for each branch
 */
public function getBusinessPerformance(Request $request)
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

        // Get filters
        $perPage = $request->input('per_page', 20);
        $sortBy = $request->input('sort_by', 'total_users'); // 'name', 'total_users', 'created_at'

        // ==========================================
        // GET BRANCH PERFORMANCE DATA
        // ==========================================
        $query = Branch::where('business_id', $businessId)
            ->withCount([
                'primaryUsers as total_users',
                'primaryUsers as active_users' => function($q) {
                    $q->where('is_active', true);
                }
            ]);

        // Apply sorting
        if ($sortBy === 'name') {
            $query->orderBy('name', 'asc');
        } elseif ($sortBy === 'created_at') {
            $query->orderBy('created_at', 'desc');
        } else {
            $query->orderBy('total_users', 'desc');
        }

        $branches = $query->paginate($perPage);

        // Transform data
        $branchData = $branches->getCollection()->map(function($branch) use ($userBranchId) {
            $activityRate = $branch->total_users > 0
                ? round(($branch->active_users / $branch->total_users) * 100, 1)
                : 0;

            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'is_main' => $branch->is_main_branch,
                'is_current' => $branch->id === $userBranchId,
                'is_active' => $branch->is_active,
                'total_users' => $branch->total_users,
                'active_users' => $branch->active_users,
                'inactive_users' => $branch->total_users - $branch->active_users,
                'activity_rate' => $activityRate,
                'status_badge' => $branch->is_active ? 'Active' : 'Inactive',
                'status_color' => $branch->is_active ? 'green' : 'gray',
                'created_at' => $branch->created_at->format('Y-m-d')
            ];
        });

        // ==========================================
        // RESPONSE
        // ==========================================
        $data = [
            'branches' => $branchData,

            'pagination' => [
                'current_page' => $branches->currentPage(),
                'per_page' => $branches->perPage(),
                'total' => $branches->total(),
                'last_page' => $branches->lastPage()
            ],

            'summary' => [
                'total_branches' => $branches->total(),
                'total_users' => $branchData->sum('total_users'),
                'average_users_per_branch' => $branches->total() > 0
                    ? round($branchData->sum('total_users') / $branches->total(), 1)
                    : 0
            ]
        ];

        return successResponse('Business performance retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch business performance', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch business performance', $e->getMessage());
    }
}

/**
 * Function 11: Branch Details Table
 * Shows detailed information for each branch
 */
public function getBranchDetails(Request $request)
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

        // Get filters
        $search = $request->input('search');
        $isActive = $request->input('is_active');
        $perPage = $request->input('per_page', 20);

        // ==========================================
        // BUILD QUERY
        // ==========================================
        $query = Branch::where('business_id', $businessId)
            ->withCount('primaryUsers');

        // Apply search filter
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%");
            });
        }

        // Apply status filter
        if (isset($isActive)) {
            $query->where('is_active', (bool) $isActive);
        }

        $branches = $query->orderBy('is_main_branch', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Transform data
        $branchData = $branches->getCollection()->map(function($branch) use ($userBranchId) {
            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'is_main_branch' => $branch->is_main_branch,
                'is_current_branch' => $branch->id === $userBranchId,
                'is_active' => $branch->is_active,
                'user_count' => $branch->primary_users_count,
                'status_badge' => $branch->is_active ? 'Active' : 'Inactive',
                'status_color' => $branch->is_active ? 'green' : 'gray',
                'branch_type' => $branch->is_main_branch ? 'Main Branch' : 'Branch',
                'created_at' => $branch->created_at->format('Y-m-d H:i:s'),
                'created_ago' => $branch->created_at->diffForHumans()
            ];
        });

        // ==========================================
        // RESPONSE
        // ==========================================
        $data = [
            'branches' => $branchData,

            'pagination' => [
                'current_page' => $branches->currentPage(),
                'per_page' => $branches->perPage(),
                'total' => $branches->total(),
                'last_page' => $branches->lastPage()
            ],

            'summary' => [
                'total_branches' => $branches->total(),
                'showing' => $branchData->count(),
                'filters_applied' => [
                    'search' => $search,
                    'is_active' => $isActive
                ]
            ]
        ];

        return successResponse('Branch details retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch branch details', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch branch details', $e->getMessage());
    }
}
/**
 * Function 12: Security Summary
 * Shows key security metrics in cards
 */
public function getSecuritySummary(Request $request)
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

        // Get date range filter
        $dateRange = $request->input('date_range', 7); // Default last 7 days

        // ==========================================
        // CARD 1: TOTAL ROLES
        // ==========================================
        $totalRoles = Role::count();

        // Count users per role in this branch
        $usersPerRole = User::where('primary_branch_id', $userBranchId)
            ->selectRaw('role_id, COUNT(*) as user_count')
            ->groupBy('role_id')
            ->get();

        $averageUsersPerRole = $usersPerRole->count() > 0
            ? round($usersPerRole->avg('user_count'), 1)
            : 0;

        // ==========================================
        // CARD 2: PIN LOCKED USERS
        // ==========================================
        $pinLockedUsers = User::where('primary_branch_id', $userBranchId)
            ->whereNotNull('pin_locked_until')
            ->where('pin_locked_until', '>', now())
            ->count();

        // Locked in last 24 hours
        $lockedLast24h = User::where('primary_branch_id', $userBranchId)
            ->whereNotNull('pin_locked_until')
            ->where('pin_locked_until', '>', now()->subHours(24))
            ->count();

        // ==========================================
        // CARD 3: FAILED LOGIN ATTEMPTS (Last 7 days)
        // ==========================================
        $failedAttempts = User::where('primary_branch_id', $userBranchId)
            ->where('failed_pin_attempts', '>', 0)
            ->sum('failed_pin_attempts');

        $usersWithFailedAttempts = User::where('primary_branch_id', $userBranchId)
            ->where('failed_pin_attempts', '>', 0)
            ->count();

        // ==========================================
        // CARD 4: PASSWORD RESETS (This month)
        // Note: You may need a password_resets table to track this accurately
        // For now, we'll use a placeholder
        // ==========================================
        $passwordResetsThisMonth = 0; // Placeholder - implement if you have password_resets table

        // ==========================================
        // CARD 5: DEACTIVATED USERS (Today)
        // ==========================================
        $deactivatedToday = User::where('primary_branch_id', $userBranchId)
            ->where('is_active', false)
            ->whereDate('updated_at', today())
            ->count();

        // Total inactive users
        $totalInactive = User::where('primary_branch_id', $userBranchId)
            ->where('is_active', false)
            ->count();

        // ==========================================
        // CARD 6: PERMISSION COVERAGE
        // Calculate what % of roles have permissions assigned
        // ==========================================
        $rolesWithPermissions = DB::table('role_permission')
            ->distinct('role_id')
            ->count('role_id');

        $permissionCoverage = $totalRoles > 0
            ? round(($rolesWithPermissions / $totalRoles) * 100, 1)
            : 0;

        // ==========================================
        // SECURITY HEALTH SCORE
        // ==========================================
        // Lower locked accounts = better (max 30 points)
        $lockedScore = max(0, 30 - ($pinLockedUsers * 5));

        // Lower failed attempts = better (max 30 points)
        $failedScore = max(0, 30 - ($usersWithFailedAttempts * 2));

        // Higher permission coverage = better (max 40 points)
        $permissionScore = ($permissionCoverage / 100) * 40;

        $securityScore = round($lockedScore + $failedScore + $permissionScore, 1);

        // ==========================================
        // RESPONSE
        // ==========================================
        $data = [
            'cards' => [
                [
                    'title' => 'Total Roles',
                    'value' => $totalRoles,
                    'subtitle' => "Avg {$averageUsersPerRole} users per role",
                    'icon' => '👔',
                    'color' => 'blue'
                ],
                [
                    'title' => 'PIN Locked Users',
                    'value' => $pinLockedUsers,
                    'subtitle' => "{$lockedLast24h} locked in last 24h",
                    'icon' => '🔒',
                    'color' => $pinLockedUsers > 0 ? 'red' : 'green'
                ],
                [
                    'title' => 'Failed Attempts',
                    'value' => $failedAttempts,
                    'subtitle' => "{$usersWithFailedAttempts} users affected",
                    'icon' => '⚠️',
                    'color' => $failedAttempts > 10 ? 'red' : 'orange'
                ],
                [
                    'title' => 'Password Resets',
                    'value' => $passwordResetsThisMonth,
                    'subtitle' => 'This month',
                    'icon' => '🔑',
                    'color' => 'purple'
                ],
                [
                    'title' => 'Deactivated Today',
                    'value' => $deactivatedToday,
                    'subtitle' => "{$totalInactive} total inactive",
                    'icon' => '🚫',
                    'color' => 'gray'
                ],
                [
                    'title' => 'Permission Coverage',
                    'value' => $permissionCoverage,
                    'value_formatted' => $permissionCoverage . '%',
                    'subtitle' => "{$rolesWithPermissions}/{$totalRoles} roles configured",
                    'icon' => '✅',
                    'color' => $permissionCoverage > 80 ? 'green' : 'orange'
                ]
            ],

            'security_score' => [
                'score' => $securityScore,
                'max_score' => 100,
                'status' => $securityScore > 70 ? 'Good' : ($securityScore > 50 ? 'Fair' : 'Needs Attention'),
                'color' => $securityScore > 70 ? 'green' : ($securityScore > 50 ? 'orange' : 'red')
            ],

            'summary' => [
                'total_roles' => $totalRoles,
                'locked_users' => $pinLockedUsers,
                'failed_attempts' => $failedAttempts,
                'inactive_users' => $totalInactive,
                'permission_coverage' => $permissionCoverage
            ]
        ];

        return successResponse('Security summary retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch security summary', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch security summary', $e->getMessage());
    }
}

/**
 * Function 13: Locked Users
 * Shows currently locked user accounts with unlock capability
 */
public function getLockedUsers(Request $request)
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

        // Get pagination
        $perPage = $request->input('per_page', 20);

        // ==========================================
        // GET LOCKED USERS
        // ==========================================
        $lockedUsers = User::where('primary_branch_id', $userBranchId)
            ->whereNotNull('pin_locked_until')
            ->where('pin_locked_until', '>', now())
            ->with(['role:id,name'])
            ->orderBy('pin_locked_until', 'desc')
            ->paginate($perPage);

        // Transform data
        $userData = $lockedUsers->getCollection()->map(function($user) {
            $lockedMinutesRemaining = now()->diffInMinutes($user->pin_locked_until);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_id' => $user->employee_id,
                'role' => $user->role ? $user->role->name : 'N/A',
                'failed_attempts' => $user->failed_pin_attempts,
                'locked_until' => $user->pin_locked_until->format('Y-m-d H:i:s'),
                'locked_until_human' => $user->pin_locked_until->diffForHumans(),
                'minutes_remaining' => $lockedMinutesRemaining,
                'is_active' => $user->is_active,
                'can_unlock' => true // Admin can always unlock
            ];
        });

        // ==========================================
        // RESPONSE
        // ==========================================
        $data = [
            'users' => $userData,

            'pagination' => [
                'current_page' => $lockedUsers->currentPage(),
                'per_page' => $lockedUsers->perPage(),
                'total' => $lockedUsers->total(),
                'last_page' => $lockedUsers->lastPage()
            ],

            'summary' => [
                'total_locked' => $lockedUsers->total(),
                'showing' => $userData->count()
            ],

            'actions' => [
                'unlock_endpoint' => '/api/users/{id}/unlock-pin',
                'note' => 'Use POST request to unlock a user'
            ]
        ];

        return successResponse('Locked users retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch locked users', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch locked users', $e->getMessage());
    }
}

/**
 * Function 14: Failed Login Attempts
 * Shows users with failed PIN attempts
 */
public function getFailedLoginAttempts(Request $request)
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

        // Get filters
        $dateRange = $request->input('date_range', 7); // Last 7 days
        $perPage = $request->input('per_page', 20);

        // ==========================================
        // GET USERS WITH FAILED ATTEMPTS
        // ==========================================
        $usersWithFailures = User::where('primary_branch_id', $userBranchId)
            ->where('failed_pin_attempts', '>', 0)
            ->with(['role:id,name'])
            ->orderBy('failed_pin_attempts', 'desc')
            ->paginate($perPage);

        // Transform data
        $userData = $usersWithFailures->getCollection()->map(function($user) {
            $isLocked = $user->pin_locked_until && $user->pin_locked_until > now();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_id' => $user->employee_id,
                'role' => $user->role ? $user->role->name : 'N/A',
                'failed_attempts' => $user->failed_pin_attempts,
                'is_locked' => $isLocked,
                'locked_until' => $isLocked
                    ? $user->pin_locked_until->format('Y-m-d H:i:s')
                    : null,
                'last_login' => $user->last_login_at
                    ? $user->last_login_at->format('Y-m-d H:i:s')
                    : 'Never',
                'is_active' => $user->is_active,
                'risk_level' => $user->failed_pin_attempts > 3 ? 'high' : 'medium'
            ];
        });

        // ==========================================
        // RESPONSE
        // ==========================================
        $data = [
            'users' => $userData,

            'pagination' => [
                'current_page' => $usersWithFailures->currentPage(),
                'per_page' => $usersWithFailures->perPage(),
                'total' => $usersWithFailures->total(),
                'last_page' => $usersWithFailures->lastPage()
            ],

            'summary' => [
                'total_users_with_failures' => $usersWithFailures->total(),
                'total_failed_attempts' => $userData->sum('failed_attempts'),
                'high_risk_users' => $userData->where('risk_level', 'high')->count()
            ]
        ];

        return successResponse('Failed login attempts retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch failed login attempts', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch failed login attempts', $e->getMessage());
    }
}

/**
 * Function 15: Role Distribution
 * Shows user distribution by role with permissions
 */
public function getRoleDistribution(Request $request)
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
        // GET ROLE DISTRIBUTION
        // ==========================================
        $roleDistribution = User::where('primary_branch_id', $userBranchId)
            ->join('roles', 'users.role_id', '=', 'roles.id')
            ->select([
                'roles.id as role_id',
                'roles.name as role_name',
                DB::raw('COUNT(users.id) as user_count'),
                DB::raw('SUM(CASE WHEN users.is_active = 1 THEN 1 ELSE 0 END) as active_count')
            ])
            ->groupBy('roles.id', 'roles.name')
            ->orderByDesc('user_count')
            ->get();

        // Get permission counts for each role
        $rolePermissions = DB::table('role_permission')
            ->select('role_id', DB::raw('COUNT(*) as permission_count'))
            ->groupBy('role_id')
            ->pluck('permission_count', 'role_id');

        $totalUsers = $roleDistribution->sum('user_count');

        // Define colors
        $colors = ['#3B82F6', '#8B5CF6', '#10B981', '#F59E0B', '#EF4444', '#06B6D4'];

        $chartData = $roleDistribution->map(function($role, $index) use ($totalUsers, $rolePermissions, $colors) {
            $percentage = $totalUsers > 0
                ? round(($role->user_count / $totalUsers) * 100, 1)
                : 0;

            $permissionCount = $rolePermissions[$role->role_id] ?? 0;

            return [
                'role_id' => $role->role_id,
                'role_name' => $role->role_name,
                'user_count' => $role->user_count,
                'active_users' => $role->active_count,
                'inactive_users' => $role->user_count - $role->active_count,
                'permission_count' => $permissionCount,
                'percentage' => $percentage,
                'color' => $colors[$index % count($colors)],
                'label' => $role->role_name . ' (' . $percentage . '%)'
            ];
        });

        // ==========================================
        // RESPONSE
        // ==========================================
        $data = [
            'chart_data' => $chartData,

            'summary' => [
                'total_roles' => $roleDistribution->count(),
                'total_users' => $totalUsers,
                'most_common_role' => $chartData->first() ? [
                    'name' => $chartData->first()['role_name'],
                    'user_count' => $chartData->first()['user_count'],
                    'percentage' => $chartData->first()['percentage']
                ] : null,
                'least_common_role' => $chartData->last() ? [
                    'name' => $chartData->last()['role_name'],
                    'user_count' => $chartData->last()['user_count'],
                    'percentage' => $chartData->last()['percentage']
                ] : null
            ]
        ];

        return successResponse('Role distribution retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch role distribution', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch role distribution', $e->getMessage());
    }
}

/**
 * Function 16: Security Events
 * Shows recent security events (lockouts, deactivations, etc.)
 */
public function getSecurityEvents(Request $request)
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

        // Get filters
        $dateRange = $request->input('date_range', 30); // Last 30 days
        $eventType = $request->input('event_type'); // 'lockout', 'deactivation', 'reactivation'
        $perPage = $request->input('per_page', 20);

        $startDate = now()->subDays($dateRange);

        // ==========================================
        // BUILD SECURITY EVENTS
        // ==========================================
        $events = collect();

        // Event 1: Users who got locked
        $lockedUsers = User::where('primary_branch_id', $userBranchId)
            ->whereNotNull('pin_locked_until')
            ->where('updated_at', '>=', $startDate)
            ->get(['id', 'name', 'email', 'pin_locked_until', 'failed_pin_attempts', 'updated_at']);

        foreach ($lockedUsers as $lockedUser) {
            $events->push([
                'event_type' => 'lockout',
                'event_label' => 'Account Locked',
                'user_id' => $lockedUser->id,
                'user_name' => $lockedUser->name,
                'user_email' => $lockedUser->email,
                'details' => "Locked due to {$lockedUser->failed_pin_attempts} failed attempts",
                'timestamp' => $lockedUser->updated_at,
                'icon' => '🔒',
                'color' => 'red'
            ]);
        }

        // Event 2: Users who got deactivated
        $deactivatedUsers = User::where('primary_branch_id', $userBranchId)
            ->where('is_active', false)
            ->where('updated_at', '>=', $startDate)
            ->get(['id', 'name', 'email', 'updated_at']);

        foreach ($deactivatedUsers as $deactivated) {
            $events->push([
                'event_type' => 'deactivation',
                'event_label' => 'Account Deactivated',
                'user_id' => $deactivated->id,
                'user_name' => $deactivated->name,
                'user_email' => $deactivated->email,
                'details' => 'Account deactivated',
                'timestamp' => $deactivated->updated_at,
                'icon' => '🚫',
                'color' => 'orange'
            ]);
        }

        // Event 3: Users with failed attempts
        $usersWithFailures = User::where('primary_branch_id', $userBranchId)
            ->where('failed_pin_attempts', '>', 0)
            ->where('updated_at', '>=', $startDate)
            ->get(['id', 'name', 'email', 'failed_pin_attempts', 'updated_at']);

        foreach ($usersWithFailures as $failure) {
            $events->push([
                'event_type' => 'failed_attempt',
                'event_label' => 'Failed Login Attempts',
                'user_id' => $failure->id,
                'user_name' => $failure->name,
                'user_email' => $failure->email,
                'details' => "{$failure->failed_pin_attempts} failed PIN attempts",
                'timestamp' => $failure->updated_at,
                'icon' => '⚠️',
                'color' => 'yellow'
            ]);
        }

        // Filter by event type if specified
        if ($eventType) {
            $events = $events->filter(function($event) use ($eventType) {
                return $event['event_type'] === $eventType;
            });
        }

        // Sort by timestamp (newest first)
        $events = $events->sortByDesc('timestamp')->values();

        // Paginate manually
        $total = $events->count();
        $currentPage = $request->input('page', 1);
        $offset = ($currentPage - 1) * $perPage;
        $paginatedEvents = $events->slice($offset, $perPage)->values();

        // Transform timestamps
        $eventsData = $paginatedEvents->map(function($event) {
            return array_merge($event, [
                'timestamp_formatted' => $event['timestamp']->format('Y-m-d H:i:s'),
                'timestamp_human' => $event['timestamp']->diffForHumans()
            ]);
        });

        // ==========================================
        // RESPONSE
        // ==========================================
        $data = [
            'events' => $eventsData,

            'pagination' => [
                'current_page' => $currentPage,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage)
            ],

            'summary' => [
                'total_events' => $total,
                'lockouts' => $events->where('event_type', 'lockout')->count(),
                'deactivations' => $events->where('event_type', 'deactivation')->count(),
                'failed_attempts' => $events->where('event_type', 'failed_attempt')->count(),
                'period' => "Last {$dateRange} days"
            ]
        ];

        return successResponse('Security events retrieved successfully', $data);

    } catch (\Exception $e) {
        Log::error('Failed to fetch security events', [
            'user_id' => $request->user()->id,
            'error' => $e->getMessage()
        ]);
        return queryErrorResponse('Failed to fetch security events', $e->getMessage());
    }
}
}