<?php

namespace Database\Seeders;

use App\Exceptions\BusinessRuleException;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Patient;
use App\Models\Payer;
use App\Models\PayerMembership;
use App\Models\PharmacyMedicine;
use App\Models\Plan;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visit;
use App\Services\ActivityLogger;
use App\Services\Billing\BillingService;
use App\Services\Billing\InsuranceService;
use App\Services\Clinical\ClinicalService;
use App\Services\Clinical\LabService;
use App\Services\Inventory\PurchaseService;
use App\Services\Inventory\StockService;
use App\Services\Inventory\SupplierReturnService;
use App\Services\Inventory\TransferService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Phase-2 demo data layered on top of DemoSeeder: stores, suppliers, purchasing, the hospital's
 * patient journey (reception → triage → doctor → lab → cashier → in-house pharmacy), insurance,
 * subscriptions and a multi-branch owner. Nothing from DemoSeeder is changed or removed.
 */
class OperationsDemoSeeder extends Seeder
{
    public function __construct(
        private StockService $stock,
        private PurchaseService $purchasing,
        private TransferService $transfers,
        private SupplierReturnService $returns,
        private ClinicalService $clinical,
        private LabService $lab,
        private BillingService $billing,
        private InsuranceService $insurance,
    ) {}

    public function run(): void
    {
        mt_srand(1142);
        config(['queue.default' => 'sync']);

        $bole = Tenant::where('name', 'Bole Medhanit Pharmacy')->firstOrFail();
        $entoto = Tenant::where('name', 'Entoto Hills General Hospital')->firstOrFail();

        ActivityLogger::muted(function () use ($bole, $entoto) {
            $this->subscriptions();
            $this->backfillStores();
            $this->pharmacySupplyChain($bole);
            $this->ownerAndBranch($bole);
            $this->hospitalSetup($entoto);
        });

        $this->hospitalPharmacy($entoto);
        $this->patientJourneys($entoto);
        $this->insuranceCycle($entoto);

        Auth::forgetUser();
    }

    private function subscriptions(): void
    {
        $plans = Plan::pluck('id', 'code');
        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            $code = $tenant->isHospital() ? 'hospital-enterprise' : 'pharmacy-pro';
            $trial = str_contains($tenant->name, 'Bahir Dar'); // one pharmacy still on its free trial, ending soon
            Subscription::updateOrCreate(['tenant_id' => $tenant->id], [
                'plan_id' => $plans[$code],
                'status' => $trial ? 'trial' : 'active',
                'trial_ends_at' => $trial ? now()->addDays(4) : null,
                'current_period_start' => $trial ? null : now()->subDays(12),
                'current_period_end' => $trial ? null : now()->addDays(18),
            ]);
        }
        foreach (Tenant::where('status', 'pending')->get() as $tenant) {
            Subscription::updateOrCreate(['tenant_id' => $tenant->id], [
                'plan_id' => $plans[$tenant->isHospital() ? 'clinic' : 'pharmacy-basic'],
                'status' => 'trial', 'trial_ends_at' => now()->addDays(config('medlink.trial_days')),
            ]);
        }

