<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to enforce branch-level access control.
 * 
 * This middleware ensures that users can only access branches that:
 * 1. Belong to their business (tenant isolation)
 * 2. They have been explicitly assigned to (branch access)
 */
class EnsureBranchAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Skip if no authenticated user
        if (!$user) {
            return $next($request);
        }

        // Get branch_id from request (query param, body, or route param)
        $branchId = $request->branch_id ?? $request->route('branch_id') ?? $request->route('branch');

        // Skip if no branch specified
        if (!$branchId) {
            return $next($request);
        }

        // If branchId is a model instance, get the ID
        if ($branchId instanceof Branch) {
            $branchId = $branchId->id;
        }

        // Find the branch
        $branch = Branch::find($branchId);

        // Check branch exists and belongs to user's business
        if (!$branch || $branch->business_id !== $user->business_id) {
            return response()->json([
                'success' => false,
                'message' => 'Branch not found',
                'error' => 'The specified branch does not exist or you do not have access to it.'
            ], 404);
        }

        // Check user has access to this branch
        if (!$user->canAccessBranch($branchId)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied',
                'error' => 'You do not have permission to access this branch.'
            ], 403);
        }

        return $next($request);
    }
}
