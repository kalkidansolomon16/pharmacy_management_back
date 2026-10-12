<?php

namespace App\Support;

/**
 * Every permission, grouped the way the app's menus are, with plain-language labels.
 * Drives the Roles & permissions editor and decides who may grant what:
 *  - scope "platform":     only MedLink's platform team can hold or grant it
 *  - scope "organization": pharmacies / hospitals use it; their admins can put it in custom roles
 *  - scope "customer":     patients using the public site (never edited)
 * action = view | create | update | delete | other (shown as columns / ticks in the editor)
 * module = the organization must have that module switched on for the permission to matter
 */
final class PermissionCatalog
{
    public const GROUPS = [
        // ---------------- Platform ----------------
        'platform_organizations' => ['label' => 'Organizations', 'scope' => 'platform', 'permissions' => [
            'tenants.manage' => ['View, approve & suspend pharmacies and hospitals', 'other'],
        ]],
        'platform_catalog' => ['label' => 'Master medicine catalogue', 'scope' => 'platform', 'permissions' => [
            'catalog.manage' => ['Edit the master catalogue and review additions', 'other'],
        ]],
        'platform_billing' => ['label' => 'Plans, subscriptions & backups', 'scope' => 'platform', 'permissions' => [
            'platform.plans' => ['Manage plans and subscriptions', 'other'],
            'platform.backups' => ['Run and download database backups', 'other'],
        ]],

        // ---------------- Pharmacy ----------------
        'owner' => ['label' => 'Owner overview', 'scope' => 'organization', 'permissions' => [
            'owner.dashboard' => ['See the all-branches overview', 'view'],
        ]],
        'pos' => ['label' => 'Point of sale', 'scope' => 'organization', 'module' => 'pharmacy', 'permissions' => [
            'sales.create' => ['Sell at the counter', 'create'],
        ]],
        'orders' => ['label' => 'Online orders', 'scope' => 'organization', 'module' => 'pharmacy', 'permissions' => [
            'orders.view' => ['See online orders', 'view'],
            'orders.manage' => ['Confirm, prepare, complete or reject orders', 'update'],
        ]],
        'dispensing' => ['label' => 'e-Prescription inbox & dispensing', 'scope' => 'organization', 'module' => 'pharmacy', 'permissions' => [
            'prescriptions.dispense' => ['Look up and dispense prescriptions', 'other'],
        ]],
        'inventory' => ['label' => 'Inventory, expiry & stock movements', 'scope' => 'organization', 'module' => 'pharmacy', 'permissions' => [
            'inventory.view' => ['See stock, expiry and movements', 'view'],
            'inventory.create' => ['Add medicines to inventory', 'create'],
            'inventory.update' => ['Change prices, reorder levels & visibility', 'update'],
            'inventory.delete' => ['Remove medicines from inventory', 'delete'],
            'inventory.stock' => ['Receive stock & adjust batches', 'other'],
        ]],
        'catalog' => ['label' => 'Medicine catalogue', 'scope' => 'organization', 'permissions' => [
            'catalog.contribute' => ['Add medicines & categories (reviewed by MedLink)', 'create'],
        ]],
        'purchasing' => ['label' => 'Purchasing & suppliers', 'scope' => 'organization', 'module' => 'pharmacy', 'permissions' => [
            'purchasing.view' => ['See purchase orders and suppliers', 'view'],
            'purchasing.create' => ['Create purchase orders and suppliers', 'create'],
            'purchasing.update' => ['Send, receive or cancel orders; edit suppliers', 'update'],
            'returns.manage' => ['Return stock to suppliers', 'other'],
        ]],
        'stores' => ['label' => 'Stores & transfers', 'scope' => 'organization', 'module' => 'pharmacy', 'permissions' => [
            'stores.create' => ['Add stores', 'create'],
            'stores.update' => ['Edit stores', 'update'],
            'transfers.manage' => ['Request, dispatch and receive transfers', 'other'],
        ]],
        'controlled' => ['label' => 'Controlled drugs register', 'scope' => 'organization', 'module' => 'pharmacy', 'permissions' => [
            'controlled.view' => ['See and export the register', 'view'],
        ]],

        // ---------------- Hospital / clinic ----------------
        'patients' => ['label' => 'Patients', 'scope' => 'organization', 'module' => 'clinical', 'permissions' => [
            'patients.view' => ['See the patient register', 'view'],
            'patients.create' => ['Register patients', 'create'],
            'patients.update' => ['Edit patient details', 'update'],
        ]],
        'visits' => ['label' => 'Patient queue & visits', 'scope' => 'organization', 'module' => 'clinical', 'permissions' => [
            'visits.view' => ['See the patient queue', 'view'],
            'visits.manage' => ['Check in, assign and cancel visits', 'create'],
            'triage.record' => ['Record vital signs', 'other'],
            'consultations.manage' => ['Consult: notes, diagnoses, complete visits', 'other'],
        ]],
        'prescriptions' => ['label' => 'Prescriptions', 'scope' => 'organization', 'module' => 'clinical', 'permissions' => [
            'prescriptions.view' => ['See prescriptions', 'view'],
            'prescriptions.create' => ['Write prescriptions', 'create'],
        ]],
        'lab' => ['label' => 'Laboratory', 'scope' => 'organization', 'module' => 'lab', 'permissions' => [
            'lab.view' => ['See lab orders and results', 'view'],
            'lab.order' => ['Order lab tests', 'create'],
            'lab.result' => ['Collect samples and enter results', 'update'],
            'lab.verify' => ['Verify (release) results', 'other'],
            'lab.manage' => ['Edit the lab test menu and prices', 'other'],
        ]],

        // ---------------- Billing ----------------
        'cashier' => ['label' => 'Cashier & invoices', 'scope' => 'organization', 'module' => 'billing', 'permissions' => [
            'billing.view' => ['See invoices and payments', 'view'],
            'billing.collect' => ['Take payments', 'other'],
            'billing.create' => ['Create manual invoices', 'create'],
            'billing.void' => ['Void invoices (credit notes)', 'delete'],
        ]],
        'services' => ['label' => 'Service price list', 'scope' => 'organization', 'module' => 'billing', 'permissions' => [
            'services.create' => ['Add services and prices', 'create'],
            'services.update' => ['Edit services and prices', 'update'],
        ]],
        'insurance' => ['label' => 'Insurance, credit & claims', 'scope' => 'organization', 'module' => 'insurance', 'permissions' => [
            'insurance.view' => ['See payers, members and claims', 'view'],
            'insurance.create' => ['Add payers and enrol members', 'create'],
            'insurance.update' => ['Edit payers and members', 'update'],
            'insurance.claims' => ['Prepare claims, record payments, decide approvals', 'other'],
        ]],

        // ---------------- Management ----------------
        'reports' => ['label' => 'Reports', 'scope' => 'organization', 'permissions' => [
            'reports.view' => ['See and export reports', 'view'],
        ]],
        'team' => ['label' => 'Team', 'scope' => 'organization', 'permissions' => [
            'users.view' => ['See team members', 'view'],
            'users.create' => ['Add team members', 'create'],
            'users.update' => ['Edit team members and their roles', 'update'],
            'users.delete' => ['Deactivate team members', 'delete'],
            'roles.manage' => ['Manage roles & permissions', 'other'],
        ]],
        'activity' => ['label' => 'Audit log', 'scope' => 'organization', 'permissions' => [
            'activity.view' => ['See who did what', 'view'],
        ]],
        'organization' => ['label' => 'Organization, settings & subscription', 'scope' => 'organization', 'permissions' => [
            'organization.manage' => ['Edit profile, modules and settings', 'update'],
            'integrations.manage' => ['Create integration (FHIR) tokens', 'other'],
            'subscription.view' => ['See the subscription and plan usage', 'view'],
        ]],

        // ---------------- Patients on the public site ----------------
        'customer' => ['label' => 'Online ordering', 'scope' => 'customer', 'permissions' => [
            'orders.place' => ['Order medicines online', 'create'],
        ]],
    ];

