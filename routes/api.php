<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\MedicineController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Billing\AuthorizationController;
use App\Http\Controllers\Billing\ClaimController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\MembershipController;
use App\Http\Controllers\Billing\PayerController;
use App\Http\Controllers\Billing\ReceivablesController;
use App\Http\Controllers\Billing\ServiceController;
use App\Http\Controllers\Clinical\LabOrderController;
use App\Http\Controllers\Clinical\LabTestController;
use App\Http\Controllers\Clinical\MedicineAvailabilityController;
use App\Http\Controllers\Clinical\VisitController;
use App\Http\Controllers\Customer\OrderController as CustomerOrderController;
use App\Http\Controllers\Hospital\PatientController;
use App\Http\Controllers\Hospital\PrescriptionController;
use App\Http\Controllers\Interop\FhirController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\Pharmacy\BatchController;
use App\Http\Controllers\Pharmacy\ControlledRegisterController;
use App\Http\Controllers\Pharmacy\InventoryController;
use App\Http\Controllers\Pharmacy\OrderController as PharmacyOrderController;
use App\Http\Controllers\Pharmacy\PosController;
use App\Http\Controllers\Pharmacy\PurchaseOrderController;
use App\Http\Controllers\Pharmacy\RxInboxController;
use App\Http\Controllers\Pharmacy\ScanController;
use App\Http\Controllers\Pharmacy\StoreController;
use App\Http\Controllers\Pharmacy\SupplierController;
use App\Http\Controllers\Pharmacy\SupplierReturnController;
use App\Http\Controllers\Pharmacy\TransferController;
use App\Http\Controllers\Platform\BackupController;
use App\Http\Controllers\Platform\BranchController;
use App\Http\Controllers\Platform\HelpController;
use App\Http\Controllers\Platform\IntegrationController;
use App\Http\Controllers\Platform\OrganizationSettingsController;
use App\Http\Controllers\Platform\OwnerController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\SessionController;
use App\Http\Controllers\Platform\SubscriptionAdminController;
use App\Http\Controllers\Platform\SubscriptionController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
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
    Route::get('pharmacies/nearby', [PublicController::class, 'nearbyPharmacies']); // before {slug}
    Route::get('facilities/nearby', [PublicController::class, 'nearbyFacilities']); // pharmacies + hospitals & clinics
    Route::get('pharmacies/{slug}', [PublicController::class, 'pharmacy']);
    Route::get('pharmacies/{slug}/medicines', [PublicController::class, 'pharmacyMedicines']);
    Route::post('prescriptions/verify', [PublicController::class, 'verifyPrescription'])->middleware('throttle:prescription-verify');
    Route::get('plans', [PlanController::class, 'index']); // pricing page
});

Route::prefix('auth')->middleware('throttle:login')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('register-organization', [AuthController::class, 'registerOrganization']);
});

/*
|--------------------------------------------------------------------------
| HL7 FHIR R4 (read-only) - staff sessions or integration tokens with fhir:read
|--------------------------------------------------------------------------
*/
Route::prefix('fhir')->middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::get('metadata', [FhirController::class, 'metadata']);
    Route::get('Patient', [FhirController::class, 'patients']);
    Route::get('Patient/{patient}', [FhirController::class, 'patient']);
    Route::get('Encounter', [FhirController::class, 'encounters']);
    Route::get('Observation', [FhirController::class, 'observations']);
    Route::get('MedicationRequest', [FhirController::class, 'medicationRequests']);
    Route::get('Medication/{medicine}', [FhirController::class, 'medication']);
});

