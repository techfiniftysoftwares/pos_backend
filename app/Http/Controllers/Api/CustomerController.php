<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $search = $request->input('search');
            $customerType = $request->input('customer_type');
            $isActive = $request->input('is_active');
            $businessId = $request->input('business_id');

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');

            // Whitelist of allowed sortable columns
            $allowedSortColumns = [
                'customer_number',
                'name',
                'email',
                'phone',
                'customer_type',
                'credit_limit',
                'current_credit_balance',
                'is_active',
                'created_at',
                'updated_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'created_at';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            $query = Customer::query()->with(['business']);

            // Filter by business
            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            // Search filter
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('customer_number', 'like', "%{$search}%");
                });
            }

            // Customer type filter
            if ($customerType) {
                $query->where('customer_type', $customerType);
            }

            // Active status filter
            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            $customers = $query->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            // Add computed fields
            $customers->getCollection()->transform(function ($customer) {
                return $this->transformCustomer($customer);
            });

            return successResponse('Customers retrieved successfully', $customers);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve customers', $e->getMessage());
        }
    }

    /**
     * Store a newly created customer
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:customers,email',
            'phone' => 'nullable|string|max:20',
            'secondary_phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'customer_type' => 'sometimes|in:regular,vip,wholesale',
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $customer = Customer::create($validator->validated());

            DB::commit();

            return successResponse('Customer created successfully', $customer, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to create customer', $e->getMessage());
        }
    }

    /**
     * Display the specified customer
     */
    /**
     * Display the specified customer
     */
    public function show(Customer $customer)
    {
        try {
            $customer->load([
                'business',
                'creditTransactions' => function ($query) {
                    $query->latest()->limit(10);
                },
                'points' => function ($query) {
                    $query->latest()->limit(10);
                },
                'giftCards'
                // Removed 'segments' as it doesn't exist
            ]);

            $customerData = [
                'id' => $customer->id,
                'business_id' => $customer->business_id,
                'customer_number' => $customer->customer_number,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'secondary_phone' => $customer->secondary_phone,
                'address' => $customer->address,
                'city' => $customer->city,
                'country' => $customer->country,
                'customer_type' => $customer->customer_type,
                'credit_limit' => $customer->credit_limit,
                'current_credit_balance' => $customer->current_credit_balance,
                'available_credit' => $customer->credit_limit - $customer->current_credit_balance,
                'is_active' => $customer->is_active,
                'notes' => $customer->notes,
                'created_at' => $customer->created_at->format('Y-m-d H:i:s'),
                'business' => $customer->business ? $customer->business->only(['id', 'name']) : null,
                'points_balance' => $customer->getCurrentPointsBalance(),
                'recent_credit_transactions' => $customer->creditTransactions->map(function ($transaction) {
                    return [
                        'id' => $transaction->id,
                        'type' => $transaction->transaction_type,
                        'amount' => $transaction->amount,
                        'balance' => $transaction->new_balance,
                        'reference' => $transaction->reference_number,
                        'date' => $transaction->created_at->format('Y-m-d H:i:s')
                    ];
                }),
                'recent_points' => $customer->points->map(function ($point) {
                    return [
                        'id' => $point->id,
                        'type' => $point->transaction_type,
                        'points' => $point->points,
                        'balance' => $point->new_balance,
                        'date' => $point->created_at->format('Y-m-d H:i:s')
                    ];
                }),
                'gift_cards_count' => $customer->giftCards->count(),
                'active_gift_cards' => $customer->giftCards->where('status', 'active')->count(),
                // Removed segments data since relationship doesn't exist
            ];

            return successResponse('Customer retrieved successfully', $customerData);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve customer', $e->getMessage());
        }
    }

    /**
     * Update the specified customer
     */
    public function update(Request $request, Customer $customer)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|unique:customers,email,' . $customer->id,
            'phone' => 'sometimes|nullable|string|max:20',
            'secondary_phone' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string',
            'city' => 'sometimes|nullable|string|max:100',
            'country' => 'sometimes|nullable|string|max:100',
            'customer_type' => 'sometimes|in:regular,vip,wholesale',
            'credit_limit' => 'sometimes|nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $customer->update($validator->validated());

            DB::commit();

            return updatedResponse($customer, 'Customer updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to update customer', $e->getMessage());
        }
    }

    /**
     * Remove the specified customer
     */
    public function destroy(Customer $customer)
    {
        try {
            DB::beginTransaction();

            // Check if customer has outstanding credit balance
            if ($customer->current_credit_balance > 0) {
                return errorResponse('Cannot delete customer with outstanding credit balance', 400);
            }

            // Check if customer has active gift cards
            if ($customer->giftCards()->where('status', 'active')->exists()) {
                return errorResponse('Cannot delete customer with active gift cards', 400);
            }

            $customer->delete();

            DB::commit();

            return deleteResponse('Customer deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to delete customer', $e->getMessage());
        }
    }

    /**
     * Toggle customer status
     */
    public function toggleStatus(Customer $customer)
    {
        try {
            $newStatus = !$customer->is_active;
            $customer->update(['is_active' => $newStatus]);

            $statusText = $newStatus ? 'activated' : 'deactivated';
            return successResponse("Customer {$statusText} successfully", $customer);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to toggle customer status', $e->getMessage());
        }
    }

    /**
     * Get customer statistics
     */
    public function statistics(Request $request)
    {
        try {
            $businessId = $request->input('business_id');

            $query = Customer::query();

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            $stats = [
                'total_customers' => $query->count(),
                'active_customers' => (clone $query)->where('is_active', true)->count(),
                'inactive_customers' => (clone $query)->where('is_active', false)->count(),
                'vip_customers' => (clone $query)->where('customer_type', 'vip')->count(),
                'wholesale_customers' => (clone $query)->where('customer_type', 'wholesale')->count(),
                'customers_with_credit' => (clone $query)->where('current_credit_balance', '>', 0)->count(),
                'total_credit_outstanding' => (clone $query)->sum('current_credit_balance'),
                'average_credit_balance' => (clone $query)->where('current_credit_balance', '>', 0)->avg('current_credit_balance'),
            ];

            return successResponse('Customer statistics retrieved successfully', $stats);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve customer statistics', $e->getMessage());
        }
    }

    /**
     * Search customers (lightweight for dropdowns)
     */
    public function search(Request $request)
    {
        try {
            $search = $request->input('search');
            $businessId = $request->input('business_id');
            $limit = $request->input('limit', 10);

            $query = Customer::query()
                ->select('id', 'customer_number', 'name', 'email', 'phone', 'customer_type')
                ->where('is_active', true);

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('customer_number', 'like', "%{$search}%");
                });
            }

            $customers = $query->limit($limit)->get();

            return successResponse('Customers found', $customers);
        } catch (\Exception $e) {
            return queryErrorResponse('Search failed', $e->getMessage());
        }
    }

    /**
     * Transform customer for listing
     */
    private function transformCustomer($customer)
    {
        return [
            'id' => $customer->id,
            'customer_number' => $customer->customer_number,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'customer_type' => $customer->customer_type,
            'credit_limit' => $customer->credit_limit,
            'current_credit_balance' => $customer->current_credit_balance,
            'available_credit' => $customer->credit_limit - $customer->current_credit_balance,
            'is_active' => $customer->is_active,
            'created_at' => $customer->created_at->format('Y-m-d H:i:s'),
            'business' => $customer->business ? $customer->business->only(['id', 'name']) : null,
        ];
    }
}
