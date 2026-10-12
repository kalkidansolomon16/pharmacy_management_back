<?php

namespace App\Support;

use App\Models\User;

/**
 * Default permissions of the built-in roles (seeded into spatie/laravel-permission).
 * The platform team can change these defaults in Roles & permissions; organization admins build
 * their own custom roles from PermissionCatalog. Module middleware decides which areas an
 * organization can reach; permissions decide who inside it may do what.
 */
final class Permissions
{
    private const INVENTORY_FULL = ['inventory.view', 'inventory.create', 'inventory.update', 'inventory.delete', 'inventory.stock'];

    private const PHARMACY_OPERATIONS = [
        ...self::INVENTORY_FULL, 'sales.create', 'orders.view', 'orders.manage', 'prescriptions.dispense',
        'stores.create', 'stores.update', 'transfers.manage', 'returns.manage', 'controlled.view',
        'purchasing.view', 'purchasing.create', 'purchasing.update',
    ];

    private const FINANCE = [
        'billing.view', 'billing.collect', 'billing.create', 'billing.void', 'services.create', 'services.update',
        'insurance.view', 'insurance.create', 'insurance.update', 'insurance.claims',
    ];

    private const TEAM = ['users.view', 'users.create', 'users.update', 'users.delete', 'roles.manage'];

    private const ORGANIZATION = ['organization.manage', 'owner.dashboard', 'integrations.manage', 'subscription.view', 'reports.view', 'activity.view'];

    /**
     * Extra permissions an organization admin may give individual team members on top of their role
     * (only ones the admin holds themselves). Key = permission, value = label shown in the Team screen.
     */
    public const GRANTABLE = [
        'catalog.contribute' => 'Add medicines & categories to the catalogue',
    ];

    public const MATRIX = [
        User::ROLE_SUPER_ADMIN => [
            'tenants.manage', 'catalog.manage', 'platform.plans', 'platform.backups',
            ...self::TEAM, 'reports.view', 'activity.view',
        ],
        User::ROLE_PHARMACY_ADMIN => [
            ...self::TEAM, ...self::ORGANIZATION, ...self::PHARMACY_OPERATIONS, ...self::FINANCE,
            'catalog.contribute', // add medicines & categories (reviewed by the platform before they are shared)
        ],
        User::ROLE_STAFF => [
            'inventory.view', 'sales.create', 'orders.view', 'orders.manage', 'prescriptions.dispense',
            'transfers.manage', 'controlled.view', 'billing.view', 'billing.collect', 'insurance.view',
        ],
        User::ROLE_HOSPITAL_ADMIN => [
            ...self::TEAM, ...self::ORGANIZATION,
            'patients.view', 'patients.create', 'patients.update', 'prescriptions.view',
            'visits.view', 'visits.manage', 'lab.view', 'lab.manage', 'catalog.contribute',
            // the in-house pharmacy only works when the pharmacy module is on
            ...self::PHARMACY_OPERATIONS, ...self::FINANCE,
        ],
        User::ROLE_DOCTOR => [
            'patients.view', 'patients.create', 'patients.update', 'prescriptions.view', 'prescriptions.create',
            'visits.view', 'consultations.manage', 'triage.record', 'lab.view', 'lab.order',
        ],
        User::ROLE_CUSTOMER => [
            'orders.place',
        ],
        User::ROLE_NURSE => ['patients.view', 'visits.view', 'triage.record', 'lab.view'],
        User::ROLE_RECEPTIONIST => ['patients.view', 'patients.create', 'patients.update', 'visits.view', 'visits.manage', 'billing.view', 'insurance.view', 'insurance.create'],
        User::ROLE_LAB_TECHNICIAN => ['patients.view', 'lab.view', 'lab.result', 'lab.verify'],
        User::ROLE_CASHIER => ['patients.view', 'billing.view', 'billing.collect', 'insurance.view'],
        User::ROLE_OWNER => ['owner.dashboard', 'reports.view', 'activity.view', 'subscription.view'],
    ];

    public static function all(): array
    {
        return collect(self::MATRIX)->flatten()->merge(PermissionCatalog::names())->unique()->values()->all();
    }
}
