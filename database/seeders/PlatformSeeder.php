<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * SaaS price list (ETB / month). Safe to re-run in production.
 */
class PlatformSeeder extends Seeder
{
    public const PLANS = [
        ['pharmacy-basic', 'Pharmacy Basic', 'Single pharmacy: POS, FEFO inventory, online orders, e-invoices.', 1500, ['pharmacy', 'billing'], 5, 1, 2, 1],
        ['pharmacy-pro', 'Pharmacy Pro', 'Adds insurance & credit sales, purchasing, transfers, branches and owner dashboard.', 3500, ['pharmacy', 'billing', 'insurance'], 20, 3, 6, 2],
        ['clinic', 'Clinic', 'Reception, triage, consultation, lab, cashier, insurance claims and e-prescriptions.', 4500, ['clinical', 'lab', 'billing', 'insurance'], 25, 1, 3, 3],
        ['hospital-enterprise', 'Hospital Enterprise', 'Every module incl. in-house pharmacy stores, FHIR/DHIS2 integration and up to 20 locations.', 12000, ['clinical', 'lab', 'billing', 'insurance', 'pharmacy'], 250, 20, 30, 4],
    ];

    public function run(): void
    {
        foreach (self::PLANS as [$code, $name, $description, $price, $modules, $users, $branches, $stores, $sort]) {
            Plan::updateOrCreate(['code' => $code], [
                'name' => $name, 'description' => $description, 'price_monthly' => $price, 'trial_days' => 30, 'modules' => $modules,
                'max_users' => $users, 'max_branches' => $branches, 'max_stores' => $stores, 'is_active' => true, 'sort' => $sort,
            ]);
        }
    }
}