        // Taxpayer registration: pharmacies with large turnover are VAT-registered, the rest pay TOT
        Tenant::where('type', 'pharmacy')->where('status', 'active')->get()->each(fn (Tenant $t, $i) => $t->update([
            'tax_regime' => $i < 3 ? 'vat' : 'tot',
            'vat_number' => $i < 3 ? 'VAT-'.(48210 + $i * 17) : null,
        ]));
    }

    /** Batches created before stores existed belong to each organization's default store. */
    private function backfillStores(): void
    {
        foreach (MedicineBatch::withoutGlobalScopes()->whereNull('store_id')->distinct()->pluck('tenant_id') as $tenantId) {
            $store = Store::defaultFor($tenantId);
            MedicineBatch::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('store_id')->update(['store_id' => $store->id]);
            StockMovement::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('store_id')->update(['store_id' => $store->id]);
        }
    }

    private function suppliers(Tenant $tenant): array
    {
        $rows = [
            ['EPSS Addis Ababa Hub', 'epss', '0000030603', '+251115155555', 'Ato Getnet Alemu', 'Kality, Addis Ababa', 0],
            ['Cadila Pharmaceuticals Ethiopia', 'manufacturer', '0001234567', '+251114420000', 'Ms. Selam Tadesse', 'Gelan, Oromia', 30],
            ['Ethiopian Pharmaceuticals Manufacturing (EPHARM)', 'manufacturer', '0000045210', '+251113710100', 'Ato Bereket Haile', 'Addis Ababa', 30],
            ['Medisel Importers PLC', 'importer', '0045123987', '+251116634400', 'W/ro Rahel Yohannes', 'Bole, Addis Ababa', 45],
        ];

        return collect($rows)->map(fn ($r) => Supplier::create([
            'tenant_id' => $tenant->id, 'name' => $r[0], 'type' => $r[1], 'tin_number' => $r[2], 'phone' => $r[3],
            'contact_person' => $r[4], 'address' => $r[5], 'payment_terms_days' => $r[6], 'is_active' => true,
            'license_number' => 'EFDA/WS/'.mt_rand(1000, 9999),
        ]))->all();
    }

    private function pharmacySupplyChain(Tenant $bole): void
    {
        Auth::setUser($bole->users()->role(User::ROLE_PHARMACY_ADMIN)->first());
        [$epss, $cadila, , $importer] = $this->suppliers($bole);
        Store::create(['tenant_id' => $bole->id, 'name' => 'Back store', 'code' => 'BACK', 'type' => 'main', 'is_dispensing' => false, 'is_default' => false]);

        // Reorder suggestions turned into a purchase order to EPSS, half received with scanned batches
        $low = PharmacyMedicine::where('tenant_id', $bole->id)->with('medicine')->withSum('sellableBatches as stock', 'quantity')->get()
            ->sortBy('stock')->take(4)->values();
        $po = $this->purchasing->create($epss, $low->map(fn ($l) => [
            'pharmacy_medicine_id' => $l->id, 'quantity' => in_array($l->medicine->unit, ['tablet', 'capsule'], true) ? 500 : 30,
            'unit_cost' => round((float) $l->price * 0.68, 2),
        ])->all(), ['notes' => 'Monthly EPSS replenishment', 'expected_at' => today()->addDays(2)]);
        $this->purchasing->send($po);
        $first = $po->items()->first();
        $this->purchasing->receive($po, [[
            'item_id' => $first->id, 'quantity' => intdiv($first->quantity_ordered, 2), 'batch_number' => 'EPSS-'.now()->format('ymd').'-A',
            'expiry_date' => today()->addMonths(20)->toDateString(), 'gtin' => '0'.mt_rand(100000000, 999999999).'123',
        ]]);

        // A draft order to an importer waiting for approval
        $this->purchasing->create($importer, $low->slice(2)->map(fn ($l) => [
            'pharmacy_medicine_id' => $l->id, 'quantity' => 100, 'unit_cost' => round((float) $l->price * 0.75, 2),
        ])->values()->all(), ['notes' => 'Quote requested']);

        // A damaged batch going back to the manufacturer
        $batch = MedicineBatch::where('tenant_id', $bole->id)->sellable()->where('quantity', '>', 10)->first();
        if ($batch) {
            $this->returns->create($batch, 5, 'damaged', $cadila, 'Cartons crushed in transit');
        }
    }

    private function ownerAndBranch(Tenant $bole): void
    {
        $branch = Tenant::create([
            'parent_id' => $bole->id, 'name' => 'Bole Medhanit Pharmacy - CMC Branch', 'type' => 'pharmacy', 'status' => 'active',
            'license_number' => 'EFDA/RP/2455/2017', 'tin_number' => '1000234599', 'phone' => '+251116671234',
            'email' => 'cmc@pharmacy.et', 'region' => 'Addis Ababa', 'city' => 'Addis Ababa', 'sub_city' => 'Yeka', 'woreda' => '13',
            'address_line' => 'CMC road, near St. Michael church', 'latitude' => 9.0230, 'longitude' => 38.8440,
            'opening_hours' => 'Mon-Sun 8:00-22:00', 'delivery_available' => true, 'approved_at' => now()->subMonths(2),
            'tax_regime' => 'vat', 'vat_number' => 'VAT-48299',
            'description' => 'Second branch of Bole Medhanit Pharmacy serving CMC and Ayat.',
        ]);
        Subscription::create(['tenant_id' => $branch->id, 'plan_id' => Plan::where('code', 'pharmacy-pro')->value('id'), 'status' => 'active',
            'current_period_start' => now()->subDays(12), 'current_period_end' => now()->addDays(18)]);

        $this->user($branch, 'Selamawit Bekele', 'cmc.pharmacist@medlink.et', '+251911200077', User::ROLE_STAFF, 'PH-5199');
        $this->user($bole, 'Ato Girma Wolde', 'owner@medlink.et', '+251911200088', User::ROLE_OWNER);

        // A small opening stock and a few days of sales at the branch
        $staff = $branch->users()->first();
        Auth::setUser($staff);
        $orders = app(\App\Services\Orders\OrderService::class);
        $listings = PharmacyMedicine::withoutGlobalScopes()->where('tenant_id', $bole->id)->with('medicine')
            ->whereHas('medicine', fn ($m) => $m->where('prescription_required', false)->where('is_controlled', false))->take(15)->get();
        foreach ($listings as $l) {
            $new = PharmacyMedicine::create(['tenant_id' => $branch->id, 'medicine_id' => $l->medicine_id, 'price' => $l->price, 'reorder_level' => $l->reorder_level, 'is_public' => true]);
            $this->stock->receive($new, [
                'batch_number' => 'CMC-'.mt_rand(1000, 9999), 'quantity' => in_array($l->medicine->unit, ['tablet', 'capsule', 'sachet'], true) ? 300 : 25,
                'expiry_date' => today()->addMonths(mt_rand(6, 24))->toDateString(), 'purchase_price' => round((float) $l->price * 0.7, 2),
                'supplier' => 'EPSS (Ethiopian Pharmaceutical Supply Service)',
            ]);
        }
        $branchListings = PharmacyMedicine::where('tenant_id', $branch->id)->with('medicine')->get();
        for ($s = 0; $s < 12; $s++) {
            $l = $branchListings->random();
            $orders->sellWalkIn($staff, [
                'items' => [['pharmacy_medicine_id' => $l->id, 'quantity' => in_array($l->medicine->unit, ['tablet', 'capsule'], true) ? 10 : 1]],
                'payment_method' => collect(['cash', 'telebirr', 'mpesa', 'cbe_birr'])->random(),
                'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            ]);
        }
    }

    private function hospitalSetup(Tenant $entoto): void
    {
        $entoto->update([
            'modules' => ['clinical', 'lab', 'billing', 'insurance', 'pharmacy'],
            'tax_regime' => 'exempt', // health services are VAT-exempt; taxable items still carry their own category
            'settings' => ['payment_before_service' => true, 'registration_fee_on_check_in' => true, 'einvoice_enabled' => true, 'four_eyes_lab_verification' => false],
        ]);

        $this->user($entoto, 'Sr. Almaz Gebre', 'nurse@medlink.et', '+251912300011', User::ROLE_NURSE, 'EMA-RN-22014');
        $this->user($entoto, 'Bethel Tilahun', 'reception@medlink.et', '+251912300012', User::ROLE_RECEPTIONIST);
        $this->user($entoto, 'Mesfin Kebede', 'lab@medlink.et', '+251912300013', User::ROLE_LAB_TECHNICIAN, 'EMA-LT-07781');
        $this->user($entoto, 'Hirut Asrat', 'cashier@medlink.et', '+251912300014', User::ROLE_CASHIER);
        $this->user($entoto, 'Yohannes Desta', 'entoto.pharmacist@medlink.et', '+251912300015', User::ROLE_STAFF, 'PH-6120');

        foreach ([
            ['Main medical store', 'MAIN', 'main', false, false], ['OPD pharmacy', 'OPD', 'opd', true, true],
            ['Emergency pharmacy', 'EMG', 'emergency', true, false], ['Inpatient ward store', 'WARD', 'ward', true, false],
        ] as [$name, $code, $type, $dispensing, $default]) {
            Store::create(['tenant_id' => $entoto->id, 'name' => $name, 'code' => $code, 'type' => $type, 'is_dispensing' => $dispensing, 'is_default' => $default]);
        }

        foreach ([
            ['REG', 'Card / registration (new patient)', 'registration', 50], ['CONS-OPD', 'OPD consultation', 'consultation', 150],
            ['CONS-EMG', 'Emergency consultation', 'consultation', 250], ['CONS-FU', 'Follow-up visit', 'consultation', 80],
            ['CONS-ANC', 'Antenatal care visit', 'consultation', 100], ['PROC-DRS', 'Wound dressing', 'procedure', 120],
            ['PROC-INJ', 'Injection administration', 'procedure', 30], ['PROC-IV', 'IV cannulation & fluid administration', 'procedure', 200],
            ['PROC-SUT', 'Minor suturing', 'procedure', 350], ['PROC-ECG', 'ECG', 'procedure', 300],
            ['BED-GEN', 'General ward bed (per day)', 'bed', 400], ['CERT-MED', 'Medical certificate', 'other', 100],
        ] as [$code, $name, $category, $price]) {
            Service::create(['tenant_id' => $entoto->id, 'code' => $code, 'name' => $name, 'category' => $category, 'price' => $price, 'tax_category' => 'exempt', 'is_active' => true]);
        }

        // [code, name, category, specimen, unit, type, options, low, high, critLow, critHigh, refText, price, tat]
        foreach ([
            ['CBC', 'Complete blood count (WBC)', 'hematology', 'Whole blood (EDTA)', 'x10³/µL', 'numeric', null, 4, 11, 2, 30, null, 150, 60],
            ['HGB', 'Haemoglobin', 'hematology', 'Whole blood (EDTA)', 'g/dL', 'numeric', null, 12, 17.5, 7, 20, null, 60, 30],
            ['ESR', 'Erythrocyte sedimentation rate', 'hematology', 'Whole blood', 'mm/hr', 'numeric', null, 0, 20, null, null, null, 50, 60],
            ['BGRP', 'Blood group & Rh', 'hematology', 'Whole blood', null, 'select', ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], null, null, null, null, null, 80, 30],
            ['BF', 'Blood film for malaria', 'parasitology', 'Capillary blood', null, 'select', ['No haemoparasite seen', 'P. falciparum seen', 'P. vivax seen', 'Mixed infection'], null, null, null, null, 'No haemoparasite seen', 70, 45],
            ['RDT-MAL', 'Malaria rapid diagnostic test', 'parasitology', 'Capillary blood', null, 'positive_negative', null, null, null, null, null, 'Negative', 60, 20],
            ['STOOL', 'Stool examination (O/P)', 'parasitology', 'Stool', null, 'text', null, null, null, null, null, 'No ova or parasite seen', 50, 45],
            ['UA', 'Urinalysis', 'urinalysis', 'Urine', null, 'text', null, null, null, null, null, 'Within normal limits', 70, 30],
            ['WIDAL', 'Widal test', 'serology', 'Serum', null, 'positive_negative', null, null, null, null, null, 'Non-reactive (<1:80)', 90, 60],
            ['FBS', 'Fasting blood sugar', 'chemistry', 'Serum', 'mg/dL', 'numeric', null, 70, 110, 50, 400, null, 60, 30],
            ['RBS', 'Random blood sugar', 'chemistry', 'Capillary blood', 'mg/dL', 'numeric', null, 70, 140, 50, 400, null, 50, 15],
            ['CREA', 'Creatinine', 'chemistry', 'Serum', 'mg/dL', 'numeric', null, 0.6, 1.3, null, 5, null, 120, 120],
            ['ALT', 'ALT (SGPT)', 'chemistry', 'Serum', 'U/L', 'numeric', null, 7, 56, null, 500, null, 120, 120],
            ['HIV', 'HIV rapid test', 'serology', 'Whole blood', null, 'positive_negative', null, null, null, null, null, 'Non-reactive', 0, 30],
            ['HBSAG', 'HBsAg', 'serology', 'Serum', null, 'positive_negative', null, null, null, null, null, 'Negative', 100, 30],
            ['VDRL', 'VDRL', 'serology', 'Serum', null, 'positive_negative', null, null, null, null, null, 'Non-reactive', 80, 30],
            ['HPY', 'H. pylori stool antigen', 'serology', 'Stool', null, 'positive_negative', null, null, null, null, null, 'Negative', 150, 45],
            ['HCG', 'Urine HCG (pregnancy test)', 'serology', 'Urine', null, 'positive_negative', null, null, null, null, null, 'Negative', 50, 15],
        ] as [$code, $name, $cat, $specimen, $unit, $type, $options, $lo, $hi, $clo, $chi, $text, $price, $tat]) {
            LabTest::create([
                'tenant_id' => $entoto->id, 'code' => $code, 'name' => $name, 'category' => $cat, 'specimen' => $specimen, 'unit' => $unit,
                'result_type' => $type, 'options' => $options, 'reference_low' => $lo, 'reference_high' => $hi, 'critical_low' => $clo,
                'critical_high' => $chi, 'reference_text' => $text, 'price' => $price, 'turnaround_minutes' => $tat, 'is_active' => true,
            ]);
        }

        $cbhi = Payer::create(['tenant_id' => $entoto->id, 'name' => 'Gullele Sub-city CBHI Scheme', 'code' => 'CBHI-GUL', 'type' => 'cbhi',
            'phone' => '+251111550011', 'contact_person' => 'Ato Teshome Ayana', 'default_copay_percent' => 0, 'credit_limit' => 500000,
            'payment_terms_days' => 60, 'preauth_threshold' => null, 'is_active' => true]);
        $nyala = Payer::create(['tenant_id' => $entoto->id, 'name' => 'Nyala Insurance S.C.', 'code' => 'NYALA', 'type' => 'private_insurance',
            'tin_number' => '0000071234', 'phone' => '+251116626667', 'email' => 'claims@nyalainsurance.et', 'contact_person' => 'Ms. Hanna Girma',
            'default_copay_percent' => 10, 'credit_limit' => 300000, 'payment_terms_days' => 30, 'preauth_threshold' => 1500, 'is_active' => true]);
        $ethio = Payer::create(['tenant_id' => $entoto->id, 'name' => 'Ethio Telecom (staff medical scheme)', 'code' => 'ETC', 'type' => 'corporate',
            'tin_number' => '0000030141', 'phone' => '+251115505678', 'contact_person' => 'HR Medical Desk', 'default_copay_percent' => 0,
            'credit_limit' => 200000, 'payment_terms_days' => 30, 'preauth_threshold' => 3000, 'is_active' => true,
            'coverage' => ['registration' => true, 'consultation' => true, 'lab' => true, 'medicine' => true, 'procedure' => true, 'other' => false]]);

        $patients = Patient::withoutGlobalScopes()->where('tenant_id', $entoto->id)->get()->keyBy('full_name');
        foreach ([
            [$cbhi, 'Abebe Kebede', 'CBHI-GUL-2016-00412', 'Household head', null, 12, 6],
            [$nyala, 'Yonas Tadesse', 'NYL-ME-778201', 'Gold medical plan', 'Awash Bank', 18, 6],
            [$ethio, 'Samuel Wolde', 'ETC-EMP-30981', 'Employee', 'Ethio Telecom', 24, 11],
            [$cbhi, 'Liya Getachew', 'CBHI-GUL-2016-00599', 'Dependant', null, 12, 6],
            [$nyala, 'Kidist Mulugeta', 'NYL-ME-701455', 'Silver medical plan', 'Dashen Bank', -14, -1], // lapsed cover
        ] as [$payer, $name, $number, $scheme, $employer, $fromMonths, $toMonths]) {
            $p = $patients[$name];
            PayerMembership::create([
                'tenant_id' => $entoto->id, 'payer_id' => $payer->id, 'patient_id' => $p->id, 'member_number' => $number,
                'beneficiary_name' => $p->full_name, 'phone' => $p->phone, 'scheme' => $scheme, 'employer' => $employer,
                'valid_from' => today()->subMonths(abs($fromMonths)), 'valid_to' => $toMonths > 0 ? today()->addMonths($toMonths) : today()->addMonths($toMonths)->endOfMonth()->min(today()->subDay()),
                'status' => 'active',
            ]);
        }
    }

    /** In-house pharmacy: stock arrives in the main store and moves to the dispensaries by transfer. */
    private function hospitalPharmacy(Tenant $entoto): void
    {
        $pharmacist = User::where('email', 'entoto.pharmacist@medlink.et')->first();
        Auth::setUser($pharmacist);
        [$epss] = ActivityLogger::muted(fn () => $this->suppliers($entoto));
        $main = Store::where('tenant_id', $entoto->id)->where('code', 'MAIN')->first();
        $opd = Store::where('tenant_id', $entoto->id)->where('code', 'OPD')->first();
        $emg = Store::where('tenant_id', $entoto->id)->where('code', 'EMG')->first();
        $prices = CatalogSeeder::referencePrices();

        $wanted = ['Amoxicillin', 'Paracetamol', 'Artemether + Lumefantrine', 'Amlodipine', 'Metformin', 'Atorvastatin', 'Ciprofloxacin',
            'Omeprazole', 'Enalapril', 'Metronidazole', 'Ibuprofen', 'Oral Rehydration Salts (ORS)', 'Zinc Sulfate', 'Diazepam',
            'Tramadol', 'Ceftriaxone', 'Cotrimoxazole', 'Ferrous Sulfate + Folic Acid', 'Ascorbic Acid (Vitamin C)', 'Normal Saline'];
        $listings = Medicine::whereIn('generic_name', $wanted)->get()->map(function (Medicine $m) use ($entoto, $prices) {
            $ref = $prices["{$m->generic_name}|{$m->strength}|{$m->dosage_form}"] ?? 5;

            return PharmacyMedicine::create([
                'tenant_id' => $entoto->id, 'medicine_id' => $m->id, 'price' => max(0.5, round($ref * 1.1 * 2) / 2),
                'reorder_level' => in_array($m->unit, ['tablet', 'capsule', 'sachet'], true) ? 100 : 10, 'is_public' => false,
            ])->setRelation('medicine', $m);
        });

        $po = $this->purchasing->create($epss, $listings->map(fn ($l) => [
            'pharmacy_medicine_id' => $l->id, 'quantity' => in_array($l->medicine->unit, ['tablet', 'capsule', 'sachet'], true) ? 1000 : 60,
            'unit_cost' => round((float) $l->price * 0.7, 2),
        ])->all(), ['store_id' => $main->id, 'notes' => 'Quarterly EPSS requisition (RRF)']);
        $this->purchasing->send($po);
        $this->purchasing->receive($po, $po->items()->get()->map(fn ($item, $i) => [
            'item_id' => $item->id, 'quantity' => $item->quantity_ordered, 'batch_number' => 'EPSS-Q1-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
            'expiry_date' => today()->addMonths($i % 7 === 0 ? 2 : mt_rand(10, 30))->toDateString(),
        ])->all());

        $toOpd = $this->transfers->request($main, $opd, $listings->map(fn ($l) => [
            'pharmacy_medicine_id' => $l->id, 'quantity' => in_array($l->medicine->unit, ['tablet', 'capsule', 'sachet'], true) ? 400 : 20,
        ])->all(), 'Weekly OPD top-up');
        $this->transfers->dispatch($toOpd);
        $this->transfers->receive($toOpd);

        $emergencyItems = $listings->filter(fn ($l) => in_array($l->medicine->generic_name, ['Paracetamol', 'Ceftriaxone', 'Normal Saline', 'Diazepam', 'Tramadol'], true));
        if ($emergencyItems->isNotEmpty()) {
            $toEmg = $this->transfers->request($main, $emg, $emergencyItems->map(fn ($l) => ['pharmacy_medicine_id' => $l->id, 'quantity' => 30])->values()->all(), 'Emergency trolley restock');
            $this->transfers->dispatch($toEmg); // in transit, awaiting receipt
        }
    }

    private function patientJourneys(Tenant $entoto): void
    {
        $reception = User::where('email', 'reception@medlink.et')->first();
        $nurse = User::where('email', 'nurse@medlink.et')->first();
        $hana = User::where('email', 'dr.hana@medlink.et')->first();
        $dawit = User::where('email', 'dr.dawit@medlink.et')->first();
        $labTech = User::where('email', 'lab@medlink.et')->first();
        $cashier = User::where('email', 'cashier@medlink.et')->first();
        $patients = Patient::withoutGlobalScopes()->where('tenant_id', $entoto->id)->get()->keyBy('full_name');
        $tests = LabTest::withoutGlobalScopes()->where('tenant_id', $entoto->id)->get()->keyBy('code')->toBase();
        $dx = collect(config('ethiopia.diagnoses'))->keyBy('code');

        // 1. History: completed OPD visits over the past three weeks (feeds DHIS2 morbidity & financial reports)
        $history = [
            ['Eyerusalem Tefera', 'opd', ['A06.9'], ['STOOL' => 'Trophozoites of E. histolytica seen'], 19],
            ['Biruk Asfaw', 'opd', ['K29.7'], ['HPY' => 'Positive'], 16],
            ['Samuel Wolde', 'follow_up', ['I10'], [], 13],
            ['Kidist Mulugeta', 'opd', ['N39.0'], ['UA' => 'Leucocytes ++, nitrite positive'], 9],
            ['Meron Alemu', 'opd', ['B50.9'], ['BF' => 'P. falciparum seen'], 7],
            ['Liya Getachew', 'opd', ['H66.9'], [], 5],
            ['Abebe Kebede', 'opd', ['J03.9'], ['CBC' => 13.4], 3],
        ];
        foreach ($history as [$name, $type, $codes, $labs, $daysAgo]) {
            $p = $patients[$name];
            $membership = PayerMembership::where('patient_id', $p->id)->get()->first(fn ($m) => $m->isValid());
            $visit = $this->as($reception, fn () => $this->clinical->checkIn($p, ['type' => $type, 'doctor_id' => $hana->id, 'membership_id' => $membership?->id, 'chief_complaint' => 'See notes']));
            $this->as($nurse, fn () => $this->clinical->triage($visit, ['temperature' => 36.4 + mt_rand(0, 30) / 10, 'pulse' => mt_rand(70, 104), 'bp_systolic' => mt_rand(105, 150), 'bp_diastolic' => mt_rand(65, 95), 'weight' => mt_rand(18, 85)]));
            $this->as($hana, fn () => $this->clinical->start($visit, $hana));
            if ($labs) {
                $order = $this->as($hana, fn () => $this->lab->order($p, $visit, $tests->only(array_keys($labs))->pluck('id')->all()));
                $this->payAll($cashier, $p);
                $this->as($labTech, function () use ($order, $labs) {
                    $this->lab->collect($order);
                    foreach ($order->items()->with('test')->get() as $item) {
                        $value = $labs[$item->test->code];
                        $this->lab->verify($this->lab->result($item, is_numeric($value) ? ['result_value' => $value] : ['result_text' => $value]));
                    }
                });
            }
            $this->as($hana, function () use ($visit, $codes, $dx) {
                $this->clinical->saveNotes($visit, ['diagnoses' => collect($codes)->map(fn ($c) => $dx[$c] ?? ['code' => $c, 'name' => $c])->values()->all(), 'plan' => 'Treatment given; review if not improving in 3 days.']);
                $this->clinical->complete($visit);
            });
            $this->payAll($cashier, $p);
            $this->backdateVisit($visit->refresh(), now()->subDays($daysAgo)->setTime(mt_rand(8, 15), mt_rand(0, 59)));
        }

        // 2. Today's queue at different stages
        $today = [
            ['Yonas Tadesse', 'follow_up', 'Review of blood pressure and sugar'],
            ['Meron Alemu', 'opd', 'Headache, fever and chills for 2 days'],
            ['Samuel Wolde', 'emergency', 'Chest tightness and shortness of breath'],
            ['Eyerusalem Tefera', 'opd', 'Burning urination'],
            ['Kidist Mulugeta', 'antenatal', 'ANC 2nd visit, 24 weeks'],
        ];
        $visits = [];
        foreach ($today as [$name, $type, $complaint]) {
            $p = $patients[$name];
            $membership = PayerMembership::where('patient_id', $p->id)->get()->first(fn ($m) => $m->isValid());
            $visits[$name] = $this->as($reception, fn () => $this->clinical->checkIn($p, ['type' => $type, 'membership_id' => $membership?->id, 'chief_complaint' => $complaint, 'doctor_id' => $type === 'emergency' ? $dawit->id : null]));
        }

        // Yonas: triaged, seen, labs resulted (one critical sugar), awaiting doctor review
        $this->as($nurse, fn () => $this->clinical->triage($visits['Yonas Tadesse'], ['temperature' => 36.7, 'pulse' => 82, 'bp_systolic' => 158, 'bp_diastolic' => 96, 'weight' => 78, 'height' => 170, 'spo2' => 97]));
        $this->as($hana, fn () => $this->clinical->start($visits['Yonas Tadesse'], $hana));
        $order = $this->as($hana, fn () => $this->lab->order($patients['Yonas Tadesse'], $visits['Yonas Tadesse'], $tests->only(['FBS', 'CREA'])->pluck('id')->all(), 'routine', 'Known HTN + T2DM, on metformin'));
        $this->payAll($cashier, $patients['Yonas Tadesse']);
        $this->as($labTech, function () use ($order) {
            $this->lab->collect($order);
            foreach ($order->items()->with('test')->get() as $item) {
                $this->lab->result($item, ['result_value' => $item->test->code === 'FBS' ? 412 : 1.1]);
            }
        });

        // Yonas is kept for observation: above Nyala's pre-authorization threshold, so the insurer must approve first
        $this->as($hana, function () use ($entoto, $visits, $patients) {
            $visit = $visits['Yonas Tadesse'];
            $services = Service::whereIn('code', ['PROC-ECG', 'PROC-IV', 'BED-GEN'])->get()->keyBy('code');
            $this->billing->createInvoice($entoto, [
                'patient_id' => $visit->patient_id, 'visit_id' => $visit->id,
                'customer_name' => $patients['Yonas Tadesse']->full_name, 'customer_phone' => $patients['Yonas Tadesse']->phone,
            ], collect(['PROC-ECG' => 1, 'PROC-IV' => 1, 'BED-GEN' => 3])->map(fn ($qty, $code) => [
                'item_type' => 'procedure', 'description' => $services[$code]->name, 'quantity' => $qty,
                'unit_price' => $services[$code]->price, 'tax_category' => 'exempt', 'reference' => $services[$code],
            ])->values()->all(), $visit->membership);
        });

        // Meron: triaged with fever, malaria tests ordered but not yet paid (lab waits for cashier)
        $this->as($nurse, fn () => $this->clinical->triage($visits['Meron Alemu'], ['temperature' => 38.9, 'pulse' => 108, 'bp_systolic' => 110, 'bp_diastolic' => 70, 'weight' => 58, 'spo2' => 96]));
        $this->as($dawit, function () use ($visits, $patients, $tests, $dawit) {
            $this->clinical->start($visits['Meron Alemu'], $dawit);
            $this->lab->order($patients['Meron Alemu'], $visits['Meron Alemu'], $tests->only(['BF', 'CBC'])->pluck('id')->all(), 'urgent', 'Recurrent fever - rule out malaria');
        });

        // Samuel: emergency, triaged urgent, with the doctor
        $this->as($nurse, fn () => $this->clinical->triage($visits['Samuel Wolde'], ['temperature' => 37.1, 'pulse' => 118, 'bp_systolic' => 186, 'bp_diastolic' => 112, 'spo2' => 90, 'respiratory_rate' => 26]));
        $this->as($dawit, fn () => $this->clinical->start($visits['Samuel Wolde'], $dawit));

        // Eyerusalem triaged and waiting; Kidist just arrived
        $this->as($nurse, fn () => $this->clinical->triage($visits['Eyerusalem Tefera'], ['temperature' => 37.2, 'pulse' => 88, 'bp_systolic' => 118, 'bp_diastolic' => 76, 'weight' => 61]));

        // Some invoices settled by the cashier this morning, a mix of methods
        $this->payAll($cashier, $patients['Kidist Mulugeta'], 'telebirr');
    }

    /** Monthly CBHI/insurer claim cycle: generate, submit and part-pay a claim. */
    private function insuranceCycle(Tenant $entoto): void
    {
        Auth::setUser($entoto->users()->role(User::ROLE_HOSPITAL_ADMIN)->first());
        foreach (Payer::where('tenant_id', $entoto->id)->get() as $payer) {
            try {
                $claim = $this->insurance->generateClaim($payer, CarbonImmutable::today()->subDays(30), CarbonImmutable::today()->subDay());
                if ($payer->type === 'cbhi') {
                    $this->insurance->submit($claim);
                } elseif ($payer->type === 'corporate') {
                    $this->insurance->submit($claim);
                    $this->insurance->recordClaimPayment($claim->refresh(), round((float) $claim->total_amount * 0.6, 2), 0, 'bank_transfer', 'CBE-TT-'.mt_rand(100000, 999999));
                }
            } catch (BusinessRuleException $e) {
                $this->command?->warn("Claim for {$payer->name}: ".$e->getMessage());
            }
        }
    }

    private function payAll(User $cashier, Patient $patient, string $method = 'cash'): void
    {
        $this->as($cashier, function () use ($patient, $method) {
            foreach (Invoice::where('patient_id', $patient->id)->open()->get() as $invoice) {
                $due = $invoice->patientBalance();
                if ($due > 0) {
                    $this->billing->recordPayment($invoice, $due, $method, $method === 'cash' ? null : 'TB'.mt_rand(10000000, 99999999));
                }
            }
        });
    }

    private function backdateVisit(Visit $visit, \Carbon\Carbon $at): void
    {
        $shift = fn ($minutes) => $at->copy()->addMinutes($minutes);
        Visit::withoutGlobalScopes()->whereKey($visit->id)->update([
            'checked_in_at' => $at, 'triaged_at' => $shift(10), 'seen_at' => $shift(35), 'completed_at' => $shift(120),
            'created_at' => $at, 'updated_at' => $shift(120),
        ]);
        Invoice::withoutGlobalScopes()->where('visit_id', $visit->id)->update(['issued_at' => $at, 'created_at' => $at]);
        foreach (LabOrder::withoutGlobalScopes()->where('visit_id', $visit->id)->get() as $order) {
            $order->update(['created_at' => $shift(40), 'collected_at' => $shift(55), 'completed_at' => $shift(100)]);
            $order->items()->update(['resulted_at' => $shift(95), 'verified_at' => $shift(100)]);
        }
        \App\Models\Payment::withoutGlobalScopes()->whereIn('invoice_id', Invoice::withoutGlobalScopes()->where('visit_id', $visit->id)->pluck('id'))
            ->update(['created_at' => $shift(45)]);
    }

    private function as(User $user, callable $fn): mixed
    {
        Auth::setUser($user);

        return $fn();
    }

    private function user(Tenant $tenant, string $name, string $email, string $phone, string $role, ?string $license = null): User
    {
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'password' => DemoSeeder::PASSWORD,
            'license_number' => $license, 'city' => $tenant->city, 'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }
}
