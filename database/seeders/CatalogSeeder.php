<?php

namespace Database\Seeders;

use App\Models\Medicine;
use App\Models\MedicineCategory;
use Illuminate\Database\Seeder;

/**
 * Master catalogue drawn from the Ethiopian Essential Medicines List.
 * `ref_price` is a typical ETB retail price per dispensing unit, used to seed pharmacy prices.
 */
class CatalogSeeder extends Seeder
{
    public const CATEGORIES = [
        'analgesics' => ['Pain & Fever', 'የህመምና ትኩሳት', 'pain'],
        'antibiotics' => ['Antibiotics', 'ፀረ-ባክቴሪያ', 'shield'],
        'antimalarials' => ['Antimalarials', 'የወባ መድኃኒቶች', 'bug'],
        'cardiovascular' => ['Heart & Blood Pressure', 'የልብና የደም ግፊት', 'heart'],
        'diabetes' => ['Diabetes', 'የስኳር በሽታ', 'drop'],
        'gastrointestinal' => ['Stomach & Digestion', 'የጨጓራና አንጀት', 'stomach'],
        'respiratory' => ['Respiratory & Allergy', 'የመተንፈሻና አለርጂ', 'lungs'],
        'vitamins' => ['Vitamins & Supplements', 'ቫይታሚኖችና ማዕድናት', 'sparkles'],
        'dermatology' => ['Skin Care', 'የቆዳ ህክምና', 'hand'],
        'antiparasitic' => ['Deworming & Antiparasitic', 'የትላትል መድኃኒቶች', 'worm'],
        'eye-ear' => ['Eye & Ear', 'የዓይንና ጆሮ', 'eye'],
        'mental-health' => ['Mental Health & Neurology', 'የአእምሮና ነርቭ ጤና', 'brain'],
        'maternal' => ['Maternal & Family Planning', 'የእናቶችና ቤተሰብ ዕቅድ', 'baby'],
    ];

