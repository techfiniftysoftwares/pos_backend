<?php
// routes/api.php

use App\Http\Controllers\Api\AuthController;

use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ModuleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\PinAuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\BranchController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/



// Public authentication routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    // POS PIN authentication
    Route::post('/pin-login', [PinAuthController::class, 'pinLogin']);
});


// Protected routes (require Passport authentication)
Route::middleware(['auth:api'])->group(function () {

    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/signup', [AuthController::class, 'signup']);
        Route::put('/profile', [AuthController::class, 'profileChange']);



        // PIN management
        Route::post('/change-pin', [PinAuthController::class, 'changePin']);
        Route::post('/pin-logout', [PinAuthController::class, 'pinLogout']);
        Route::post('/switch-branch', [PinAuthController::class, 'switchBranch']);
        Route::post('/reset-pin/{user}', [PinAuthController::class, 'resetUserPin']);
    });

    // user routes
    Route::apiResource('users', UserController::class)->only(['index', 'show', 'destroy']);
    Route::get('user/profile', [UserController::class, 'getProfile']);
    Route::put('user/profile', [UserController::class, 'updateProfile']);
    Route::put('users/{user}/edit', [UserController::class, 'updateUserSpecifics']);
    Route::put('users/{user}/toggle-status', [UserController::class, 'toggleStatus']);


    Route::apiResource('/roles', RoleController::class);

    Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions']);
    Route::get('/modules', [ModuleController::class, 'getModules']);
    Route::patch('/modules/{id}/toggle-status', [ModuleController::class, 'toggleModuleStatus']);

    // Submodule status toggle
    Route::patch('/submodules/{id}/toggle-status', [ModuleController::class, 'toggleSubmoduleStatus']);
    Route::delete('modules/{id}', [ModuleController::class, 'destroyModule']);
    Route::delete('submodules/{id}', [ModuleController::class, 'destroySubmodule']);
    Route::post('modules', [ModuleController::class, 'storeModule']);
    Route::post('submodules', [ModuleController::class, 'storeSubmodule']);
    Route::get('submodules', [ModuleController::class, 'getSubmodules']);

     // Business management
    Route::prefix('businesses')->group(function () {
        Route::get('/', [BusinessController::class, 'index']);
        Route::post('/', [BusinessController::class, 'store']);
        Route::get('/{business}', [BusinessController::class, 'show']);
        Route::put('/{business}', [BusinessController::class, 'update']);
        Route::delete('/{business}', [BusinessController::class, 'destroy']);
    });

    // Branch management
    Route::prefix('branches')->group(function () {
        Route::get('/', [BranchController::class, 'index']);
        Route::post('/', [BranchController::class, 'store']);
        Route::get('/{branch}', [BranchController::class, 'show']);
        Route::put('/{branch}', [BranchController::class, 'update']);
        Route::delete('/{branch}', [BranchController::class, 'destroy']);
        Route::patch('/{branch}/toggle-status', [BranchController::class, 'toggleStatus']);
    });
});
