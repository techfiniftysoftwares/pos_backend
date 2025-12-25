<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * Get all products with filters
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $search = $request->input('search');
            $categoryId = $request->input('category_id');
            $supplierId = $request->input('supplier_id');
            $isActive = $request->input('is_active');
            $lowStock = $request->input('low_stock'); // boolean
            $businessId = $request->user()->business_id;

            // Sorting parameters
            $sortBy = $request->input('sort_by', 'name');
            $sortDirection = $request->input('sort_direction', 'asc');

            // Whitelist of allowed sortable columns to prevent SQL injection
            $allowedSortColumns = [
                'name',
                'sku',
                'cost_price',
                'selling_price',
                'is_active',
                'created_at',
                'updated_at',
            ];

            // Validate sort column
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'name';
            }

            // Validate sort direction
            $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

            $query = Product::with(['category', 'unit', 'supplier'])
                ->forBusiness($businessId);

            // Search filter
            if ($search) {
                $query->search($search);
            }

            // Category filter
            if ($categoryId) {
                $query->where('category_id', $categoryId);
            }

            // Supplier filter
            if ($supplierId) {
                $query->where('supplier_id', $supplierId);
            }

            // Active status filter
            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            // Low stock filter
            if ($lowStock) {
                $query->lowStock();
            }

            $products = $query->orderBy($sortBy, $sortDirection)
                ->paginate($perPage);

            // Transform the data
            $products->getCollection()->transform(function ($product) {
                return $this->transformProduct($product);
            });

            return successResponse('Products retrieved successfully', $products);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve products', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return queryErrorResponse('Failed to retrieve products', $e->getMessage());
        }
    }

    /**
     * Create new product
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'required|exists:units,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'sku' => 'nullable|string|max:100|unique:products,sku',
            'barcode' => 'nullable|string|max:100|unique:products,barcode',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'cost_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'minimum_stock_level' => 'nullable|integer|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'track_inventory' => 'sometimes|boolean',
            'allow_negative_stock' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;

            // Validate category belongs to business
            $category = Category::find($request->category_id);
            if (!$category || $category->business_id !== $businessId) {
                return errorResponse('Invalid category', 422);
            }

            // Validate unit belongs to business
            $unit = Unit::find($request->unit_id);
            if (!$unit || $unit->business_id !== $businessId) {
                return errorResponse('Invalid unit', 422);
            }

            // Validate supplier belongs to business (if provided)
            if ($request->supplier_id) {
                $supplier = Supplier::find($request->supplier_id);
                if (!$supplier || $supplier->business_id !== $businessId) {
                    return errorResponse('Invalid supplier', 422);
                }
            }

            // Validate selling price is not less than cost price (warning only)
            if ($request->selling_price < $request->cost_price) {
                Log::warning('Product selling price is less than cost price', [
                    'product_name' => $request->name,
                    'cost_price' => $request->cost_price,
                    'selling_price' => $request->selling_price
                ]);
            }

            DB::beginTransaction();

            $productData = [
                'business_id' => $businessId,
                'category_id' => $request->category_id,
                'unit_id' => $request->unit_id,
                'supplier_id' => $request->supplier_id,
                'name' => $request->name,
                'sku' => $request->sku,
                'barcode' => $request->barcode,
                'description' => $request->description,
                'cost_price' => $request->cost_price,
                'selling_price' => $request->selling_price,
                'minimum_stock_level' => $request->input('minimum_stock_level', 0),
                'tax_rate' => $request->input('tax_rate', 0),
                'track_inventory' => $request->input('track_inventory', true),
                'allow_negative_stock' => $request->input('allow_negative_stock', false),
                'is_active' => $request->input('is_active', true),
            ];

            // Generate SKU if not provided
            if (!$productData['sku']) {
                $productData['sku'] = $this->generateSKU($businessId, $category->name);
            }

            // Handle image upload
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $filename = 'product_' . Str::random(20) . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('products', $filename, 'public');
                $productData['image'] = $path;
            }

            $product = Product::create($productData);

            DB::commit();

            $product->load(['category', 'unit', 'supplier']);

            return successResponse(
                'Product created successfully',
                $this->transformProduct($product),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();

            // Delete uploaded image if product creation failed
            if (isset($path)) {
                Storage::disk('public')->delete($path);
            }

            Log::error('Failed to create product', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->except(['image'])
            ]);
            return serverErrorResponse('Failed to create product', $e->getMessage());
        }
    }

    /**
     * Get specific product
     */
    public function show(Product $product)
    {
        try {
            // Check business access
            if ($product->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this product', 403);
            }

            $product->load(['category', 'unit', 'supplier']);

            $productData = $this->transformProduct($product);

            // Add computed fields
            $productData['profit_margin'] = $product->profit_margin;
            $productData['profit_percentage'] = round($product->profit_percentage, 2);
            $productData['price_with_tax'] = $product->price_with_tax;

            return successResponse('Product retrieved successfully', $productData);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve product', [
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve product', $e->getMessage());
        }
    }

    /**
     * Update product
     */
    public function update(Request $request, Product $product)
    {
        // Check business access
        if ($product->business_id !== $request->user()->business_id) {
            return errorResponse('Unauthorized access to this product', 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'category_id' => 'sometimes|exists:categories,id',
            'unit_id' => 'sometimes|exists:units,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'sku' => 'sometimes|string|max:100|unique:products,sku,' . $product->id,
            'barcode' => 'nullable|string|max:100|unique:products,barcode,' . $product->id,
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'cost_price' => 'sometimes|numeric|min:0',
            'selling_price' => 'sometimes|numeric|min:0',
            'minimum_stock_level' => 'sometimes|integer|min:0',
            'tax_rate' => 'sometimes|numeric|min:0|max:100',
            'track_inventory' => 'sometimes|boolean',
            'allow_negative_stock' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'remove_image' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;

            // Validate category belongs to business
            if ($request->has('category_id')) {
                $category = Category::find($request->category_id);
                if (!$category || $category->business_id !== $businessId) {
                    return errorResponse('Invalid category', 422);
                }
            }

            // Validate unit belongs to business
            if ($request->has('unit_id')) {
                $unit = Unit::find($request->unit_id);
                if (!$unit || $unit->business_id !== $businessId) {
                    return errorResponse('Invalid unit', 422);
                }
            }

            // Validate supplier belongs to business
            if ($request->has('supplier_id') && $request->supplier_id) {
                $supplier = Supplier::find($request->supplier_id);
                if (!$supplier || $supplier->business_id !== $businessId) {
                    return errorResponse('Invalid supplier', 422);
                }
            }

            DB::beginTransaction();

            $updateData = collect($request->only([
                'name',
                'category_id',
                'unit_id',
                'supplier_id',
                'sku',
                'barcode',
                'description',
                'cost_price',
                'selling_price',
                'minimum_stock_level',
                'tax_rate',
                'track_inventory',
                'allow_negative_stock',
                'is_active'
            ]))->filter(function ($value, $key) {
                return !is_null($value) || in_array($key, ['supplier_id', 'barcode']);
            })->toArray();

            $oldImage = $product->image;

            // Handle image removal
            if ($request->input('remove_image', false)) {
                if ($product->image) {
                    Storage::disk('public')->delete($product->image);
                }
                $updateData['image'] = null;
            }

            // Handle new image upload
            if ($request->hasFile('image')) {
                // Delete old image
                if ($product->image) {
                    Storage::disk('public')->delete($product->image);
                }

                $image = $request->file('image');
                $filename = 'product_' . Str::random(20) . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('products', $filename, 'public');
                $updateData['image'] = $path;
            }

            $product->update($updateData);

            DB::commit();

            $product->load(['category', 'unit', 'supplier']);

            return updatedResponse(
                $this->transformProduct($product),
                'Product updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();

            // Delete newly uploaded image if update failed
            if (isset($path) && $path !== $oldImage) {
                Storage::disk('public')->delete($path);
            }

            Log::error('Failed to update product', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to update product', $e->getMessage());
        }
    }

    /**
     * Delete product
     */
    public function destroy(Product $product)
    {
        try {
            // Check business access
            if ($product->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this product', 403);
            }

            DB::beginTransaction();

            // Check if product has been sold (has sale items)
            // Uncomment when SaleItem model exists
            // if ($product->saleItems()->exists()) {
            //     return errorResponse(
            //         'Cannot delete product with existing sales history.',
            //         400
            //     );
            // }

            // Delete image if exists
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }

            $product->delete();

            DB::commit();

            return deleteResponse('Product deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete product', [
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete product', $e->getMessage());
        }
    }

    /**
     * Toggle product status
     */
    public function toggleStatus(Product $product)
    {
        try {
            // Check business access
            if ($product->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this product', 403);
            }

            DB::beginTransaction();

            $newStatus = !$product->is_active;
            $product->update(['is_active' => $newStatus]);

            DB::commit();

            $statusText = $newStatus ? 'activated' : 'deactivated';
            return successResponse(
                "Product {$statusText} successfully",
                $this->transformProduct($product)
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to toggle product status', [
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to toggle product status', $e->getMessage());
        }
    }

    /**
     * Bulk update products
     */
    public function bulkUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id',
            'action' => 'required|in:activate,deactivate,update_category,update_tax_rate',
            'category_id' => 'required_if:action,update_category|exists:categories,id',
            'tax_rate' => 'required_if:action,update_tax_rate|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;

            DB::beginTransaction();

            $products = Product::whereIn('id', $request->product_ids)
                ->forBusiness($businessId)
                ->get();

            if ($products->isEmpty()) {
                return errorResponse('No products found', 404);
            }

            switch ($request->action) {
                case 'activate':
                    $products->each->update(['is_active' => true]);
                    $message = 'Products activated successfully';
                    break;

                case 'deactivate':
                    $products->each->update(['is_active' => false]);
                    $message = 'Products deactivated successfully';
                    break;

                case 'update_category':
                    $products->each->update(['category_id' => $request->category_id]);
                    $message = 'Products category updated successfully';
                    break;

                case 'update_tax_rate':
                    $products->each->update(['tax_rate' => $request->tax_rate]);
                    $message = 'Products tax rate updated successfully';
                    break;
            }

            DB::commit();

            return successResponse($message, [
                'updated_count' => $products->count()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to bulk update products', [
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to bulk update products', $e->getMessage());
        }
    }

    /**
     * Generate unique SKU
     */
    private function generateSKU($businessId, $categoryName)
    {
        $prefix = strtoupper(substr($categoryName, 0, 3));
        $count = Product::where('business_id', $businessId)->count();

        do {
            $count++;
            $sku = $prefix . str_pad($count, 6, '0', STR_PAD_LEFT);
        } while (Product::where('sku', $sku)->exists());

        return $sku;
    }

    /**
     * Helper: Transform product data
     */
    private function transformProduct($product)
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'description' => $product->description,
            'image' => $product->image ? asset('storage/' . $product->image) : null,
            'image_path' => $product->image,
            'cost_price' => (float) $product->cost_price,
            'selling_price' => (float) $product->selling_price,
            'minimum_stock_level' => $product->minimum_stock_level,
            'tax_rate' => (float) $product->tax_rate,
            'track_inventory' => $product->track_inventory,
            'allow_negative_stock' => $product->allow_negative_stock,
            'is_active' => $product->is_active,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name
            ] : null,
            'unit' => $product->unit ? [
                'id' => $product->unit->id,
                'name' => $product->unit->name,
                'symbol' => $product->unit->symbol
            ] : null,
            'supplier' => $product->supplier ? [
                'id' => $product->supplier->id,
                'name' => $product->supplier->name
            ] : null,
            'created_at' => $product->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $product->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
