<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Demo hospitals and clinics (fictional names) placed on the map, so patients' "near me" search shows
 * health facilities as well as pharmacies. Safe to re-run: existing names are left untouched.
 */
class HealthFacilitiesSeeder extends Seeder
{
    // [name, sub_city, woreda, address, lat, lng, 24h, hours, modules, phone]
    private const FACILITIES = [
        ['Bole Medhin Medical Center', 'Bole', '03', 'Africa Avenue, behind Dembel City Center', 9.0005, 38.7845, true,
            'Emergency 24/7, OPD Mon-Sun 8:00-20:00', ['clinical', 'lab', 'billing', 'insurance', 'pharmacy'], '+251116611200'],
        ['Arada Tena Family Clinic', 'Arada', '01', 'Piassa, near Taitu Hotel', 9.0330, 38.7510, false,
            'Mon-Sat 8:00-18:00', ['clinical', 'lab', 'billing'], '+251111553300'],
        ['Yeka Mother & Child Clinic', 'Yeka', '06', 'Megenagna, Salam building 2nd floor', 9.0215, 38.8005, false,
            'Mon-Sat 8:00-17:30 · ANC, delivery referral, vaccination', ['clinical', 'lab', 'billing', 'insurance'], '+251116674400'],
        ['Kirkos Hiwot Specialty Hospital', 'Kirkos', '08', 'Kazanchis, near Intercontinental Hotel', 9.0168, 38.7660, true,
            'Emergency 24/7, specialty OPD Mon-Fri 8:30-17:00', ['clinical', 'lab', 'billing', 'insurance', 'pharmacy'], '+251115578800'],
        ['Adama Hiwot General Hospital', null, '02', 'Franko area, Djibouti road', 8.5452, 39.2690, true,
            'Emergency 24/7, OPD Mon-Sat 8:00-17:00', ['clinical', 'lab', 'billing', 'insurance', 'pharmacy'], '+251221114400'],
    ];

    public function run(): void
    {
        foreach (self::FACILITIES as $i => [$name, $sub, $woreda, $address, $lat, $lng, $h24, $hours, $modules, $phone]) {
            if (Tenant::where('name', $name)->exists()) {
                continue;
            }
            $adama = str_starts_with($name, 'Adama');
            Tenant::create([
                'name' => $name, 'type' => Tenant::TYPE_HOSPITAL, 'status' => 'active',
                'license_number' => 'MOH/HF/'.(2100 + $i * 41).'/2016', 'tin_number' => (string) (2000456780 + $i * 1013),
                'phone' => $phone, 'region' => $adama ? 'Oromia' : 'Addis Ababa', 'city' => $adama ? 'Adama' : 'Addis Ababa',
                'sub_city' => $sub, 'woreda' => $woreda, 'address_line' => $address, 'latitude' => $lat, 'longitude' => $lng,
                'opening_hours' => $hours, 'is_24_hours' => $h24, 'modules' => $modules,
                'description' => 'Demo health facility for the MedLink map.', 'approved_at' => now()->subMonths(3),
            ]);
        }
    }
}
