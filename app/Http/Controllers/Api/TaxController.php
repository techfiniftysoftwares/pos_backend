<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    public function index(Request $request)
    {
        $query = \App\Models\Tax::query();

        if ($request->has('active_only')) {
            $query->active();
        }

        // Filter by business (assumed from context or request, but typically user->business_id)
        // For now, return all or if multitenant logic exists, use it.
        // Assuming single business for now or handled by global scopes if they existed.
        // We will just return all for simplicity unless filtered.

        return successResponse('Taxes retrieved successfully', $query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'rate' => 'required|numeric|min:0',
            'is_active' => 'boolean',
            'business_id' => 'required|exists:businesses,id', // or optional if inferred
        ]);

        $tax = \App\Models\Tax::create($validated);

        return successResponse('Tax created successfully', $tax, 201);
    }

    public function update(Request $request, \App\Models\Tax $tax)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'rate' => 'sometimes|numeric|min:0',
            'is_active' => 'boolean',
            'business_id' => 'sometimes|exists:businesses,id',
        ]);

        $tax->update($validated);

        return successResponse('Tax updated successfully', $tax);
    }

    public function destroy(\App\Models\Tax $tax)
    {
        $tax->delete();
        return successResponse('Tax deleted successfully');
    }
}