    /**
     * Old all-in-one permissions and the specific ones that replace them. Everyone who had the old one
     * gets all of its replacements (see the 2026_10_10_000004 migration), so nobody loses access.
     */
    public const SPLITS = [
        'inventory.manage' => ['inventory.create', 'inventory.update', 'inventory.delete', 'inventory.stock', 'purchasing.view'],
        'patients.manage' => ['patients.create', 'patients.update', 'insurance.create'],
        'users.manage' => ['users.view', 'users.create', 'users.update', 'users.delete', 'roles.manage'],
        'stores.manage' => ['stores.create', 'stores.update'],
        'purchasing.manage' => ['purchasing.view', 'purchasing.create', 'purchasing.update'],
        'billing.manage' => ['billing.create', 'billing.void', 'services.create', 'services.update'],
        'services.manage' => ['services.create', 'services.update'],
        'insurance.manage' => ['insurance.create', 'insurance.update', 'insurance.claims'],
    ];

    /**
     * Permissions a role must keep, so nobody locks themselves (or every organization) out.
     */
    public const LOCKED = [
        \App\Models\User::ROLE_SUPER_ADMIN => ['tenants.manage', 'users.view', 'users.create', 'users.update', 'roles.manage', 'platform.plans'],
        \App\Models\User::ROLE_PHARMACY_ADMIN => ['users.view', 'users.create', 'users.update', 'roles.manage', 'organization.manage'],
        \App\Models\User::ROLE_HOSPITAL_ADMIN => ['users.view', 'users.create', 'users.update', 'roles.manage', 'organization.manage'],
    ];

    /** @return array<string, array{label:string, action:string, group:string, scope:string, module:?string}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::GROUPS as $key => $group) {
            foreach ($group['permissions'] as $name => [$label, $action]) {
                $out[$name] = ['label' => $label, 'action' => $action, 'group' => $key, 'scope' => $group['scope'], 'module' => $group['module'] ?? null];
            }
        }

        return $out;
    }

    public static function names(?string $scope = null): array
    {
        return array_keys(array_filter(self::all(), fn ($p) => $scope === null || $p['scope'] === $scope));
    }

    /** Organization permissions that matter for this organization's switched-on modules. */
    public static function forOrganization(\App\Models\Tenant $tenant): array
    {
        $modules = $tenant->enabledModules();

        return array_keys(array_filter(self::all(), fn ($p) => $p['scope'] === 'organization' && ($p['module'] === null || in_array($p['module'], $modules, true))));
    }
}