    // [category, generic, brand, manufacturer, form, strength, unit, rx, controlled, ref_price, storage]
    public const MEDICINES = [
        ['analgesics', 'Paracetamol', null, 'EPHARM', 'Tablet', '500mg', 'tablet', false, false, 2.0, 'Store below 30°C'],
        ['analgesics', 'Paracetamol', 'Calpol', 'Cadila Pharmaceuticals Ethiopia', 'Syrup', '120mg/5ml', 'bottle', false, false, 68, 'Store below 30°C'],
        ['analgesics', 'Ibuprofen', null, 'Addis Pharmaceutical Factory', 'Tablet', '400mg', 'tablet', false, false, 3.0, null],
        ['analgesics', 'Diclofenac Sodium', 'Voltaren', 'Novartis', 'Tablet', '50mg', 'tablet', false, false, 4.5, null],
        ['analgesics', 'Tramadol', null, 'Julphar Ethiopia', 'Capsule', '50mg', 'capsule', true, true, 6.0, 'Controlled - keep locked'],
        ['antibiotics', 'Amoxicillin', null, 'EPHARM', 'Capsule', '500mg', 'capsule', true, false, 5.0, null],
        ['antibiotics', 'Amoxicillin', null, 'EPHARM', 'Suspension', '250mg/5ml', 'bottle', true, false, 85, 'Refrigerate after reconstitution'],
        ['antibiotics', 'Amoxicillin + Clavulanic Acid', 'Augmentin', 'GSK', 'Tablet', '625mg', 'tablet', true, false, 28, null],
        ['antibiotics', 'Ciprofloxacin', null, 'Cadila Pharmaceuticals Ethiopia', 'Tablet', '500mg', 'tablet', true, false, 4.0, null],
        ['antibiotics', 'Azithromycin', 'Zithromax', 'Pfizer', 'Tablet', '500mg', 'tablet', true, false, 25, null],
        ['antibiotics', 'Doxycycline', null, 'East African Pharmaceuticals', 'Capsule', '100mg', 'capsule', true, false, 3.0, null],
        ['antibiotics', 'Sulfamethoxazole + Trimethoprim', 'Cotrimoxazole', 'EPHARM', 'Tablet', '800/160mg', 'tablet', true, false, 2.0, null],
        ['antibiotics', 'Metronidazole', null, 'EPHARM', 'Tablet', '250mg', 'tablet', true, false, 1.5, null],
        ['antibiotics', 'Ceftriaxone', 'Rocephin', 'Roche', 'Injection', '1g', 'vial', true, false, 120, 'Store below 25°C'],
        ['antibiotics', 'Cephalexin', null, 'Julphar Ethiopia', 'Capsule', '500mg', 'capsule', true, false, 6.0, null],
        ['antimalarials', 'Artemether + Lumefantrine', 'Coartem', 'Novartis', 'Tablet', '20/120mg', 'tablet', true, false, 12, null],
        ['antimalarials', 'Chloroquine Phosphate', null, 'EPHARM', 'Tablet', '250mg', 'tablet', true, false, 2.0, null],
        ['antimalarials', 'Primaquine', null, 'Sanofi', 'Tablet', '7.5mg', 'tablet', true, false, 3.0, null],
        ['antimalarials', 'Artesunate', null, 'Guilin', 'Injection', '60mg', 'vial', true, false, 180, null],
        ['cardiovascular', 'Amlodipine', 'Norvasc', 'Pfizer', 'Tablet', '5mg', 'tablet', true, false, 2.5, null],
        ['cardiovascular', 'Enalapril', null, 'Cadila Pharmaceuticals Ethiopia', 'Tablet', '10mg', 'tablet', true, false, 2.0, null],
        ['cardiovascular', 'Hydrochlorothiazide', null, 'EPHARM', 'Tablet', '25mg', 'tablet', true, false, 1.0, null],
        ['cardiovascular', 'Atenolol', null, 'Addis Pharmaceutical Factory', 'Tablet', '50mg', 'tablet', true, false, 2.0, null],
        ['cardiovascular', 'Nifedipine Retard', null, 'Bayer', 'Tablet', '20mg', 'tablet', true, false, 3.5, null],
        ['cardiovascular', 'Atorvastatin', 'Lipitor', 'Pfizer', 'Tablet', '20mg', 'tablet', true, false, 6.0, null],
        ['diabetes', 'Metformin', null, 'EPHARM', 'Tablet', '500mg', 'tablet', true, false, 1.5, null],
        ['diabetes', 'Glibenclamide', null, 'Cadila Pharmaceuticals Ethiopia', 'Tablet', '5mg', 'tablet', true, false, 1.0, null],
        ['diabetes', 'Insulin NPH (Isophane)', 'Insulatard', 'Novo Nordisk', 'Injection', '100 IU/ml, 10ml', 'vial', true, false, 350, 'Refrigerate 2-8°C. Do not freeze.'],
        ['gastrointestinal', 'Omeprazole', null, 'Julphar Ethiopia', 'Capsule', '20mg', 'capsule', false, false, 3.0, null],
        ['gastrointestinal', 'Oral Rehydration Salts (ORS)', null, 'EPHARM', 'Powder (sachet)', '20.5g', 'sachet', false, false, 10, null],
        ['gastrointestinal', 'Zinc Sulfate', null, 'Nutriset', 'Tablet', '20mg dispersible', 'tablet', false, false, 3.0, null],
        ['gastrointestinal', 'Aluminium + Magnesium Hydroxide', 'Antacid', 'Addis Pharmaceutical Factory', 'Suspension', '200ml', 'bottle', false, false, 95, null],
        ['gastrointestinal', 'Hyoscine Butylbromide', 'Buscopan', 'Sanofi', 'Tablet', '10mg', 'tablet', false, false, 4.0, null],
        ['respiratory', 'Salbutamol', 'Ventolin', 'GSK', 'Inhaler', '100mcg/dose', 'inhaler', true, false, 260, null],
        ['respiratory', 'Salbutamol', null, 'EPHARM', 'Tablet', '4mg', 'tablet', true, false, 1.0, null],
        ['respiratory', 'Cetirizine', null, 'Cadila Pharmaceuticals Ethiopia', 'Tablet', '10mg', 'tablet', false, false, 3.0, null],
        ['respiratory', 'Chlorpheniramine Maleate', null, 'EPHARM', 'Tablet', '4mg', 'tablet', false, false, 1.0, null],
        ['respiratory', 'Dextromethorphan', null, 'Julphar Ethiopia', 'Syrup', '15mg/5ml, 100ml', 'bottle', false, false, 80, null],
        ['vitamins', 'Ferrous Sulfate + Folic Acid', null, 'EPHARM', 'Tablet', '200mg/0.4mg', 'tablet', false, false, 1.0, null],
        ['vitamins', 'Vitamin B Complex', null, 'Addis Pharmaceutical Factory', 'Tablet', 'Standard', 'tablet', false, false, 1.0, null],
        ['vitamins', 'Ascorbic Acid (Vitamin C)', null, 'East African Pharmaceuticals', 'Tablet', '500mg', 'tablet', false, false, 3.0, null],
        ['vitamins', 'Folic Acid', null, 'EPHARM', 'Tablet', '5mg', 'tablet', false, false, 1.0, null],
        ['dermatology', 'Ketoconazole', 'Nizoral', 'Janssen', 'Cream', '2%, 15g', 'tube', false, false, 90, null],
        ['dermatology', 'Clotrimazole', 'Canesten', 'Bayer', 'Cream', '1%, 20g', 'tube', false, false, 60, null],
        ['dermatology', 'Hydrocortisone', null, 'Julphar Ethiopia', 'Cream', '1%, 15g', 'tube', false, false, 70, null],
        ['antiparasitic', 'Albendazole', 'Zentel', 'GSK', 'Tablet', '400mg', 'tablet', false, false, 8.0, null],
        ['antiparasitic', 'Mebendazole', null, 'EPHARM', 'Tablet', '100mg', 'tablet', false, false, 3.0, null],
        ['antiparasitic', 'Tinidazole', null, 'Cadila Pharmaceuticals Ethiopia', 'Tablet', '500mg', 'tablet', true, false, 4.0, null],
        ['antiparasitic', 'Praziquantel', 'Biltricide', 'Bayer', 'Tablet', '600mg', 'tablet', true, false, 10, null],
        ['eye-ear', 'Tetracycline', null, 'EPHARM', 'Ointment', '1% eye ointment, 5g', 'tube', false, false, 35, null],
        ['eye-ear', 'Chloramphenicol', null, 'Julphar Ethiopia', 'Eye drops', '0.5%, 10ml', 'bottle', true, false, 45, 'Refrigerate after opening'],
        ['mental-health', 'Diazepam', 'Valium', 'Roche', 'Tablet', '5mg', 'tablet', true, true, 2.0, 'Controlled - keep locked'],
        ['mental-health', 'Amitriptyline', null, 'Cadila Pharmaceuticals Ethiopia', 'Tablet', '25mg', 'tablet', true, false, 2.0, null],
        ['mental-health', 'Carbamazepine', 'Tegretol', 'Novartis', 'Tablet', '200mg', 'tablet', true, false, 4.0, null],
        ['mental-health', 'Phenobarbital', null, 'EPHARM', 'Tablet', '30mg', 'tablet', true, true, 1.5, 'Controlled - keep locked'],
        ['maternal', 'Medroxyprogesterone Acetate', 'Depo-Provera', 'Pfizer', 'Injection', '150mg/ml', 'vial', true, false, 45, null],
        ['maternal', 'Levonorgestrel', 'Postinor-2', 'Gedeon Richter', 'Tablet', '0.75mg', 'tablet', false, false, 40, null],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $slug => [$name, $nameAm, $icon]) {
            MedicineCategory::updateOrCreate(['slug' => $slug], ['name' => $name, 'name_am' => $nameAm, 'icon' => $icon]);
        }

        $categories = MedicineCategory::pluck('id', 'slug');

        foreach (self::MEDICINES as $row) {
            [$cat, $generic, $brand, $maker, $form, $strength, $unit, $rx, $controlled, , $storage] = $row;

            Medicine::updateOrCreate(
                ['generic_name' => $generic, 'strength' => $strength, 'dosage_form' => $form],
                [
                    'category_id' => $categories[$cat],
                    'brand_name' => $brand,
                    'manufacturer' => $maker,
                    'unit' => $unit,
                    'prescription_required' => $rx,
                    'is_controlled' => $controlled,
                    'storage_conditions' => $storage ?? 'Store in a cool, dry place below 30°C',
                    'is_active' => true,
                ]
            );
        }
    }

    /** Reference price per unit keyed by "generic|strength|form". */
    public static function referencePrices(): array
    {
        return collect(self::MEDICINES)->mapWithKeys(fn ($r) => ["{$r[1]}|{$r[5]}|{$r[4]}" => $r[9]])->all();
    }
}
