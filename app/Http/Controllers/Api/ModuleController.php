<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Module;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use App\Models\Submodule;
use Illuminate\Support\Str;


class ModuleController extends Controller
{

    public function getModules(Request $request)
    {
        try {
            $user = Auth::user();
            $query = Module::with(['submodules' => function ($query) use ($user) {
                $query->select('id', 'module_id', 'title', 'is_active');

                // For non-admin roles, only show active submodules
                if ($user->role_id !== 1) {
                    $query->where('is_active', 1);
                }
            }])
                ->select('id', 'name', 'is_active');

            // For non-admin roles, only show active modules
            if ($user->role_id !== 1) {
                $query->where('is_active', 1);
            }

            $modules = $query->get();

            if ($modules->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'success' => true,
                    'message' => 'No modules found',
                    'data' => []
                ], 200);
            }

            return successResponse('Modules and submodules retrieved successfully', $modules);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to retrieve modules and submodules', $e->getMessage());
        }
    }

    public function getSubmodules()
    {
        try {
            $user = Auth::user();
            $query = Submodule::select(
                'submodules.id',
                'submodules.module_id',
                'submodules.title',

                'submodules.is_active',
                'modules.name as module_name',
                'modules.is_active as module_is_active'
            )
                ->join('modules', 'modules.id', '=', 'submodules.module_id');

            // For non-admin roles, only show active modules and submodules
            if ($user->role_id !== 1) {
                $query->where('submodules.is_active', 1)
                    ->where('modules.is_active', 1);
            }

            $submodules = $query->orderBy('submodules.title')
                ->get();

            if ($submodules->isEmpty()) {
                return notFoundResponse('No submodules found');
            }

            return successResponse('Submodules retrieved successfully', $submodules);
        } catch (\Exception $e) {
            return serverErrorResponse('Failed to retrieve submodules', $e->getMessage());
        }
    }



    public function storeModule(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:modules,name',

            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            DB::beginTransaction();

            $module = Module::create([
                'name' => $request->name,

            ]);

            DB::commit();

            return successResponse('Module created successfully', $module, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to create module', $e->getMessage());
        }
    }

    public function storeSubmodule(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',

                'module_id' => 'required|exists:modules,id'
            ]);

            if ($validator->fails()) {
                return validationErrorResponse($validator->errors());
            }

            // Check if module exists
            $module = Module::find($request->module_id);
            if (!$module) {
                return notFoundResponse('Module not found');
            }

            // Check for duplicate title within the same module
            $existingSubmodule = Submodule::where('module_id', $request->module_id)
                ->where('title', $request->title)
                ->first();

            if ($existingSubmodule) {
                return errorResponse('Submodule with this title already exists in the selected module', 422);
            }

            DB::beginTransaction();

            $submodule = Submodule::create([
                'title' => $request->title,

                'module_id' => $request->module_id
            ]);

            DB::commit();

            // Load the module relationship for the response
            $submodule->load('module:id,name');

            return successResponse('Submodule created successfully', $submodule, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to create submodule', $e->getMessage());
        }
    }




    public function destroyModule($id)
    {
        try {
            DB::beginTransaction();

            $module = Module::find($id);

            if (!$module) {
                return notFoundResponse('Module not found');
            }

            // The actual deletion and cascade logic is handled in the Module model boot method
            $module->delete();

            DB::commit();
            return deleteResponse('Module and associated records deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to delete module', $e->getMessage());
        }
    }

    public function destroySubmodule($id)
    {
        try {
            DB::beginTransaction();

            $submodule = Submodule::find($id);

            if (!$submodule) {
                return notFoundResponse('Submodule not found');
            }

            // The actual deletion and cascade logic is handled in the Submodule model boot method
            $submodule->delete();

            DB::commit();
            return deleteResponse('Submodule and associated records deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to delete submodule', $e->getMessage());
        }
    }



    public function toggleModuleStatus($id)
    {
        try {
            DB::beginTransaction();

            $module = Module::find($id);

            if (!$module) {
                return notFoundResponse('Module not found');
            }

            $module->is_active = !$module->is_active;
            $module->save();

            DB::commit();

            $message = $module->is_active ? 'Module activated successfully' : 'Module deactivated successfully';
            return successResponse($message, $module);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to update module status', $e->getMessage());
        }
    }

    public function toggleSubmoduleStatus($id)
    {
        try {
            DB::beginTransaction();

            $submodule = Submodule::find($id);

            if (!$submodule) {
                return notFoundResponse('Submodule not found');
            }

            $submodule->is_active = !$submodule->is_active;
            $submodule->save();

            DB::commit();

            $message = $submodule->is_active ? 'Submodule activated successfully' : 'Submodule deactivated successfully';
            return successResponse($message, $submodule);
        } catch (\Exception $e) {
            DB::rollBack();
            return serverErrorResponse('Failed to update submodule status', $e->getMessage());
        }
    }
}
