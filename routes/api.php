<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\MedicineController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Customer\OrderController as CustomerOrderController;
use App\Http\Controllers\Hospital\PatientController;
use App\Http\Controllers\Hospital\PrescriptionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\Pharmacy\BatchController;
use App\Http\Controllers\Pharmacy\InventoryController;
use App\Http\Controllers\Pharmacy\OrderController as PharmacyOrderController;
use App\Http\Controllers\Pharmacy\PosController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\PublicContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API - no authentication
|--------------------------------------------------------------------------
*/
Route::prefix('public')->middleware([PublicContext::class, 'throttle:public'])->group(function () {
    Route::get('meta', [PublicController::class, 'meta']);
    Route::get('stats', [PublicController::class, 'stats']);
    Route::get('categories', [PublicController::class, 'categories']);
    Route::get('medicines', [PublicController::class, 'searchMedicines']);
    Route::get('pharmacies', [PublicController::class, 'pharmacies']);
    Route::get('pharmacies/{slug}', [PublicController::class, 'pharmacy']);
    Route::get('pharmacies/{slug}/medicines', [PublicController::class, 'pharmacyMedicines']);
    Route::post('prescriptions/verify', [PublicController::class, 'verifyPrescription'])->middleware('throttle:prescription-verify');
});

Route::prefix('auth')->middleware('throttle:login')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('register-organization', [AuthController::class, 'registerOrganization']);
});

/*
|--------------------------------------------------------------------------
| Authenticated API
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    // Available even while an organization awaits approval
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::put('auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('auth/password', [AuthController::class, 'changePassword']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);

    Route::middleware('active')->group(function () {
        Route::get('dashboard', [ReportController::class, 'dashboard']);

        // Shared catalogue (read for all; write = super admin, enforced by policies)
        Route::apiResource('medicines', MedicineController::class);
        Route::apiResource('categories', CategoryController::class)->except('show');

        // Team & organization
        Route::apiResource('users', UserController::class)->except('show');
        Route::get('organization', [OrganizationController::class, 'show']);
        Route::put('organization', [OrganizationController::class, 'update']);
        Route::get('activity-logs', [ActivityLogController::class, 'index']);

        // Reports
        Route::get('reports/sales', [ReportController::class, 'sales']);
        Route::get('reports/inventory', [ReportController::class, 'inventory']);
        Route::get('reports/prescriptions', [ReportController::class, 'prescriptions']);
        Route::get('reports/{report}/export', [ReportController::class, 'export'])->whereIn('report', ['sales', 'inventory', 'prescriptions']);

        // Platform administration
        Route::prefix('admin')->middleware('role:super_admin')->group(function () {
            Route::get('tenants', [TenantController::class, 'index']);
            Route::get('tenants/{tenant}', [TenantController::class, 'show']);
            Route::put('tenants/{tenant}', [TenantController::class, 'update']);
            Route::patch('tenants/{tenant}/status', [TenantController::class, 'updateStatus']);
        });

        // Customers
        Route::prefix('my')->group(function () {
            Route::get('orders', [CustomerOrderController::class, 'index']);
            Route::post('orders', [CustomerOrderController::class, 'store']);
            Route::get('orders/{order}', [CustomerOrderController::class, 'show']);
            Route::post('orders/{order}/cancel', [CustomerOrderController::class, 'cancel']);
        });

        // Pharmacy workspace
        Route::middleware('tenant.type:pharmacy')->group(function () {
            Route::get('inventory/alerts', [InventoryController::class, 'alerts']);
            Route::post('inventory/scan', [InventoryController::class, 'scan']);
            Route::apiResource('inventory', InventoryController::class)->parameters(['inventory' => 'listing']);
            Route::post('inventory/{listing}/batches', [BatchController::class, 'store']);
            Route::get('batches', [BatchController::class, 'index']);
            Route::post('batches/{batch}/adjust', [BatchController::class, 'adjust']);
            Route::get('stock-movements', [BatchController::class, 'movements']);

            Route::get('orders', [PharmacyOrderController::class, 'index']);
            Route::get('orders/{order}', [PharmacyOrderController::class, 'show']);
            Route::post('orders/{order}/confirm', [PharmacyOrderController::class, 'confirm']);
            Route::post('orders/{order}/ready', [PharmacyOrderController::class, 'ready']);
            Route::post('orders/{order}/complete', [PharmacyOrderController::class, 'complete']);
            Route::post('orders/{order}/reject', [PharmacyOrderController::class, 'reject']);
            Route::post('orders/{order}/cancel', [PharmacyOrderController::class, 'cancel']);

            Route::get('pos/products', [PosController::class, 'products']);
            Route::post('pos/sales', [PosController::class, 'sell']);
            Route::get('pos/prescriptions/{code}', [PosController::class, 'prescription']);
        });

        // Hospital workspace
        Route::middleware('tenant.type:hospital')->group(function () {
            Route::apiResource('patients', PatientController::class)->except('destroy');
            Route::get('prescriptions', [PrescriptionController::class, 'index']);
            Route::post('prescriptions', [PrescriptionController::class, 'store']);
            Route::get('prescriptions/{prescription}', [PrescriptionController::class, 'show']);
            Route::post('prescriptions/{prescription}/cancel', [PrescriptionController::class, 'cancel']);
        });
    });
});
