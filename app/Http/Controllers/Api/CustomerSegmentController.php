<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerSegment;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class CustomerSegmentController extends Controller
{
    /**
     * Display all customer segments
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $businessId = $request->input('business_id');
            $isActive = $request->input('is_active');
            $search = $request->input('search');

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');

            // Whitelist of allowed sortable columns
            $allowedSortColumns = [
                'name',
                'customers_count',
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

            $query = CustomerSegment::query()
                ->with(['business'])
                ->withCount('customers');

            // Filter by business
            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            // Filter by active status
            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            // Search filter
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $segments = $query->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            return successResponse('Customer segments retrieved successfully', $segments);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve customer segments', $e->getMessage());
        }
    }

    /**
     * Create new segment
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'criteria' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $segment = CustomerSegment::create($validator->validated());

            // Auto-evaluate criteria if provided
            if ($request->criteria) {
                $matchingCustomers = $segment->evaluateCriteria();

                foreach ($matchingCustomers as $customer) {
                    $segment->assignCustomer($customer->id, Auth::id());
                }
            }

            DB::commit();

            $segment->load(['business']);
            $segment->loadCount('customers');

            return successResponse('Customer segment created successfully', $segment, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to create customer segment', $e->getMessage());
        }
    }

    /**
     * Display specific segment
     */
    public function show(CustomerSegment $customerSegment)
    {
        try {
            $customerSegment->load([
                'business',
                'customers' => function ($query) {
                    $query->select('customers.*')
                        ->withPivot('assigned_at', 'assigned_by')
                        ->latest('customer_segment.assigned_at');
                }
            ]);

            $segmentData = [
                'id' => $customerSegment->id,
                'name' => $customerSegment->name,
                'description' => $customerSegment->description,
                'criteria' => $customerSegment->criteria,
                'is_active' => $customerSegment->is_active,
                'created_at' => $customerSegment->created_at->format('Y-m-d H:i:s'),
                'business' => $customerSegment->business->only(['id', 'name']),
                'customers_count' => $customerSegment->customers->count(),
                'customers' => $customerSegment->customers->map(function ($customer) {
                    return [
                        'id' => $customer->id,
                        'customer_number' => $customer->customer_number,
                        'name' => $customer->name,
                        'email' => $customer->email,
                        'phone' => $customer->phone,
                        'customer_type' => $customer->customer_type,
                        'assigned_at' => $customer->pivot->assigned_at,
                    ];
                }),
            ];

            return successResponse('Customer segment retrieved successfully', $segmentData);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve customer segment', $e->getMessage());
        }
    }

    /**
     * Update segment
     */
    public function update(Request $request, CustomerSegment $customerSegment)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'criteria' => 'sometimes|nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $customerSegment->update($validator->validated());

            return updatedResponse($customerSegment, 'Customer segment updated successfully');
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to update customer segment', $e->getMessage());
        }
    }

    /**
     * Delete segment
     */
    public function destroy(CustomerSegment $customerSegment)
    {
        try {
            DB::beginTransaction();

            // Remove all customer assignments
            $customerSegment->customers()->detach();

            $customerSegment->delete();

            DB::commit();

            return deleteResponse('Customer segment deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to delete customer segment', $e->getMessage());
        }
    }

    /**
     * Assign customer to segment
     */
    public function assignCustomer(Request $request, CustomerSegment $customerSegment)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $customer = Customer::findOrFail($request->customer_id);

            // Check if customer belongs to same business
            if ($customer->business_id !== $customerSegment->business_id) {
                return errorResponse('Customer does not belong to the same business', 400);
            }

            // Check if already assigned
            if ($customerSegment->hasCustomer($request->customer_id)) {
                return errorResponse('Customer already assigned to this segment', 400);
            }

            $customerSegment->assignCustomer($request->customer_id, Auth::id());

            return successResponse('Customer assigned to segment successfully', [
                'segment' => $customerSegment->only(['id', 'name']),
                'customer' => $customer->only(['id', 'customer_number', 'name']),
            ]);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to assign customer to segment', $e->getMessage());
        }
    }

    /**
     * Remove customer from segment
     */
    public function removeCustomer(Request $request, CustomerSegment $customerSegment)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            if (!$customerSegment->hasCustomer($request->customer_id)) {
                return errorResponse('Customer not in this segment', 404);
            }

            $customerSegment->removeCustomer($request->customer_id);

            return successResponse('Customer removed from segment successfully');
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to remove customer from segment', $e->getMessage());
        }
    }

    /**
     * Bulk assign customers based on criteria
     */
    public function evaluateAndAssign(CustomerSegment $customerSegment)
    {
        try {
            DB::beginTransaction();

            $matchingCustomers = $customerSegment->evaluateCriteria();

            $assignedCount = 0;
            foreach ($matchingCustomers as $customer) {
                if (!$customerSegment->hasCustomer($customer->id)) {
                    $customerSegment->assignCustomer($customer->id, Auth::id());
                    $assignedCount++;
                }
            }

            DB::commit();

            return successResponse('Criteria evaluated and customers assigned', [
                'segment' => $customerSegment->only(['id', 'name']),
                'matched_customers' => $matchingCustomers->count(),
                'newly_assigned' => $assignedCount,
                'total_in_segment' => $customerSegment->customers()->count(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to evaluate criteria and assign customers', $e->getMessage());
        }
    }

    /**
     * Get segment statistics
     */
    public function statistics(CustomerSegment $customerSegment)
    {
        try {
            $customers = $customerSegment->customers;

            $stats = [
                'segment' => $customerSegment->only(['id', 'name', 'description']),
                'total_customers' => $customers->count(),
                'customer_types' => [
                    'regular' => $customers->where('customer_type', 'regular')->count(),
                    'vip' => $customers->where('customer_type', 'vip')->count(),
                    'wholesale' => $customers->where('customer_type', 'wholesale')->count(),
                ],
                'total_credit_balance' => $customers->sum('current_credit_balance'),
                'average_credit_balance' => $customers->avg('current_credit_balance'),
                'customers_with_credit' => $customers->where('current_credit_balance', '>', 0)->count(),
            ];

            return successResponse('Segment statistics retrieved successfully', $stats);
        } catch (\Exception $e) {
            return queryErrorResponse('Failed to retrieve segment statistics', $e->getMessage());
        }
    }

    /**
     * Toggle segment status
     */
    public function toggleStatus(CustomerSegment $customerSegment)
    {
        try {
            $newStatus = !$customerSegment->is_active;
            $customerSegment->update(['is_active' => $newStatus]);

            $statusText = $newStatus ? 'activated' : 'deactivated';
            return successResponse("Customer segment {$statusText} successfully", $customerSegment);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to toggle segment status', $e->getMessage());
        }
    }
}
