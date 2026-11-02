<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BarcodeController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomFieldController;
use App\Http\Controllers\Api\V1\DailyClosingController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\HardwareProfileController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\PriceListController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseReturnController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SaleCancellationController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleReturnController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockReconciliationController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\SyncConflictController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\TemplateUpdateController;
use App\Http\Controllers\Api\V1\TenantConfigController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public - no tenant/auth context exists yet when a load balancer or
    // uptime monitor probes this (section 10 "Platform" endpoint group,
    // section 12 Phase 8 "monitoring + alerting").
    Route::get('/health', [HealthController::class, 'show']);

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:20,1');
    Route::post('/auth/2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:20,1');

    Route::middleware('tenant.auth')->group(function () {
        // section 9/13.2: a suspended/expired tenant's offline sales must still be
        // STORED for review, never discarded - so /sync/push is deliberately outside
        // the `tenant.active` gate below (SyncPushService itself holds them instead of
        // processing). Every other endpoint, including /sync/pull, stays gated.
        Route::post('/sync/push', [SyncController::class, 'push'])->middleware('permission:pos.sales.create');

        Route::middleware('tenant.active')->group(function () {
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            Route::get('/auth/me', [AuthController::class, 'me']);
            Route::post('/auth/2fa/setup', [AuthController::class, 'setupTwoFactor']);
            Route::post('/auth/2fa/confirm', [AuthController::class, 'confirmTwoFactor']);
            Route::post('/auth/2fa/disable', [AuthController::class, 'disableTwoFactor']);
            Route::get('/tenant/config', [TenantConfigController::class, 'show']);

            Route::post('/sales', [SaleController::class, 'store'])->middleware(['permission:pos.sales.create', 'idempotent']);
            Route::get('/sales', [SaleController::class, 'index'])->middleware('permission:pos.sales.create');
            Route::get('/sales/{sale}', [SaleController::class, 'show'])->middleware('permission:pos.sales.create');

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
            Route::get('/templates', [TemplateController::class, 'index'])->middleware('permission:pos.sales.create');
            Route::get('/templates/{id}', [TemplateController::class, 'show'])->middleware('permission:pos.sales.create');
            Route::get('/custom-fields', [CustomFieldController::class, 'index'])->middleware('permission:pos.sales.create');
            Route::post('/custom-fields', [CustomFieldController::class, 'store'])->middleware('permission:core.settings.manage');

            Route::get('/hardware-profiles', [HardwareProfileController::class, 'index'])->middleware('permission:core.branches.manage');
            Route::post('/hardware-profiles', [HardwareProfileController::class, 'store'])->middleware('permission:core.branches.manage');

            Route::get('/sync/pull', [SyncController::class, 'pull'])->middleware('permission:pos.sales.create');
            Route::get('/sync/operations', [SyncController::class, 'operations'])->middleware('permission:core.audit.view');

            Route::get('/sync/conflicts', [SyncConflictController::class, 'index'])->middleware('permission:core.audit.view');
            Route::post('/sync/conflicts/{conflict}/resolve', [SyncConflictController::class, 'resolve'])->middleware('permission:core.audit.view');

            Route::get('/devices', [DeviceController::class, 'index'])->middleware('permission:core.users.manage');
            Route::post('/devices/{device}/revoke', [DeviceController::class, 'revoke'])->middleware('permission:core.users.manage');

            // POS screen support - read-only product/customer search and shift
            // open/close, none of which had an HTTP route before (ProductService/
            // ShiftService/Customer model all existed service-only since Phases 2-3).
            Route::get('/products', [ProductController::class, 'index'])->middleware('permission:pos.sales.create');
            Route::post('/products', [ProductController::class, 'store'])->middleware('permission:catalog.products.manage');

            Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:pos.sales.create');
            Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:customers.manage');
            Route::get('/customers/{id}/ledger', [CustomerController::class, 'ledger'])->middleware('permission:customers.manage');

            Route::get('/inventory/balances', [InventoryController::class, 'balances'])->middleware('permission:pos.sales.create');
            Route::get('/inventory/cost-layers', [InventoryController::class, 'costLayers'])->middleware('permission:pos.sales.create');
            Route::get('/reports/summary', [ReportController::class, 'summary'])->middleware('permission:reports.view');

            Route::get('/shifts/current', [ShiftController::class, 'current'])->middleware('permission:pos.shift.open');
            Route::post('/shifts/open', [ShiftController::class, 'open'])->middleware('permission:pos.shift.open');
            Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])->middleware('permission:pos.shift.close');

            Route::get('/stock-transfers', [StockTransferController::class, 'index'])->middleware('permission:inventory.transfer');
            Route::post('/stock-transfers', [StockTransferController::class, 'store'])->middleware('permission:inventory.transfer');
            Route::get('/stock-transfers/{id}', [StockTransferController::class, 'show'])->middleware('permission:inventory.transfer');
            Route::post('/stock-transfers/{id}/dispatch', [StockTransferController::class, 'dispatch'])->middleware('permission:inventory.transfer');
            Route::post('/stock-transfers/{id}/receive', [StockTransferController::class, 'receive'])->middleware('permission:inventory.transfer');
            Route::post('/stock-transfers/{id}/cancel', [StockTransferController::class, 'cancel'])->middleware('permission:inventory.transfer');

            Route::get('/stock-reconciliations', [StockReconciliationController::class, 'index'])->middleware('permission:inventory.adjust');
            Route::post('/stock-reconciliations', [StockReconciliationController::class, 'store'])->middleware('permission:inventory.adjust');
            Route::get('/stock-reconciliations/{id}', [StockReconciliationController::class, 'show'])->middleware('permission:inventory.adjust');
            Route::post('/stock-reconciliations/{id}/submit-counts', [StockReconciliationController::class, 'submitCounts'])->middleware('permission:inventory.adjust');
            Route::post('/stock-reconciliations/{id}/complete', [StockReconciliationController::class, 'complete'])->middleware('permission:inventory.adjust');
            Route::post('/stock-reconciliations/{id}/cancel', [StockReconciliationController::class, 'cancel'])->middleware('permission:inventory.adjust');
        });
    });
});
