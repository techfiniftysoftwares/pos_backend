<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    /**
     * Get all suppliers with optional filters
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $search = $request->input('search');
            $isActive = $request->input('is_active');
            $businessId = $request->user()->business_id;

            $query = Supplier::forBusiness($businessId);

            // Search filter
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('contact_person', 'like', "%{$search}%");
                });
            }

            // Active status filter
            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            $suppliers = $query->orderBy('name', 'asc')
                ->paginate($perPage);

            // Transform the data
            $suppliers->getCollection()->transform(function ($supplier) {
                return $this->transformSupplier($supplier);
            });

            return successResponse('Suppliers retrieved successfully', $suppliers);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve suppliers', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return queryErrorResponse('Failed to retrieve suppliers', $e->getMessage());
        }
    }

    /**
     * Create new supplier
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string|max:100',
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;

            // Check for duplicate supplier name
            $exists = Supplier::forBusiness($businessId)
                ->where('name', $request->name)
                ->exists();

            if ($exists) {
                return errorResponse('Supplier with this name already exists', 422);
            }

            // Check for duplicate email (if provided)
            if ($request->email) {
                $emailExists = Supplier::forBusiness($businessId)
                    ->where('email', $request->email)
                    ->exists();

                if ($emailExists) {
                    return errorResponse('Supplier with this email already exists', 422);
                }
            }

            DB::beginTransaction();

            $supplier = Supplier::create([
                'business_id' => $businessId,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'contact_person' => $request->contact_person,
                'tax_number' => $request->tax_number,
                'payment_terms' => $request->payment_terms,
                'credit_limit' => $request->credit_limit,
                'is_active' => $request->input('is_active', true),
            ]);

            DB::commit();

            return successResponse(
                'Supplier created successfully',
                $this->transformSupplier($supplier),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create supplier', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return serverErrorResponse('Failed to create supplier', $e->getMessage());
        }
    }

    /**
     * Get specific supplier
     */
    public function show(Supplier $supplier)
    {
        try {
            // Check business access
            if ($supplier->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this supplier', 403);
            }

            $supplier->load(['products']);

            $supplierData = $this->transformSupplier($supplier);

            // Add additional stats
            $supplierData['products_count'] = $supplier->products()->count();
            $supplierData['active_products_count'] = $supplier->products()->where('is_active', true)->count();

            // Add products list
            $supplierData['products'] = $supplier->products->map(function($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'cost_price' => (float) $product->cost_price,
                    'selling_price' => (float) $product->selling_price,
                    'is_active' => $product->is_active,
                ];
            });

            return successResponse('Supplier retrieved successfully', $supplierData);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve supplier', [
                'supplier_id' => $supplier->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve supplier', $e->getMessage());
        }
    }

    /**
     * Update supplier
     */
    public function update(Request $request, Supplier $supplier)
    {
        // Check business access
        if ($supplier->business_id !== $request->user()->business_id) {
            return errorResponse('Unauthorized access to this supplier', 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string|max:100',
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Check for duplicate name (excluding current supplier)
            if ($request->has('name')) {
                $exists = Supplier::forBusiness($supplier->business_id)
                    ->where('name', $request->name)
                    ->where('id', '!=', $supplier->id)
                    ->exists();

                if ($exists) {
                    return errorResponse('Supplier with this name already exists', 422);
                }
            }

            // Check for duplicate email (excluding current supplier)
            if ($request->has('email') && $request->email) {
                $emailExists = Supplier::forBusiness($supplier->business_id)
                    ->where('email', $request->email)
                    ->where('id', '!=', $supplier->id)
                    ->exists();

                if ($emailExists) {
                    return errorResponse('Supplier with this email already exists', 422);
                }
            }

            DB::beginTransaction();

            $updateData = collect($request->only([
                'name',
                'email',
                'phone',
                'address',
                'contact_person',
                'tax_number',
                'payment_terms',
                'credit_limit',
                'is_active'
            ]))->filter(function ($value, $key) {
                return !is_null($value) || in_array($key, ['email', 'phone', 'address', 'contact_person', 'tax_number', 'payment_terms', 'credit_limit']);
            })->toArray();

            $supplier->update($updateData);

            DB::commit();

            return updatedResponse(
                $this->transformSupplier($supplier),
                'Supplier updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update supplier', [
                'supplier_id' => $supplier->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to update supplier', $e->getMessage());
        }
    }

    /**
     * Delete supplier
     */
    public function destroy(Supplier $supplier)
    {
        try {
            // Check business access
            if ($supplier->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this supplier', 403);
            }

            DB::beginTransaction();

            // Check if supplier has products
            if ($supplier->products()->exists()) {
                return errorResponse(
                    'Cannot delete supplier with existing products. Please reassign products first.',
                    400
                );
            }

            // Check if supplier has purchase orders
            // Uncomment when Purchase model exists
            // if ($supplier->purchases()->exists()) {
            //     return errorResponse(
            //         'Cannot delete supplier with existing purchase orders.',
            //         400
            //     );
            // }

            $supplier->delete();

            DB::commit();

            return deleteResponse('Supplier deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete supplier', [
                'supplier_id' => $supplier->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete supplier', $e->getMessage());
        }
    }

    /**
     * Toggle supplier status
     */
    public function toggleStatus(Supplier $supplier)
    {
        try {
            // Check business access
            if ($supplier->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this supplier', 403);
            }

            DB::beginTransaction();

            $newStatus = !$supplier->is_active;
            $supplier->update(['is_active' => $newStatus]);

            DB::commit();

            $statusText = $newStatus ? 'activated' : 'deactivated';
            return successResponse(
                "Supplier {$statusText} successfully",
                $this->transformSupplier($supplier)
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to toggle supplier status', [
                'supplier_id' => $supplier->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to toggle supplier status', $e->getMessage());
        }
    }

    /**
     * Get supplier statistics
     */
    public function statistics(Supplier $supplier)
    {
        try {
            // Check business access
            if ($supplier->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this supplier', 403);
            }

            $stats = [
                'total_products' => $supplier->products()->count(),
                'active_products' => $supplier->products()->where('is_active', true)->count(),
                'inactive_products' => $supplier->products()->where('is_active', false)->count(),
                // Add more stats when Purchase model exists
                // 'total_purchases' => $supplier->purchases()->count(),
                // 'total_amount_purchased' => $supplier->purchases()->sum('total_amount'),
                // 'pending_payments' => $supplier->purchases()->where('payment_status', 'pending')->sum('balance'),
            ];

            return successResponse('Supplier statistics retrieved successfully', $stats);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve supplier statistics', [
                'supplier_id' => $supplier->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve supplier statistics', $e->getMessage());
        }
    }

    /**
     * Helper: Transform supplier data
     */
    private function transformSupplier($supplier)
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'address' => $supplier->address,
            'contact_person' => $supplier->contact_person,
            'tax_number' => $supplier->tax_number,
            'payment_terms' => $supplier->payment_terms,
            'credit_limit' => $supplier->credit_limit ? (float) $supplier->credit_limit : null,
            'is_active' => $supplier->is_active,
            'products_count' => $supplier->products_count ?? $supplier->products()->count(),
            'created_at' => $supplier->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $supplier->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
