<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to enforce business-level data isolation (multi-tenancy).
 * 
 * This middleware ensures that all API requests are scoped to the
 * authenticated user's business. It overrides any business_id passed
 * in the request with the user's actual business_id.
 */
class EnsureBusinessScope
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Skip if no authenticated user (public routes)
        if (!$user) {
            return $next($request);
        }

        // Override any business_id in the request with the user's business
        // This prevents users from accessing data from other businesses
        if ($user->business_id) {
            $request->merge(['business_id' => $user->business_id]);
        }

        return $next($request);
    }
}
