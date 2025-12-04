<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RevenueStream;
use App\Models\Business; // 🆕 Add this import
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RevenueStreamController extends Controller
{
    /**
     * Get all revenue streams
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $search = $request->input('search');
            $isActive = $request->input('is_active');
            $businessId = $request->user()->business_id;

            $query = RevenueStream::forBusiness($businessId);

            // Search filter
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Active status filter
            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            $streams = $query->orderBy('name', 'asc')
                ->paginate($perPage);

            // Transform the data
            $streams->getCollection()->transform(function ($stream) {
                return $this->transformRevenueStream($stream);
            });

            return successResponse('Revenue streams retrieved successfully', $streams);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve revenue streams', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return queryErrorResponse('Failed to retrieve revenue streams', $e->getMessage());
        }
    }

    /**
     * Create new revenue stream
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:revenue_streams,code',
            'description' => 'nullable|string',
            'default_currency' => 'sometimes|string|size:3',
            'requires_approval' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;

            // 🆕 Get business with base currency
            $business = Business::with('baseCurrency')->findOrFail($businessId);

            // 🆕 Check if business has a base currency configured
            if (!$business->base_currency_id) {
                return errorResponse('Business does not have a base currency configured. Please contact administrator.', 400);
            }

            DB::beginTransaction();

            $streamData = [
                'business_id' => $businessId,
                'name' => $request->name,
                'code' => strtolower($request->code),
                'description' => $request->description,
                // 🆕 Use business base currency if not provided
                'default_currency' => $request->input('default_currency', $business->baseCurrency->code),
                'requires_approval' => $request->input('requires_approval', false),
                'is_active' => $request->input('is_active', true),
            ];

            $stream = RevenueStream::create($streamData);

            DB::commit();

            return successResponse(
                'Revenue stream created successfully',
                $this->transformRevenueStream($stream),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to create revenue stream', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return serverErrorResponse('Failed to create revenue stream', $e->getMessage());
        }
    }

    /**
     * Get specific revenue stream
     */
    public function show(RevenueStream $revenueStream)
    {
        try {
            // Check business access
            if ($revenueStream->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this revenue stream', 403);
            }

            $streamData = $this->transformRevenueStream($revenueStream);

            // Add additional stats
            $streamData['total_entries'] = $revenueStream->revenueEntries()->count();
            $streamData['pending_entries'] = $revenueStream->revenueEntries()->where('status', 'pending')->count();
            $streamData['approved_entries'] = $revenueStream->revenueEntries()->where('status', 'approved')->count();

            return successResponse('Revenue stream retrieved successfully', $streamData);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve revenue stream', [
                'stream_id' => $revenueStream->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve revenue stream', $e->getMessage());
        }
    }

    /**
     * Update revenue stream
     */
    public function update(Request $request, RevenueStream $revenueStream)
    {
        // Check business access
        if ($revenueStream->business_id !== $request->user()->business_id) {
            return errorResponse('Unauthorized access to this revenue stream', 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|unique:revenue_streams,code,' . $revenueStream->id,
            'description' => 'nullable|string',
            'default_currency' => 'sometimes|string|size:3',
            'requires_approval' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $updateData = collect($request->only([
                'name',
                'code',
                'description',
                'default_currency',
                'requires_approval',
                'is_active'
            ]))->filter(function ($value, $key) {
                return !is_null($value) || in_array($key, ['description']);
            })->toArray();

            // Ensure code is lowercase
            if (isset($updateData['code'])) {
                $updateData['code'] = strtolower($updateData['code']);
            }

            $revenueStream->update($updateData);

            DB::commit();

            return updatedResponse(
                $this->transformRevenueStream($revenueStream),
                'Revenue stream updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to update revenue stream', [
                'stream_id' => $revenueStream->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to update revenue stream', $e->getMessage());
        }
    }

    /**
     * Delete revenue stream
     */
    public function destroy(RevenueStream $revenueStream)
    {
        try {
            // Check business access
            if ($revenueStream->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this revenue stream', 403);
            }

            DB::beginTransaction();

            // Check if revenue stream has entries
            if ($revenueStream->revenueEntries()->exists()) {
                return errorResponse(
                    'Cannot delete revenue stream with existing entries. Please deactivate instead.',
                    400
                );
            }

            $revenueStream->delete();

            DB::commit();

            return deleteResponse('Revenue stream deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete revenue stream', [
                'stream_id' => $revenueStream->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete revenue stream', $e->getMessage());
        }
    }

    /**
     * Toggle revenue stream status
     */
    public function toggleStatus(RevenueStream $revenueStream)
    {
        try {
            // Check business access
            if ($revenueStream->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this revenue stream', 403);
            }

            DB::beginTransaction();

            $newStatus = !$revenueStream->is_active;
            $revenueStream->update(['is_active' => $newStatus]);

            DB::commit();

            $statusText = $newStatus ? 'activated' : 'deactivated';
            return successResponse(
                "Revenue stream {$statusText} successfully",
                $this->transformRevenueStream($revenueStream)
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to toggle revenue stream status', [
                'stream_id' => $revenueStream->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to toggle revenue stream status', $e->getMessage());
        }
    }

    /**
     * Helper: Transform revenue stream data
     */
    private function transformRevenueStream($stream)
    {
        return [
            'id' => $stream->id,
            'business_id' => $stream->business_id,
            'name' => $stream->name,
            'code' => $stream->code,
            'description' => $stream->description,
            'default_currency' => $stream->default_currency,
            'requires_approval' => $stream->requires_approval,
            'is_active' => $stream->is_active,
            'created_at' => $stream->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $stream->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
