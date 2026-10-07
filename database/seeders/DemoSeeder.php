<?php

namespace Database\Seeders;

use App\Exceptions\BusinessRuleException;
use App\Models\ActivityLog;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Order;
use App\Models\Patient;
use App\Models\PharmacyMedicine;
use App\Models\Prescription;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Inventory\StockService;
use App\Services\Orders\OrderService;
use App\Services\Prescriptions\PrescriptionService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Realistic Ethiopian demo data: pharmacies across regions, a hospital with doctors and patients,
 * stocked inventories with varied expiry, prescriptions, and 30 days of sales history.
 *
 * Every demo account uses the password in DemoSeeder::PASSWORD (see README).
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Password@123';

    private const PHARMACIES = [
        ['Bole Medhanit Pharmacy', 'Addis Ababa', 'Addis Ababa', 'Bole', '03', 'Bole Road, near Edna Mall', 8.9963, 38.7894, true, true],
        ['Piassa Tena Pharmacy', 'Addis Ababa', 'Addis Ababa', 'Arada', '01', 'Churchill Avenue, near St. George Cathedral', 9.0350, 38.7505, false, false],
        ['Kazanchis Fews Pharmacy', 'Addis Ababa', 'Addis Ababa', 'Kirkos', '08', 'Kazanchis, opposite ECA', 9.0185, 38.7681, false, true],
        ['Megenagna Selam Pharmacy', 'Addis Ababa', 'Addis Ababa', 'Yeka', '06', 'Megenagna roundabout, Zefmesh building', 9.0201, 38.8020, true, false],
        ['Adama Hiwot Pharmacy', 'Oromia', 'Adama', null, '02', 'Franko area, main road to Djibouti', 8.5400, 39.2700, false, true],
        ['Hawassa Lake Pharmacy', 'Sidama', 'Hawassa', null, '04', 'Piassa, near the lake front', 7.0621, 38.4764, false, false],
        ['Bahir Dar Abay Pharmacy', 'Amhara', 'Bahir Dar', null, '05', 'Kebele 14, near Abay bridge', 11.5936, 37.3908, true, false],
    ];

    private array $prices = [];

    public function __construct(
        private StockService $stock,
        private OrderService $orders,
        private PrescriptionService $prescriptions,
    ) {}

    public function run(): void
    {
        mt_srand(2026);
        config(['queue.default' => 'sync']); // demo users see their notifications immediately
        $this->prices = CatalogSeeder::referencePrices();

        [$pharmacies, $hospital] = ActivityLogger::muted(function () {
            $this->superAdmin();
            $pharmacies = $this->pharmacies();
            $hospital = $this->hospital();
            $this->pendingOrganizations();
            $this->customers();

            return [$pharmacies, $hospital];
        });

        $prescriptions = $this->prescriptions($hospital);
        foreach (array_slice($pharmacies, 0, 6) as $pharmacy) {
            $this->salesHistory($pharmacy);
        }
        $this->onlineOrders($pharmacies[0], $prescriptions);
        $this->prescriptionSaleAtCounter($pharmacies[2], $prescriptions['meron']);

        Auth::forgetUser();
    }

    private function superAdmin(): void
    {
        $this->user(null, 'Tsion Abera', 'admin@medlink.et', '+251911000001', User::ROLE_SUPER_ADMIN);
    }

    /** @return Tenant[] */
    private function pharmacies(): array
    {
        $medicines = Medicine::all();
        $result = [];

        foreach (self::PHARMACIES as $i => [$name, $region, $city, $sub, $woreda, $address, $lat, $lng, $h24, $delivery]) {
            $tenant = Tenant::create([
                'name' => $name, 'type' => 'pharmacy', 'status' => 'active',
                'license_number' => 'EFDA/RP/'.(2400 + $i * 37).'/2016', 'tin_number' => (string) (1000234560 + $i * 1111),
                'phone' => '+2511155'.str_pad((string) (1200 + $i * 7), 5, '0', STR_PAD_LEFT),
                'email' => str($name)->before(' Pharmacy')->slug().'@pharmacy.et',
                'region' => $region, 'city' => $city, 'sub_city' => $sub, 'woreda' => $woreda, 'address_line' => $address,
                'latitude' => $lat, 'longitude' => $lng,
                'opening_hours' => $h24 ? 'Open 24 hours' : 'Mon-Sat 8:00-21:00 (2:00-3:00 Ethiopian time)',
                'is_24_hours' => $h24, 'delivery_available' => $delivery,
                'description' => "Licensed community pharmacy in {$city} serving the {$sub} area with genuine EFDA-registered medicines.",
                'approved_at' => now()->subMonths(6 - $i % 3),
            ]);

            $slug = str($name)->before(' ')->lower(); // bole.admin@, piassa.admin@, ...
            $this->user($tenant, $this->name($i * 2), "{$slug}.admin@medlink.et", '+2519112'.str_pad((string) ($i * 2 + 10), 5, '0', STR_PAD_LEFT), User::ROLE_PHARMACY_ADMIN);
            $this->user($tenant, $this->name($i * 2 + 1), "{$slug}.pharmacist@medlink.et", '+2519113'.str_pad((string) ($i * 2 + 11), 5, '0', STR_PAD_LEFT), User::ROLE_STAFF, 'PH-'.(5100 + $i));

            Auth::setUser($tenant->users()->first());
            // The flagship pharmacy stocks the whole catalogue so every demo flow works there
            $this->stockPharmacy($tenant, $medicines, $i === 0 ? $medicines->count() : mt_rand(28, 42), flagship: $i === 0);
            $result[] = $tenant;
        }

        return $result;
    }

    private function stockPharmacy(Tenant $tenant, $medicines, int $count, bool $flagship = false): void
    {
        $chosen = $medicines->shuffle(mt_rand())->take($count)->values();
        $hiddenSlot = $flagship ? -1 : 7; // one listing hidden from the public catalogue
        $expiredSlots = [3, 11];  // a couple of listings carry an already-expired batch
        $lowSlots = [5, 9, 14];   // and a few are running low

        foreach ($chosen as $n => $medicine) {
            $ref = $this->prices["{$medicine->generic_name}|{$medicine->strength}|{$medicine->dosage_form}"] ?? 5;
            $price = max(0.5, round($ref * mt_rand(90, 125) / 100 * 2) / 2);
            $bulk = in_array($medicine->unit, ['tablet', 'capsule', 'sachet'], true);

            $listing = PharmacyMedicine::create([
                'tenant_id' => $tenant->id,
                'medicine_id' => $medicine->id,
                'price' => $price,
                'reorder_level' => $bulk ? 60 : 8,
                'is_public' => $n !== $hiddenSlot,
            ]);

            $batches = in_array($n, $lowSlots, true) ? 1 : mt_rand(1, 3);
            for ($b = 0; $b < $batches; $b++) {
                $qty = in_array($n, $lowSlots, true)
                    ? ($bulk ? mt_rand(20, 45) : mt_rand(2, 5))
                    : ($bulk ? mt_rand(15, 60) * 10 : mt_rand(12, 50));

                $expiry = match (true) {
                    $b === 0 && $n % 6 === 0 => today()->addDays(mt_rand(10, 28)),  // critical
                    $b === 0 && $n % 6 === 1 => today()->addDays(mt_rand(45, 85)),  // warning
                    default => today()->addMonths(mt_rand(5, 30)),
                };

                $this->stock->receive($listing, [
                    'batch_number' => strtoupper(substr($medicine->manufacturer ?? 'GEN', 0, 3)).'-'.now()->format('y').mt_rand(1000, 9999).chr(65 + $b),
                    'quantity' => $qty,
                    'expiry_date' => $expiry->toDateString(),
                    'purchase_price' => round($price * mt_rand(62, 78) / 100, 2),
                    'supplier' => $ref > 50 || ! str_contains((string) $medicine->manufacturer, 'EPHARM')
                        ? collect(['Private importer', 'Cadila Pharmaceuticals Ethiopia', 'EPSS (Ethiopian Pharmaceutical Supply Service)'])->random()
                        : 'EPSS (Ethiopian Pharmaceutical Supply Service)',
                    'received_at' => today()->subDays(mt_rand(35, 120))->toDateString(),
                ]);
            }

            if (in_array($n, $expiredSlots, true)) {
                // History: a batch that expired last week and still sits on the shelf (the daily scan writes it off)
                $batch = MedicineBatch::create([
                    'tenant_id' => $tenant->id, 'pharmacy_medicine_id' => $listing->id,
                    'batch_number' => 'OLD-'.mt_rand(100, 999), 'initial_quantity' => 40, 'quantity' => $bulk ? 40 : 4,
                    'expiry_date' => today()->subDays(mt_rand(2, 9)), 'purchase_price' => round($price * 0.7, 2),
                    'supplier' => 'EPSS (Ethiopian Pharmaceutical Supply Service)', 'received_at' => today()->subYear(),
                ]);
                StockMovement::create([
                    'tenant_id' => $tenant->id, 'batch_id' => $batch->id, 'type' => 'in',
                    'quantity' => $batch->initial_quantity, 'reason' => 'Opening stock',
                ]);
            }
        }
    }

    private function hospital(): Tenant
    {
        $hospital = Tenant::create([
            'name' => 'Entoto Hills General Hospital', 'type' => 'hospital', 'status' => 'active',
            'license_number' => 'MOH/HF/0871/2015', 'tin_number' => '0045678912', 'phone' => '+251111234567',
            'email' => 'info@entotohills.et', 'region' => 'Addis Ababa', 'city' => 'Addis Ababa', 'sub_city' => 'Gullele',
            'woreda' => '09', 'address_line' => 'Shiro Meda, Entoto Road', 'latitude' => 9.0620, 'longitude' => 38.7580,
            'opening_hours' => 'Emergency 24/7, OPD Mon-Sat 7:30-17:30', 'is_24_hours' => true,
            'description' => 'General hospital with OPD, maternity, paediatrics and emergency services.', 'approved_at' => now()->subYear(),
        ]);

        $this->user($hospital, 'Mekdes Ayele', 'entoto.admin@medlink.et', '+251912300001', User::ROLE_HOSPITAL_ADMIN);
        $this->user($hospital, 'Dr. Hana Tesfaye', 'dr.hana@medlink.et', '+251912300002', User::ROLE_DOCTOR, 'EMA-MD-11823');
        $this->user($hospital, 'Dr. Dawit Bekele', 'dr.dawit@medlink.et', '+251912300003', User::ROLE_DOCTOR, 'EMA-MD-09412');

        $patients = [
            ['Abebe Kebede', '+251911223344', 'male', '1986-03-14', 'O+', null],
            ['Meron Alemu', '+251922334455', 'female', '1992-07-02', 'A+', 'Penicillin'],
            ['Yonas Tadesse', '+251933445566', 'male', '1958-11-21', 'B+', null],
            ['Kidist Mulugeta', '+251944556677', 'female', '2001-01-30', 'O-', null],
            ['Biruk Asfaw', '+251955667788', 'male', '1975-05-09', 'AB+', 'Sulfa drugs'],
            ['Liya Getachew', '+251966778899', 'female', '2018-09-12', 'A-', null],
            ['Samuel Wolde', '+251977889900', 'male', '1969-12-03', 'O+', null],
            ['Eyerusalem Tefera', '+251988990011', 'female', '1995-04-18', 'B-', null],
        ];
        foreach ($patients as $i => [$name, $phone, $gender, $dob, $blood, $allergy]) {
            Patient::create([
                'tenant_id' => $hospital->id, 'mrn' => 'EHG-'.str_pad((string) (1201 + $i * 13), 6, '0', STR_PAD_LEFT),
                'full_name' => $name, 'phone' => $phone, 'gender' => $gender, 'date_of_birth' => $dob,
                'city' => 'Addis Ababa', 'address' => collect(['Gullele', 'Arada', 'Yeka', 'Bole', 'Lideta'])->random(),
                'blood_group' => $blood, 'allergies' => $allergy,
            ]);
        }

        return $hospital;
    }

    private function pendingOrganizations(): void
    {
        $pending = Tenant::create([
            'name' => 'Gondar Fasil Pharmacy', 'type' => 'pharmacy', 'status' => 'pending',
            'license_number' => 'EFDA/RP/3391/2017', 'phone' => '+251581110022', 'region' => 'Amhara', 'city' => 'Gondar',
            'woreda' => '03', 'address_line' => 'Piassa, near Fasil Ghebbi',
        ]);
        $this->user($pending, 'Fikru Desta', 'gondar.admin@medlink.et', '+251918440011', User::ROLE_PHARMACY_ADMIN);

        $clinic = Tenant::create([
            'name' => 'Dire Dawa Legehare Clinic', 'type' => 'hospital', 'status' => 'pending',
            'license_number' => 'MOH/HF/1450/2017', 'phone' => '+251251120033', 'region' => 'Dire Dawa', 'city' => 'Dire Dawa',
            'address_line' => 'Legehare, near the railway station',
        ]);
        $this->user($clinic, 'Hanan Mohammed', 'diredawa.admin@medlink.et', '+251918440022', User::ROLE_HOSPITAL_ADMIN);
    }

    private function customers(): void
    {
        $abebe = $this->user(null, 'Abebe Kebede', 'abebe@example.com', '+251911223344', User::ROLE_CUSTOMER);
        $meron = $this->user(null, 'Meron Alemu', 'meron@example.com', '+251922334455', User::ROLE_CUSTOMER);
        $this->user(null, 'Selamawit Girma', 'selam@example.com', '+251900112233', User::ROLE_CUSTOMER);

        // Link hospital records to customer accounts with the same phone (so patients get notified)
        Patient::withoutGlobalScopes()->where('phone', $abebe->phone)->update(['user_id' => $abebe->id]);
        Patient::withoutGlobalScopes()->where('phone', $meron->phone)->update(['user_id' => $meron->id]);
    }

    /** @return array<string, Prescription> */
    private function prescriptions(Tenant $hospital): array
    {
        $hana = User::where('email', 'dr.hana@medlink.et')->first();
        $dawit = User::where('email', 'dr.dawit@medlink.et')->first();
        $patients = Patient::withoutGlobalScopes()->where('tenant_id', $hospital->id)->get()->keyBy('full_name');
        $med = fn (string $generic, string $strength) => Medicine::where('generic_name', $generic)->where('strength', $strength)->value('id');

        $issue = function (User $doctor, string $patient, array $items, string $diagnosis, int $daysAgo = 0, int $validity = 30) use ($patients) {
            Auth::setUser($doctor);
            $rx = $this->prescriptions->issue($doctor, $patients[$patient], [
                'diagnosis' => $diagnosis, 'validity_days' => $validity, 'items' => $items,
            ]);
            if ($daysAgo) {
                $rx->update([
                    'issued_at' => now()->subDays($daysAgo),
                    'expires_at' => now()->subDays($daysAgo)->addDays($validity)->endOfDay(),
                    'created_at' => now()->subDays($daysAgo),
                ]);
            }

            return $rx;
        };

        $line = fn ($id, $dosage, $freq, $days, $total, $note = null) => [
            'medicine_id' => $id, 'dosage' => $dosage, 'frequency' => $freq,
            'duration_days' => $days, 'total_quantity' => $total, 'instructions' => $note,
        ];

        $list = [
            'abebe' => $issue($hana, 'Abebe Kebede', [
                $line($med('Amoxicillin', '500mg'), '1 capsule', '3 times daily', 7, 21, 'Complete the full course'),
                $line($med('Paracetamol', '500mg'), '1-2 tablets', 'Every 6 hours as needed', 5, 20, 'Max 8 tablets per day'),
            ], 'Acute bacterial pharyngitis', 1),
            'meron' => $issue($dawit, 'Meron Alemu', [
                $line($med('Artemether + Lumefantrine', '20/120mg'), '4 tablets', 'Twice daily', 3, 24, 'Take with fatty food or milk'),
                $line($med('Paracetamol', '500mg'), '1 tablet', '3 times daily', 3, 9),
            ], 'Uncomplicated P. falciparum malaria (RDT positive)', 2),
            'yonas' => $issue($hana, 'Yonas Tadesse', [
                $line($med('Amlodipine', '5mg'), '1 tablet', 'Once daily (morning)', 30, 30),
                $line($med('Metformin', '500mg'), '1 tablet', 'Twice daily with meals', 30, 60),
                $line($med('Atorvastatin', '20mg'), '1 tablet', 'Once daily at night', 30, 30),
            ], 'Hypertension; Type 2 diabetes mellitus - follow-up', 4, 60),
        ];

        $issue($dawit, 'Kidist Mulugeta', [$line($med('Ciprofloxacin', '500mg'), '1 tablet', 'Twice daily', 5, 10)], 'Uncomplicated UTI', 6);
        $issue($hana, 'Biruk Asfaw', [$line($med('Omeprazole', '20mg'), '1 capsule', 'Once daily before breakfast', 28, 28)], 'Dyspepsia', 8);
        $issue($dawit, 'Liya Getachew', [
            $line($med('Amoxicillin', '250mg/5ml'), '5 ml', '3 times daily', 7, 2),
            $line($med('Paracetamol', '120mg/5ml'), '5 ml', 'Every 6 hours as needed', 3, 1),
        ], 'Acute otitis media (paediatric)', 3);
        $issue($hana, 'Samuel Wolde', [$line($med('Enalapril', '10mg'), '1 tablet', 'Once daily', 30, 30)], 'Hypertension', 11, 60);
        $issue($dawit, 'Eyerusalem Tefera', [$line($med('Metronidazole', '250mg'), '2 tablets', '3 times daily', 7, 42)], 'Amoebiasis', 40);
        $this->prescriptions->expireOverdue();

        return $list;
    }

    /** 30 days of counter sales, so dashboards and reports have something to show. */
    private function salesHistory(Tenant $pharmacy): void
    {
        $staff = $pharmacy->users()->role(User::ROLE_STAFF)->first();
        Auth::setUser($staff);
        $methods = ['cash', 'cash', 'cash', 'telebirr', 'telebirr', 'cbe_birr', 'insurance'];
        $names = ['Walk-in customer', 'Tigist H.', 'Yared M.', 'Almaz T.', 'Henok G.', 'Rahel K.', 'Mulugeta A.'];

        for ($day = 29; $day >= 0; $day--) {
            $sales = mt_rand(1, 4) + ($day < 7 ? 1 : 0);
            for ($s = 0; $s < $sales; $s++) {
                $candidates = PharmacyMedicine::with('medicine')
                    ->whereHas('medicine', fn ($m) => $m->where('prescription_required', false)->where('is_controlled', false))
                    ->whereStock('>', 'reorder_level')
                    ->get();
                if ($candidates->isEmpty()) {
                    return;
                }

                // Cheap tablets go by the strip of 10; expensive ones and bottles/tubes one or two at a time
                $items = $candidates->shuffle(mt_rand())->take(mt_rand(1, 3))->map(fn ($l) => [
                    'pharmacy_medicine_id' => $l->id,
                    'quantity' => in_array($l->medicine->unit, ['tablet', 'capsule'], true) && $l->price < 15 ? mt_rand(1, 3) * 10 : mt_rand(1, 2),
                ])->values()->all();

                $order = $this->orders->sellWalkIn($staff, [
                    'items' => $items,
                    'payment_method' => $methods[array_rand($methods)],
                    'customer_name' => $names[array_rand($names)],
                ]);

                // Today's sales happened earlier today, never in the future
                $at = $day === 0
                    ? now()->subMinutes(mt_rand(5, max(6, (int) now()->startOfDay()->addHours(8)->diffInMinutes(now(), true))))
                    : now()->subDays($day)->setTime(mt_rand(8, 20), mt_rand(0, 59));
                $this->backdate($order, $at);
            }
        }
    }

    private function onlineOrders(Tenant $pharmacy, array $prescriptions): void
    {
        $abebe = User::where('email', 'abebe@example.com')->first();
        $selam = User::where('email', 'selam@example.com')->first();
        $meron = User::where('email', 'meron@example.com')->first();
        $staff = $pharmacy->users()->role(User::ROLE_STAFF)->first();

        // This morning's EPSS delivery tops up the items the scripted orders below use
        Auth::setUser($staff);
        $ids = [];
        foreach ([
            'amox' => ['Amoxicillin', '500mg'], 'para' => ['Paracetamol', '500mg'], 'vitc' => ['Ascorbic Acid (Vitamin C)', '500mg'],
            'ors' => ['Oral Rehydration Salts (ORS)', '20.5g'], 'dxm' => ['Dextromethorphan', '15mg/5ml, 100ml'],
            'cetirizine' => ['Cetirizine', '10mg'], 'ibuprofen' => ['Ibuprofen', '400mg'], 'vitb' => ['Vitamin B Complex', 'Standard'],
        ] as $key => [$generic, $strength]) {
            $listing = PharmacyMedicine::where('tenant_id', $pharmacy->id)
                ->whereHas('medicine', fn ($m) => $m->where('generic_name', $generic)->where('strength', $strength))
                ->with('medicine')
                ->firstOrFail();
            $this->stock->receive($listing, [
                'batch_number' => 'EPSS-'.now()->format('ymd').'-'.$listing->id,
                'quantity' => in_array($listing->medicine->unit, ['tablet', 'capsule', 'sachet'], true) ? 200 : 20,
                'expiry_date' => today()->addMonths(18)->toDateString(),
                'purchase_price' => round((float) $listing->price * 0.7, 2),
                'supplier' => 'EPSS (Ethiopian Pharmaceutical Supply Service)',
            ]);
            $ids[$key] = $listing->id;
        }

        $place = function (User $customer, array $items, array $extra = []) use ($pharmacy): ?Order {
            Auth::setUser($customer);

            try {
                return $this->orders->placeOnline($customer, [
                    'pharmacy_id' => $pharmacy->id,
                    'items' => collect($items)->map(fn ($q, $id) => ['pharmacy_medicine_id' => $id, 'quantity' => $q])->values()->all(),
                    'fulfillment' => 'pickup',
                    'payment_method' => 'telebirr',
                    ...$extra,
                ]);
            } catch (BusinessRuleException $e) {
                $this->command?->warn('Skipped a demo order: '.$e->getMessage());

                return null;
            }
        };

        // Abebe orders his antibiotics with the prescription his doctor gave him
        $place($abebe, [$ids['amox'] => 21, $ids['para'] => 20], [
            'prescription_code' => $prescriptions['abebe']->reference_code,
            'patient_phone' => $abebe->phone,
            'notes' => 'I will pick up after work, around 6pm.',
        ]);
        $vitamins = $place($selam, [$ids['vitc'] => 30, $ids['ors'] => 4], [
            'fulfillment' => 'delivery', 'delivery_address' => 'Bole, Woreda 03, House 512 (near Friendship Hotel)', 'payment_method' => 'cbe_birr',
        ]);
        $cough = $place($meron, [$ids['dxm'] => 1, $ids['cetirizine'] => 10]);
        $done = $place($selam, [$ids['ibuprofen'] => 20]);
        $withdrawn = $place($meron, [$ids['vitb'] => 30]);

        Auth::setUser($staff);
        $vitamins && $this->orders->confirm($vitamins);
        if ($cough) {
            $this->orders->confirm($cough);
            $this->orders->markReady($cough);
        }
        if ($done) {
            $this->orders->confirm($done);
            $this->orders->complete($done);
            $this->backdate($done, now()->subDays(2)->setTime(11, 20));
        }

        Auth::setUser($meron);
        $withdrawn && $this->orders->cancel($withdrawn, 'Bought it elsewhere');
    }

    /** A pharmacist dispenses Meron's malaria prescription at the counter. */
    private function prescriptionSaleAtCounter(Tenant $pharmacy, Prescription $rx): void
    {
        $staff = $pharmacy->users()->role(User::ROLE_STAFF)->first();
        Auth::setUser($staff);

        $items = $rx->items->map(function ($item) use ($pharmacy) {
            $listingId = PharmacyMedicine::where('tenant_id', $pharmacy->id)->where('medicine_id', $item->medicine_id)->whereStock('>=', $item->total_quantity)->value('id');

            return $listingId ? ['pharmacy_medicine_id' => $listingId, 'quantity' => $item->total_quantity] : null;
        })->filter()->values()->all();

        if ($items) {
            $this->orders->sellWalkIn($staff, [
                'items' => $items,
                'prescription_code' => $rx->reference_code,
                'payment_method' => 'cash',
            ]);
        }
    }

    private function backdate(Order $order, Carbon $at): void
    {
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['created_at' => $at, 'updated_at' => $at, 'fulfilled_at' => $order->fulfilled_at ? $at : null]);
        StockMovement::withoutGlobalScopes()->where('reference_type', $order->getMorphClass())->where('reference_id', $order->id)->update(['created_at' => $at, 'updated_at' => $at]);
        ActivityLog::withoutGlobalScopes()->where('entity_type', 'Order')->where('entity_id', $order->id)->update(['created_at' => $at]);
    }

    private function user(?Tenant $tenant, string $name, string $email, string $phone, string $role, ?string $license = null): User
    {
        $user = User::create([
            'tenant_id' => $tenant?->id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => self::PASSWORD,
            'license_number' => $license,
            'city' => $tenant?->city ?? 'Addis Ababa',
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function name(int $i): string
    {
        $names = ['Tigist Haile', 'Yared Mengistu', 'Bethlehem Assefa', 'Henok Girma', 'Rahel Kassa', 'Natnael Bekele',
            'Saron Tesfaye', 'Mulugeta Alemayehu', 'Hiwot Lemma', 'Dagim Worku', 'Feven Hailu', 'Robel Abebe',
            'Betelhem Tadesse', 'Kaleb Demissie'];

        return $names[$i % count($names)];
    }
}
