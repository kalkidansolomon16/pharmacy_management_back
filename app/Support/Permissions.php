<?php

namespace App\Support;

use App\Models\User;

/**
 * Single source of truth for the role -> permission matrix (seeded into spatie/laravel-permission).
 */
final class Permissions
{
    public const MATRIX = [
        User::ROLE_SUPER_ADMIN => [
            'tenants.manage', 'catalog.manage', 'users.manage', 'reports.view', 'activity.view',
        ],
        User::ROLE_PHARMACY_ADMIN => [
            'users.manage', 'organization.manage', 'inventory.view', 'inventory.manage', 'sales.create',
            'orders.view', 'orders.manage', 'prescriptions.dispense', 'reports.view', 'activity.view',
        ],
        User::ROLE_STAFF => [
            'inventory.view', 'sales.create', 'orders.view', 'orders.manage', 'prescriptions.dispense',
        ],
        User::ROLE_HOSPITAL_ADMIN => [
            'users.manage', 'organization.manage', 'patients.view', 'patients.manage', 'prescriptions.view',
            'reports.view', 'activity.view',
        ],
        User::ROLE_DOCTOR => [
            'patients.view', 'patients.manage', 'prescriptions.view', 'prescriptions.create',
        ],
        User::ROLE_CUSTOMER => [
            'orders.place',
        ],
    ];

    public static function all(): array
    {
        return collect(self::MATRIX)->flatten()->unique()->values()->all();
    }
}
