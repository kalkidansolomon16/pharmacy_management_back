<?php

namespace Database\Seeders;

use App\Models\HospitalSpecialty;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * The starting list of hospital specialties, and specialties for the demo hospitals.
 * Safe to run again: it never changes specialties the platform team edited, nor hospitals that chose their own.
 */
class HospitalSpecialtiesSeeder extends Seeder
{
    /** Demo facilities (by name) and what they offer. */
    private const DEMO = [
        'Entoto Hills General Hospital' => ['General Medicine', 'Internal Medicine', 'Pediatrics', 'Gynecology & Obstetrics', 'General Surgery', 'Laboratory', 'Emergency'],
        'Kirkos Hiwot Specialty Hospital' => ['Orthopedics & Spine', 'Cardiology', 'Neurology', 'Radiology & Imaging', 'Emergency', 'Laboratory'],
        'Arada Tena Family Clinic' => ['General Medicine', 'Pediatrics', 'Dental', 'Laboratory'],
        'Yeka Mother & Child Clinic' => ['Pediatrics', 'Gynecology & Obstetrics', 'General Medicine'],
        'Bole Medhin Medical Center' => ['General Medicine', 'Dermatology (Skin)', 'Ophthalmology (Eye)', 'ENT (Ear, Nose & Throat)', 'Physiotherapy'],
        'Adama Hiwot General Hospital' => ['General Medicine', 'Internal Medicine', 'Orthopedics & Spine', 'General Surgery', 'Emergency'],
        'Dire Dawa Legehare Clinic' => ['General Medicine', 'Laboratory'],
    ];

    public function run(): void
    {
        foreach (HospitalSpecialty::DEFAULTS as $i => [$name, $am, $icon]) {
            HospitalSpecialty::firstOrCreate(['name' => $name], ['name_am' => $am, 'icon' => $icon, 'sort_order' => ($i + 1) * 10]);
        }

        $ids = HospitalSpecialty::pluck('id', 'name');
        foreach (self::DEMO as $hospital => $specialties) {
            $tenant = Tenant::where('name', $hospital)->where('type', Tenant::TYPE_HOSPITAL)->first();
            if ($tenant && $tenant->specialties()->doesntExist()) {
                $tenant->specialties()->sync($ids->only($specialties)->values());
            }
        }
    }
}
