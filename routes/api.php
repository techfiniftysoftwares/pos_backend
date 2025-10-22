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
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\StockTransferController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\StorageLocationController;
use App\Http\Controllers\Api\CustomerCreditController;
use App\Http\Controllers\Api\CustomerPointController;
use App\Http\Controllers\Api\GiftCardController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\CustomerSegmentController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\CashMovementController;
use App\Http\Controllers\Api\CashReconciliationController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\CashRegisterController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ExchangeRateSourceController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\RevenueStreamController;
use App\Http\Controllers\Api\RevenueEntryController;


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
     Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::post('/', [CategoryController::class, 'store']);
        Route::get('/tree', [CategoryController::class, 'tree']);
        Route::get('/{category}', [CategoryController::class, 'show']);
        Route::put('/{category}', [CategoryController::class, 'update']);
        Route::delete('/{category}', [CategoryController::class, 'destroy']);
        Route::patch('/{category}/toggle-status', [CategoryController::class, 'toggleStatus']);
    });
    // Unit routes
    Route::prefix('units')->group(function () {
        Route::get('/', [UnitController::class, 'index']);
        Route::post('/', [UnitController::class, 'store']);
        Route::get('/{unit}', [UnitController::class, 'show']);
        Route::put('/{unit}', [UnitController::class, 'update']);
        Route::delete('/{unit}', [UnitController::class, 'destroy']);
        Route::patch('/{unit}/toggle-status', [UnitController::class, 'toggleStatus']);
    });
     // Product routes
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::post('/', [ProductController::class, 'store']);
        Route::post('/bulk-update', [ProductController::class, 'bulkUpdate']);
        Route::get('/{product}', [ProductController::class, 'show']);
        Route::put('/{product}', [ProductController::class, 'update']);
        Route::delete('/{product}', [ProductController::class, 'destroy']);
        Route::patch('/{product}/toggle-status', [ProductController::class, 'toggleStatus']);
    });
    // Supplier routes
    Route::prefix('suppliers')->group(function () {
        Route::get('/', [SupplierController::class, 'index']);
        Route::post('/', [SupplierController::class, 'store']);
        Route::get('/{supplier}', [SupplierController::class, 'show']);
        Route::get('/{supplier}/statistics', [SupplierController::class, 'statistics']);
        Route::put('/{supplier}', [SupplierController::class, 'update']);
        Route::delete('/{supplier}', [SupplierController::class, 'destroy']);
        Route::patch('/{supplier}/toggle-status', [SupplierController::class, 'toggleStatus']);
    });
    // Stock routes
    Route::prefix('stocks')->group(function () {
        Route::get('/', [StockController::class, 'index']);
        Route::get('/low-stock-alerts', [StockController::class, 'lowStockAlerts']);
        Route::get('/summary-by-branch', [StockController::class, 'summaryByBranch']);
        Route::get('/movements', [StockController::class, 'movements']);
        Route::get('/by-product-branch', [StockController::class, 'getByProductAndBranch']);
        Route::get('/{stock}', [StockController::class, 'show']);
        Route::put('/{stock}', [StockController::class, 'update']);
    });
    // Stock Adjustment routes
    Route::prefix('stock-adjustments')->group(function () {
        Route::get('/', [StockAdjustmentController::class, 'index']);
        Route::post('/', [StockAdjustmentController::class, 'store']);
        Route::get('/{stockAdjustment}', [StockAdjustmentController::class, 'show']);
        Route::put('/{stockAdjustment}', [StockAdjustmentController::class, 'update']);
        Route::delete('/{stockAdjustment}', [StockAdjustmentController::class, 'destroy']);
        Route::patch('/{stockAdjustment}/approve', [StockAdjustmentController::class, 'approve']);
    });
    // Stock Transfer routes
    Route::prefix('stock-transfers')->group(function () {
        Route::get('/', [StockTransferController::class, 'index']);
        Route::post('/', [StockTransferController::class, 'store']);
        Route::get('/{stockTransfer}', [StockTransferController::class, 'show']);
        Route::delete('/{stockTransfer}', [StockTransferController::class, 'destroy']);
        Route::patch('/{stockTransfer}/approve', [StockTransferController::class, 'approve']);
        Route::patch('/{stockTransfer}/send', [StockTransferController::class, 'sendTransfer']);
        Route::patch('/{stockTransfer}/receive', [StockTransferController::class, 'receiveTransfer']);
        Route::patch('/{stockTransfer}/cancel', [StockTransferController::class, 'cancel']);
    });
    // / Storage Location routes
    Route::prefix('storage-locations')->group(function () {
        Route::get('/', [StorageLocationController::class, 'index']);
        Route::post('/', [StorageLocationController::class, 'store']);
        Route::get('/{storageLocation}', [StorageLocationController::class, 'show']);
        Route::put('/{storageLocation}', [StorageLocationController::class, 'update']);
        Route::delete('/{storageLocation}', [StorageLocationController::class, 'destroy']);
        Route::patch('/{storageLocation}/toggle-status', [StorageLocationController::class, 'toggleStatus']);
    });
    // customer credit routes
    Route::prefix('customers/credit')->group(function () {
    Route::get('/', [CustomerCreditController::class, 'index']);
    Route::post('/', [CustomerCreditController::class, 'store']);
    Route::get('/outstanding', [CustomerCreditController::class, 'outstandingCredits']);
    Route::get('/aging-report', [CustomerCreditController::class, 'agingReport']);
    Route::get('/customer/{customer}/summary', [CustomerCreditController::class, 'customerSummary']);
    Route::get('/{customerCreditTransaction}', [CustomerCreditController::class, 'show']);
    Route::put('/{customerCreditTransaction}', [CustomerCreditController::class, 'update']);
    Route::delete('/{customerCreditTransaction}', [CustomerCreditController::class, 'destroy']);
     });
    //  customer point routes
    Route::prefix('customers/points')->group(function () {
    Route::get('/', [CustomerPointController::class, 'index']);
    Route::post('/', [CustomerPointController::class, 'store']);
    Route::post('/calculate-earned', [CustomerPointController::class, 'calculateEarnedPoints']);
    Route::post('/calculate-redemption', [CustomerPointController::class, 'calculateRedemptionValue']);
    Route::post('/expire', [CustomerPointController::class, 'expirePoints']);
    Route::get('/customer/{customer}/summary', [CustomerPointController::class, 'customerSummary']);
    Route::get('/{customerPoint}', [CustomerPointController::class, 'show']);
    Route::put('/{customerPoint}', [CustomerPointController::class, 'update']);
    Route::delete('/{customerPoint}', [CustomerPointController::class, 'destroy']);
    });
    // Customer Segments routes
      Route::prefix('customers/segments')->group(function () {
          Route::get('/', [CustomerSegmentController::class, 'index']);
          Route::post('/', [CustomerSegmentController::class, 'store']);
          Route::get('/{customerSegment}', [CustomerSegmentController::class, 'show']);
          Route::put('/{customerSegment}', [CustomerSegmentController::class, 'update']);
          Route::delete('/{customerSegment}', [CustomerSegmentController::class, 'destroy']);
          Route::patch('/{customerSegment}/toggle-status', [CustomerSegmentController::class, 'toggleStatus']);
          Route::post('/{customerSegment}/assign-customer', [CustomerSegmentController::class, 'assignCustomer']);
          Route::post('/{customerSegment}/remove-customer', [CustomerSegmentController::class, 'removeCustomer']);
          Route::post('/{customerSegment}/evaluate-and-assign', [CustomerSegmentController::class, 'evaluateAndAssign']);
          Route::get('/{customerSegment}/statistics', [CustomerSegmentController::class, 'statistics']);
      });
       // Customer Management routes
       Route::prefix('customers')->group(function () {
           Route::get('/', [CustomerController::class, 'index']);
           Route::post('/', [CustomerController::class, 'store']);
           Route::get('/search', [CustomerController::class, 'search']);
           Route::get('/statistics', [CustomerController::class, 'statistics']);
           Route::get('/{customer}', [CustomerController::class, 'show']);
           Route::put('/{customer}', [CustomerController::class, 'update']);
           Route::delete('/{customer}', [CustomerController::class, 'destroy']);
           Route::patch('/{customer}/toggle-status', [CustomerController::class, 'toggleStatus']);
       });
         // gift card controller endpoints
      Route::prefix('gift-cards')->group(function () {
      Route::get('/', [GiftCardController::class, 'index']);
      Route::post('/', [GiftCardController::class, 'store']);
      Route::post('/check-balance', [GiftCardController::class, 'checkBalance']);
      Route::post('/use', [GiftCardController::class, 'useCard']);
      Route::post('/refund', [GiftCardController::class, 'refund']);
      Route::get('/{giftCard}', [GiftCardController::class, 'show']);
      Route::get('/{giftCard}/transactions', [GiftCardController::class, 'transactions']);
      Route::put('/{giftCard}', [GiftCardController::class, 'update']);
      Route::delete('/{giftCard}', [GiftCardController::class, 'destroy']);
     });

   Route::prefix('payment-methods')->group(function () {
    Route::get('/', [PaymentMethodController::class, 'index']);
    Route::post('/', [PaymentMethodController::class, 'store']);
    Route::post('/reorder', [PaymentMethodController::class, 'reorder']);
    Route::get('/{paymentMethod}', [PaymentMethodController::class, 'show']);
    Route::post('/{paymentMethod}/calculate-fee', [PaymentMethodController::class, 'calculateFee']);
    Route::put('/{paymentMethod}', [PaymentMethodController::class, 'update']);
    Route::delete('/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
    Route::patch('/{paymentMethod}/toggle-status', [PaymentMethodController::class, 'toggleStatus']);
  });
  Route::prefix('payments')->group(function () {
    Route::get('/', [PaymentController::class, 'index']);
    Route::post('/', [PaymentController::class, 'store']);
    Route::get('/unreconciled', [PaymentController::class, 'unreconciled']);
    Route::get('/failed', [PaymentController::class, 'failed']);
    Route::post('/refund', [PaymentController::class, 'refund']);
    Route::get('/{payment}', [PaymentController::class, 'show']);
    Route::put('/{payment}', [PaymentController::class, 'update']);
    Route::delete('/{payment}', [PaymentController::class, 'destroy']);
    Route::post('/{payment}/reconcile', [PaymentController::class, 'reconcile']);
    Route::post('/{payment}/mark-failed', [PaymentController::class, 'markAsFailed']);
  });
//   cash reconciliation routes
  Route::prefix('cash-reconciliations')->group(function () {
    Route::get('/', [CashReconciliationController::class, 'index']);
    Route::post('/', [CashReconciliationController::class, 'store']);
    Route::get('/variance-report', [CashReconciliationController::class, 'varianceReport']);
    Route::get('/{cashReconciliation}', [CashReconciliationController::class, 'show']);
    Route::get('/{cashReconciliation}/movements', [CashReconciliationController::class, 'getCashMovements']);
    Route::put('/{cashReconciliation}', [CashReconciliationController::class, 'update']);
    Route::delete('/{cashReconciliation}', [CashReconciliationController::class, 'destroy']);
    Route::post('/{cashReconciliation}/complete', [CashReconciliationController::class, 'complete']);
    Route::post('/{cashReconciliation}/approve', [CashReconciliationController::class, 'approve']);
    Route::post('/{cashReconciliation}/dispute', [CashReconciliationController::class, 'dispute']);
    Route::post('/{cashReconciliation}/movements', [CashReconciliationController::class, 'recordCashMovement']);
   });
   Route::prefix('cash-movements')->group(function () {
    Route::get('/', [CashMovementController::class, 'index']);
    Route::post('/', [CashMovementController::class, 'store']);
    Route::get('/summary', [CashMovementController::class, 'summary']);
    Route::post('/cash-drop', [CashMovementController::class, 'recordCashDrop']);
    Route::post('/opening-float', [CashMovementController::class, 'recordOpeningFloat']);
    Route::post('/expense', [CashMovementController::class, 'recordExpense']);
    Route::get('/{cashMovement}', [CashMovementController::class, 'show']);
    Route::put('/{cashMovement}', [CashMovementController::class, 'update']);
    Route::delete('/{cashMovement}', [CashMovementController::class, 'destroy']);
  });
  Route::prefix('sales')->group(function () {
    Route::get('/', [SaleController::class, 'index']);
    Route::post('/', [SaleController::class, 'store']);
    Route::post('/calculate-totals', [SaleController::class, 'calculateTotals']);
    Route::post('/hold', [SaleController::class, 'hold']);
    Route::get('/held', [SaleController::class, 'getHeldSales']);
    Route::post('/recall-held/{heldSale}', [SaleController::class, 'recallHeld']);
    Route::get('/{sale}', [SaleController::class, 'show']);
    Route::post('/{sale}/cancel', [SaleController::class, 'cancel']);
   });
   Route::prefix('returns')->group(function () {
    Route::get('/', [ReturnController::class, 'index']);
    Route::post('/', [ReturnController::class, 'store']);
    Route::get('/search-sale', [ReturnController::class, 'searchOriginalSale']);
    Route::get('/{returnTransaction}', [ReturnController::class, 'show']);
   });
   Route::prefix('discounts')->group(function () {
    Route::get('/', [DiscountController::class, 'index']);
    Route::post('/', [DiscountController::class, 'store']);
    Route::post('/validate', [DiscountController::class, 'validate']);
    Route::get('/{discount}', [DiscountController::class, 'show']);
    Route::put('/{discount}', [DiscountController::class, 'update']);
    Route::delete('/{discount}', [DiscountController::class, 'destroy']);
    });
    Route::prefix('cash-register')->group(function () {
        Route::post('/open', [CashRegisterController::class, 'open']);
        Route::post('/close', [CashRegisterController::class, 'close']);
        Route::get('/current-session', [CashRegisterController::class, 'currentSession']);
        Route::post('/cash-drop', [CashRegisterController::class, 'cashDrop']);
        Route::get('/sessions', [CashRegisterController::class, 'index']);
        Route::get('/sessions/{cashReconciliation}', [CashRegisterController::class, 'show']);
    });
    Route::get('/receipts/{sale}', [ReceiptController::class, 'generate']);
    Route::prefix('purchases')->group(function () {
    Route::get('/', [PurchaseController::class, 'index']);
    Route::post('/', [PurchaseController::class, 'store']);
    Route::get('/supplier/{supplier}', [PurchaseController::class, 'supplierPurchases']);
    Route::post('/{purchase}/receive', [PurchaseController::class, 'receive']);
    Route::post('/{purchase}/cancel', [PurchaseController::class, 'cancel']);
    Route::get('/{purchase}', [PurchaseController::class, 'show']);
    Route::put('/{purchase}', [PurchaseController::class, 'update']);
    Route::delete('/{purchase}', [PurchaseController::class, 'destroy']);
     });
     Route::prefix('currencies')->group(function () {
    Route::get('/', [CurrencyController::class, 'index']);
    Route::post('/', [CurrencyController::class, 'store']);
    Route::get('/base', [CurrencyController::class, 'getBaseCurrency']);
    Route::get('/{currency}', [CurrencyController::class, 'show']);
    Route::put('/{currency}', [CurrencyController::class, 'update']);
    Route::delete('/{currency}', [CurrencyController::class, 'destroy']);
    Route::post('/{currency}/toggle-status', [CurrencyController::class, 'toggleStatus']);
   });

    // Exchange Rate Source routes
    Route::prefix('exchange-rate-sources')->group(function () {
        Route::get('/', [ExchangeRateSourceController::class, 'index']);
        Route::post('/', [ExchangeRateSourceController::class, 'store']);
        Route::get('/{exchangeRateSource}', [ExchangeRateSourceController::class, 'show']);
        Route::put('/{exchangeRateSource}', [ExchangeRateSourceController::class, 'update']);
        Route::delete('/{exchangeRateSource}', [ExchangeRateSourceController::class, 'destroy']);
    });
    Route::prefix('exchange-rates')->group(function () {
    Route::get('/', [ExchangeRateController::class, 'index']);
    Route::post('/', [ExchangeRateController::class, 'store']);
    Route::get('/current', [ExchangeRateController::class, 'getCurrentRates']);
    Route::get('/history', [ExchangeRateController::class, 'rateHistory']);
    Route::post('/convert', [ExchangeRateController::class, 'convertAmount']);
    Route::get('/sales-by-currency', [ExchangeRateController::class, 'salesByCurrency']);
    Route::get('/currency-summary', [ExchangeRateController::class, 'currencySummary']);
    Route::get('/{exchangeRate}', [ExchangeRateController::class, 'show']);
    Route::put('/{exchangeRate}', [ExchangeRateController::class, 'update']);
    Route::delete('/{exchangeRate}', [ExchangeRateController::class, 'destroy']);
    });
    // Revenue Streams Routes
    Route::prefix('revenue-streams')->group(function () {
        Route::get('/', [RevenueStreamController::class, 'index']);
        Route::post('/', [RevenueStreamController::class, 'store']);
        Route::get('/{revenueStream}', [RevenueStreamController::class, 'show']);
        Route::put('/{revenueStream}', [RevenueStreamController::class, 'update']);
        Route::delete('/{revenueStream}', [RevenueStreamController::class, 'destroy']);
        Route::patch('/{revenueStream}/toggle-status', [RevenueStreamController::class, 'toggleStatus']);
    });

    // Revenue Entries Routes
    Route::prefix('revenue-entries')->group(function () {
        Route::get('/', [RevenueEntryController::class, 'index']);
        Route::post('/', [RevenueEntryController::class, 'store']);
        Route::get('/analytics', [RevenueEntryController::class, 'analytics']);
        Route::get('/{revenueEntry}', [RevenueEntryController::class, 'show']);
        Route::put('/{revenueEntry}', [RevenueEntryController::class, 'update']);
        Route::delete('/{revenueEntry}', [RevenueEntryController::class, 'destroy']);
        Route::patch('/{revenueEntry}/approve', [RevenueEntryController::class, 'approve']);
        Route::patch('/{revenueEntry}/reject', [RevenueEntryController::class, 'reject']);
    });


});