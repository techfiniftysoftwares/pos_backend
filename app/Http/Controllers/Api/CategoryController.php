<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Get all categories with optional filters
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $search = $request->input('search');
            $isActive = $request->input('is_active');
            $parentId = $request->input('parent_id');
            $businessId = $request->user()->business_id;

            $query = Category::with(['parent', 'children'])
                ->forBusiness($businessId);

            // Search filter
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Active status filter
            if (isset($isActive)) {
                $query->where('is_active', (bool) $isActive);
            }

            // Parent category filter
            if (isset($parentId)) {
                if ($parentId === 'null' || $parentId === '0') {
                    $query->rootCategories();
                } else {
                    $query->where('parent_id', $parentId);
                }
            }

            $categories = $query->orderBy('name', 'asc')
                ->paginate($perPage);

            // Transform the data
            $categories->getCollection()->transform(function ($category) {
                return $this->transformCategory($category);
            });

            return successResponse('Categories retrieved successfully', $categories);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve categories', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return queryErrorResponse('Failed to retrieve categories', $e->getMessage());
        }
    }

    /**
     * Create new category
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $businessId = $request->user()->business_id;

            // Check for duplicate category name
            $exists = Category::forBusiness($businessId)
                ->where('name', $request->name)
                ->where('parent_id', $request->parent_id)
                ->exists();

            if ($exists) {
                return errorResponse('Category with this name already exists', 422);
            }

            // Validate parent_id belongs to same business
            if ($request->parent_id) {
                $parent = Category::find($request->parent_id);
                if (!$parent || $parent->business_id !== $businessId) {
                    return errorResponse('Invalid parent category', 422);
                }
            }

            DB::beginTransaction();

            $categoryData = [
                'business_id' => $businessId,
                'name' => $request->name,
                'parent_id' => $request->parent_id,
                'description' => $request->description,
                'is_active' => $request->input('is_active', true),
            ];

            // Handle image upload
            if ($request->hasFile('image')) {
                $image = $request->file('image');

                // Generate unique filename
                $filename = 'category_' . Str::random(20) . '.' . $image->getClientOriginalExtension();

                // Store in public/storage/categories
                $path = $image->storeAs('categories', $filename, 'public');

                $categoryData['image'] = $path;
            }

            $category = Category::create($categoryData);

            DB::commit();

            $category->load(['parent', 'children']);

            return successResponse(
                'Category created successfully',
                $this->transformCategory($category),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();

            // Delete uploaded image if category creation failed
            if (isset($path)) {
                Storage::disk('public')->delete($path);
            }

            Log::error('Failed to create category', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->except(['image'])
            ]);
            return serverErrorResponse('Failed to create category', $e->getMessage());
        }
    }

    /**
     * Get specific category
     */
    public function show(Category $category)
    {
        try {
            // Check business access
            if ($category->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this category', 403);
            }

            $category->load([
                'parent',
                'children.products',
                'products'
            ]);

            $categoryData = $this->transformCategory($category);

            // Add additional stats
            $categoryData['total_products'] = $category->products()->count();
            $categoryData['active_products'] = $category->products()->where('is_active', true)->count();
            $categoryData['subcategories_count'] = $category->children()->count();

            return successResponse('Category retrieved successfully', $categoryData);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve category', [
                'category_id' => $category->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve category', $e->getMessage());
        }
    }

    /**
     * Update category
     */
    public function update(Request $request, Category $category)
    {
        // Check business access
        if ($category->business_id !== $request->user()->business_id) {
            return errorResponse('Unauthorized access to this category', 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'is_active' => 'sometimes|boolean',
            'remove_image' => 'sometimes|boolean', // Flag to remove existing image
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            // Prevent circular reference
            if ($request->parent_id && $request->parent_id == $category->id) {
                return errorResponse('Category cannot be its own parent', 422);
            }

            // Check for duplicate name
            if ($request->has('name')) {
                $exists = Category::forBusiness($category->business_id)
                    ->where('name', $request->name)
                    ->where('parent_id', $request->input('parent_id', $category->parent_id))
                    ->where('id', '!=', $category->id)
                    ->exists();

                if ($exists) {
                    return errorResponse('Category with this name already exists', 422);
                }
            }

            DB::beginTransaction();

            $updateData = collect($request->only([
                'name',
                'parent_id',
                'description',
                'is_active'
            ]))->filter(function ($value, $key) {
                return !is_null($value) || $key === 'parent_id'; // Allow null parent_id
            })->toArray();

            $oldImage = $category->image;

            // Handle image removal
            if ($request->input('remove_image', false)) {
                if ($category->image) {
                    Storage::disk('public')->delete($category->image);
                }
                $updateData['image'] = null;
            }

            // Handle new image upload
            if ($request->hasFile('image')) {
                // Delete old image
                if ($category->image) {
                    Storage::disk('public')->delete($category->image);
                }

                $image = $request->file('image');
                $filename = 'category_' . Str::random(20) . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('categories', $filename, 'public');
                $updateData['image'] = $path;
            }

            $category->update($updateData);

            DB::commit();

            $category->load(['parent', 'children']);

            return updatedResponse(
                $this->transformCategory($category),
                'Category updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();

            // Delete newly uploaded image if update failed
            if (isset($path) && $path !== $oldImage) {
                Storage::disk('public')->delete($path);
            }

            Log::error('Failed to update category', [
                'category_id' => $category->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return serverErrorResponse('Failed to update category', $e->getMessage());
        }
    }

    /**
     * Delete category
     */
    public function destroy(Category $category)
    {
        try {
            // Check business access
            if ($category->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this category', 403);
            }

            DB::beginTransaction();

            // Check if category has products
            if ($category->products()->exists()) {
                return errorResponse(
                    'Cannot delete category with existing products. Please reassign or delete products first.',
                    400
                );
            }

            // Check if category has subcategories
            if ($category->children()->exists()) {
                return errorResponse(
                    'Cannot delete category with subcategories. Please delete subcategories first.',
                    400
                );
            }

            // Delete image if exists
            if ($category->image) {
                Storage::disk('public')->delete($category->image);
            }

            $category->delete();

            DB::commit();

            return deleteResponse('Category deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete category', [
                'category_id' => $category->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to delete category', $e->getMessage());
        }
    }

    /**
     * Toggle category status
     */
    public function toggleStatus(Category $category)
    {
        try {
            // Check business access
            if ($category->business_id !== request()->user()->business_id) {
                return errorResponse('Unauthorized access to this category', 403);
            }

            DB::beginTransaction();

            $newStatus = !$category->is_active;
            $category->update(['is_active' => $newStatus]);

            DB::commit();

            $statusText = $newStatus ? 'activated' : 'deactivated';
            return successResponse(
                "Category {$statusText} successfully",
                $this->transformCategory($category)
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to toggle category status', [
                'category_id' => $category->id,
                'error' => $e->getMessage()
            ]);
            return serverErrorResponse('Failed to toggle category status', $e->getMessage());
        }
    }

    /**
     * Get category tree
     */
    public function tree(Request $request)
    {
        try {
            $businessId = $request->user()->business_id;

            $categories = Category::with(['children.children'])
                ->forBusiness($businessId)
                ->rootCategories()
                ->active()
                ->orderBy('name', 'asc')
                ->get();

            $tree = $categories->map(function ($category) {
                return $this->buildTree($category);
            });

            return successResponse('Category tree retrieved successfully', $tree);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve category tree', [
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to retrieve category tree', $e->getMessage());
        }
    }

    /**
     * Helper: Transform category data
     */
    private function transformCategory($category)
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'image' => $category->image ? asset('storage/' . $category->image) : null,
            'image_path' => $category->image,
            'is_active' => $category->is_active,
            'parent_id' => $category->parent_id,
            'parent' => $category->parent ? [
                'id' => $category->parent->id,
                'name' => $category->parent->name
            ] : null,
            'children_count' => $category->children->count(),
            'products_count' => $category->products_count ?? $category->products()->count(),
            'created_at' => $category->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $category->updated_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Helper: Build hierarchical tree
     */
    private function buildTree($category)
    {
        $data = [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'image' => $category->image ? asset('storage/' . $category->image) : null,
            'is_active' => $category->is_active,
            'products_count' => $category->products()->count(),
        ];

        if ($category->children->isNotEmpty()) {
            $data['children'] = $category->children->map(function ($child) {
                return $this->buildTree($child);
            });
        }

        return $data;
    }
}