/*
|--------------------------------------------------------------------------
| Authenticated API
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'full.token'])->group(function () {
    // Available even while an organization awaits approval
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::put('auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('auth/password', [AuthController::class, 'changePassword']);
    Route::get('auth/sessions', [SessionController::class, 'index']);
    Route::delete('auth/sessions/{id}', [SessionController::class, 'destroy']);
    // A rejected organization fixes its details and asks for review again
    Route::post('organization/resubmit', [OrganizationController::class, 'resubmit']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);

    Route::get('help', HelpController::class);
    Route::get('subscription', [SubscriptionController::class, 'show']);

    Route::middleware(['active', 'subscription'])->group(function () {
        Route::get('dashboard', [ReportController::class, 'dashboard']);

        // Shared catalogue (read for all; write = super admin, enforced by policies)
        Route::apiResource('medicines', MedicineController::class);
        Route::apiResource('categories', CategoryController::class)->except('show');
        Route::get('medicines/{medicine}/availability', MedicineAvailabilityController::class);

        // Team & organization
        Route::apiResource('users', UserController::class)->except('show');
        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);
        Route::get('organization', [OrganizationController::class, 'show']);
        Route::put('organization', [OrganizationController::class, 'update']);
        Route::get('organization/settings', [OrganizationSettingsController::class, 'show']);
        Route::put('organization/settings', [OrganizationSettingsController::class, 'update']);
        Route::get('organization/branches', [BranchController::class, 'index']);
        Route::post('organization/branches', [BranchController::class, 'store']);
        Route::get('activity-logs', [ActivityLogController::class, 'index']);
        Route::get('owner/dashboard', OwnerController::class);

        Route::get('integrations/tokens', [IntegrationController::class, 'index']);
        Route::post('integrations/tokens', [IntegrationController::class, 'store']);
        Route::delete('integrations/tokens/{id}', [IntegrationController::class, 'destroy']);

        // Reports
        Route::get('reports/sales', [ReportController::class, 'sales']);
        Route::get('reports/inventory', [ReportController::class, 'inventory']);
        Route::get('reports/prescriptions', [ReportController::class, 'prescriptions']);
        Route::get('reports/financial', [ReportController::class, 'financial']);
        Route::get('reports/dhis2', [ReportController::class, 'dhis2']);
        Route::get('reports/{report}/export', [ReportController::class, 'export'])->whereIn('report', ['sales', 'inventory', 'prescriptions', 'financial']);

        // Platform administration
        Route::prefix('admin')->middleware('role:super_admin')->group(function () {
            Route::get('tenants', [TenantController::class, 'index']);
            Route::get('tenants/{tenant}', [TenantController::class, 'show']);
            Route::put('tenants/{tenant}', [TenantController::class, 'update']);
            Route::patch('tenants/{tenant}/status', [TenantController::class, 'updateStatus']);
            Route::post('catalog/medicines/{medicine}/review', [MedicineController::class, 'review']);
            Route::post('catalog/categories/{category}/review', [CategoryController::class, 'review']);

            Route::get('plans', [PlanController::class, 'index']);
            Route::post('plans', [PlanController::class, 'store']);
            Route::put('plans/{plan}', [PlanController::class, 'update']);
            Route::get('subscriptions', [SubscriptionAdminController::class, 'index']);
            Route::post('subscriptions', [SubscriptionAdminController::class, 'store']);
            Route::put('subscriptions/{subscription}', [SubscriptionAdminController::class, 'update']);
            Route::post('subscriptions/{subscription}/payments', [SubscriptionAdminController::class, 'pay']);
            Route::get('backups', [BackupController::class, 'index']);
            Route::post('backups', [BackupController::class, 'store']);
            Route::get('backups/{file}', [BackupController::class, 'download'])->where('file', '[A-Za-z0-9_.\-]+');
        });

        // Customers
        Route::prefix('my')->group(function () {
            Route::get('orders', [CustomerOrderController::class, 'index']);
            Route::post('orders', [CustomerOrderController::class, 'store']);
            Route::get('orders/{order}', [CustomerOrderController::class, 'show']);
            Route::post('orders/{order}/cancel', [CustomerOrderController::class, 'cancel']);
        });

        // Pharmacy workspace (pharmacies, and hospitals with an in-house pharmacy)
        Route::middleware('module:pharmacy')->group(function () {
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

            // Supply chain additions
            Route::get('scan', ScanController::class);
            Route::get('rx-inbox', RxInboxController::class);
            Route::get('stores', [StoreController::class, 'index']);
            Route::post('stores', [StoreController::class, 'store']);
            Route::put('stores/{store}', [StoreController::class, 'update']);
            Route::get('suppliers', [SupplierController::class, 'index']);
            Route::post('suppliers', [SupplierController::class, 'store']);
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::get('purchase-orders/suggestions', [PurchaseOrderController::class, 'suggestions']);
            Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
            Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
            Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
            Route::post('purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send']);
            Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);
            Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
            Route::get('transfers', [TransferController::class, 'index']);
            Route::post('transfers', [TransferController::class, 'store']);
            Route::get('transfers/{transfer}', [TransferController::class, 'show']);
            Route::post('transfers/{transfer}/dispatch', [TransferController::class, 'dispatch']);
            Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive']);
            Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel']);
            Route::get('supplier-returns', [SupplierReturnController::class, 'index']);
            Route::post('supplier-returns', [SupplierReturnController::class, 'store']);
            Route::post('supplier-returns/{return}/resolve', [SupplierReturnController::class, 'resolve']);
            Route::get('controlled-register', [ControlledRegisterController::class, 'index']);
            Route::get('controlled-register/{listing}', [ControlledRegisterController::class, 'show']);
        });

        // Hospital / clinic workspace
        Route::middleware('module:clinical')->group(function () {
            Route::apiResource('patients', PatientController::class)->except('destroy');
            Route::get('prescriptions', [PrescriptionController::class, 'index']);
            Route::post('prescriptions', [PrescriptionController::class, 'store']);
            Route::get('prescriptions/{prescription}', [PrescriptionController::class, 'show']);
            Route::post('prescriptions/{prescription}/cancel', [PrescriptionController::class, 'cancel']);

            // Patient journey additions
            Route::get('visits/doctors', [VisitController::class, 'doctors']);
            Route::get('diagnoses', [VisitController::class, 'diagnoses']);
            Route::get('visits', [VisitController::class, 'index']);
            Route::post('visits', [VisitController::class, 'store']);
            Route::get('visits/{visit}', [VisitController::class, 'show']);
            Route::post('visits/{visit}/triage', [VisitController::class, 'triage']);
            Route::post('visits/{visit}/start', [VisitController::class, 'start']);
            Route::put('visits/{visit}/notes', [VisitController::class, 'notes']);
            Route::post('visits/{visit}/assign', [VisitController::class, 'assign']);
            Route::post('visits/{visit}/complete', [VisitController::class, 'complete']);
            Route::post('visits/{visit}/cancel', [VisitController::class, 'cancel']);
        });

        // Laboratory
        Route::middleware('module:lab')->group(function () {
            Route::get('lab-tests', [LabTestController::class, 'index']);
            Route::post('lab-tests', [LabTestController::class, 'store']);
            Route::put('lab-tests/{test}', [LabTestController::class, 'update']);
            Route::get('lab-orders', [LabOrderController::class, 'index']);
            Route::post('lab-orders', [LabOrderController::class, 'store']);
            Route::get('lab-orders/{order}', [LabOrderController::class, 'show']);
            Route::post('lab-orders/{order}/collect', [LabOrderController::class, 'collect']);
            Route::post('lab-orders/{order}/items/{item}/result', [LabOrderController::class, 'result']);
            Route::post('lab-orders/{order}/items/{item}/verify', [LabOrderController::class, 'verify']);
        });

        // Billing (cashier, e-invoices)
        Route::middleware('module:billing')->group(function () {
            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::post('invoices', [InvoiceController::class, 'store']);
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'pay']);
            Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);
            Route::post('invoices/{invoice}/fiscal', [InvoiceController::class, 'registerFiscal']);
            Route::get('services', [ServiceController::class, 'index']);
            Route::post('services', [ServiceController::class, 'store']);
            Route::put('services/{service}', [ServiceController::class, 'update']);
        });

        // Insurance & credit
        Route::middleware('module:insurance')->group(function () {
            Route::get('payers', [PayerController::class, 'index']);
            Route::post('payers', [PayerController::class, 'store']);
            Route::put('payers/{payer}', [PayerController::class, 'update']);
            Route::get('memberships', [MembershipController::class, 'index']);
            Route::post('memberships', [MembershipController::class, 'store']);
            Route::post('memberships/verify', [MembershipController::class, 'verify']);
            Route::put('memberships/{membership}', [MembershipController::class, 'update']);
            Route::get('claims', [ClaimController::class, 'index']);
            Route::post('claims', [ClaimController::class, 'store']);
            Route::get('claims/{claim}', [ClaimController::class, 'show']);
            Route::post('claims/{claim}/submit', [ClaimController::class, 'submit']);
            Route::post('claims/{claim}/payments', [ClaimController::class, 'pay']);
            Route::get('claims/{claim}/export', [ClaimController::class, 'export']);
            Route::get('authorizations', [AuthorizationController::class, 'index']);
            Route::post('authorizations/{authorization}/decide', [AuthorizationController::class, 'decide']);
            Route::get('receivables', ReceivablesController::class);
        });
    });
});
