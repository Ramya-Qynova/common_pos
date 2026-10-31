<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BarcodeController;
use App\Http\Controllers\Api\V1\DailyClosingController;
use App\Http\Controllers\Api\V1\HardwareProfileController;
use App\Http\Controllers\Api\V1\PriceListController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseReturnController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SaleCancellationController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleReturnController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\TemplateUpdateController;
use App\Http\Controllers\Api\V1\TenantConfigController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:20,1');

    Route::middleware(['tenant.auth', 'tenant.active'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/tenant/config', [TenantConfigController::class, 'show']);

        Route::post('/sales', [SaleController::class, 'store'])->middleware(['permission:pos.sales.create', 'idempotent']);

        // Gating for cancel is inside SaleCancellationService itself (same
        // APPROVAL_REQUIRED pattern SaleService uses for discounts), so no
        // `permission:` middleware here - the service decides.
        Route::post('/sales/{sale}/cancel', [SaleCancellationController::class, 'store']);

        Route::post('/sales/{sale}/returns', [SaleReturnController::class, 'store'])->middleware('permission:pos.sales.return');
        Route::post('/returns/{return}/inspect', [SaleReturnController::class, 'inspect'])->middleware('permission:pos.sales.return');
        Route::post('/returns/{return}/complete', [SaleReturnController::class, 'complete'])->middleware('permission:pos.sales.return');

        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchases.manage');
        Route::post('/purchase-orders/{purchaseOrder}/order', [PurchaseOrderController::class, 'markOrdered'])->middleware('permission:purchases.manage');
        Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('permission:purchases.manage');
        Route::post('/purchases/{purchase}/returns', [PurchaseReturnController::class, 'store'])->middleware('permission:purchases.manage');

        Route::post('/daily-closings/close', [DailyClosingController::class, 'close'])->middleware('permission:finance.daily_close');
        Route::post('/daily-closings/{dailyClosing}/reopen', [DailyClosingController::class, 'reopen'])->middleware('permission:finance.reopen');

        Route::get('/reports', [ReportController::class, 'show'])->middleware('permission:reports.view');

        // No `module:weighing` gate here - plain fixed-barcode lookup must work for every
        // tenant; VariableWeightBarcodeService itself checks the module before attempting
        // to decode a variable-weight barcode (see its own docblock).
        Route::get('/barcodes/resolve', [BarcodeController::class, 'resolve'])->middleware('permission:pos.sales.create');

        Route::post('/price-lists/{priceList}/bulk-update', [PriceListController::class, 'bulkUpdate'])->middleware('permission:catalog.products.manage');

        Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])->middleware('permission:inventory.adjust');

        Route::get('/template/update-preview', [TemplateUpdateController::class, 'preview'])->middleware('permission:core.settings.manage');
        Route::post('/template/update', [TemplateUpdateController::class, 'apply'])->middleware('permission:core.settings.manage');

        Route::get('/hardware-profiles', [HardwareProfileController::class, 'index'])->middleware('permission:core.branches.manage');
        Route::post('/hardware-profiles', [HardwareProfileController::class, 'store'])->middleware('permission:core.branches.manage');
    });
});
