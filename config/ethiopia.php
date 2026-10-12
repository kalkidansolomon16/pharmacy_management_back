<?php

/*
| Reference data for the Ethiopian context, served to the frontend via GET /api/public/meta
*/

return [
    'currency' => ['code' => 'ETB', 'symbol' => 'Br', 'name' => 'Ethiopian Birr', 'name_am' => 'ብር'],

    'timezone' => 'Africa/Addis_Ababa',

    'regions' => [
        ['name' => 'Addis Ababa', 'name_am' => 'አዲስ አበባ', 'cities' => ['Addis Ababa']],
        ['name' => 'Dire Dawa', 'name_am' => 'ድሬዳዋ', 'cities' => ['Dire Dawa']],
        ['name' => 'Oromia', 'name_am' => 'ኦሮሚያ', 'cities' => ['Adama', 'Bishoftu', 'Jimma', 'Shashemene', 'Nekemte', 'Ambo', 'Asella', 'Sebeta', 'Burayu', 'Robe']],
        ['name' => 'Amhara', 'name_am' => 'አማራ', 'cities' => ['Bahir Dar', 'Gondar', 'Dessie', 'Debre Markos', 'Debre Birhan', 'Kombolcha', 'Woldia', 'Debre Tabor']],
        ['name' => 'Tigray', 'name_am' => 'ትግራይ', 'cities' => ['Mekelle', 'Adigrat', 'Axum', 'Shire']],
        ['name' => 'Sidama', 'name_am' => 'ሲዳማ', 'cities' => ['Hawassa', 'Yirgalem']],
        ['name' => 'South Ethiopia', 'name_am' => 'ደቡብ ኢትዮጵያ', 'cities' => ['Arba Minch', 'Wolaita Sodo', 'Dilla']],
        ['name' => 'Central Ethiopia', 'name_am' => 'ማዕከላዊ ኢትዮጵያ', 'cities' => ['Hosaena', 'Butajira', 'Welkite', 'Worabe']],
        ['name' => 'South West Ethiopia', 'name_am' => 'ደቡብ ምዕራብ ኢትዮጵያ', 'cities' => ['Bonga', 'Mizan Teferi', 'Tepi']],
        ['name' => 'Somali', 'name_am' => 'ሶማሌ', 'cities' => ['Jijiga', 'Gode', 'Kebri Dahar']],
        ['name' => 'Afar', 'name_am' => 'አፋር', 'cities' => ['Semera', 'Asaita', 'Awash']],
        ['name' => 'Benishangul-Gumuz', 'name_am' => 'ቤኒሻንጉል ጉሙዝ', 'cities' => ['Asosa']],
        ['name' => 'Gambela', 'name_am' => 'ጋምቤላ', 'cities' => ['Gambela']],
        ['name' => 'Harari', 'name_am' => 'ሐረሪ', 'cities' => ['Harar']],
    ],

    'addis_sub_cities' => [
        'Addis Ketema', 'Akaky Kaliti', 'Arada', 'Bole', 'Gullele', 'Kirkos',
        'Kolfe Keranio', 'Lemi Kura', 'Lideta', 'Nifas Silk-Lafto', 'Yeka',
    ],

    'payment_methods' => [
        ['value' => 'cash', 'label' => 'Cash', 'label_am' => 'ጥሬ ገንዘብ'],
        ['value' => 'telebirr', 'label' => 'telebirr', 'label_am' => 'ቴሌብር'],
        ['value' => 'cbe_birr', 'label' => 'CBE Birr', 'label_am' => 'ሲቢኢ ብር'],
        ['value' => 'mpesa', 'label' => 'M-Pesa', 'label_am' => 'ኤም-ፔሳ'],
        ['value' => 'chapa', 'label' => 'Chapa', 'label_am' => 'ቻፓ'],
        ['value' => 'bank_transfer', 'label' => 'Bank transfer', 'label_am' => 'የባንክ ዝውውር'],
        ['value' => 'insurance', 'label' => 'Health insurance (CBHI / EHIS)', 'label_am' => 'የጤና መድን'],
        ['value' => 'credit', 'label' => 'Corporate credit', 'label_am' => 'የድርጅት ዱቤ'],
    ],

    /*
    | Indirect tax. Prices are stored tax-exclusive; most medicines and medical services are exempt.
    */
    'tax' => [
        'vat_rate' => 0.15,
        'tot_goods_rate' => 0.02,
        'tot_services_rate' => 0.10,
    ],

    'tax_regimes' => [
        ['value' => 'exempt', 'label' => 'Not registered / exempt'],
        ['value' => 'vat', 'label' => 'VAT registered (15%)'],
        ['value' => 'tot', 'label' => 'Turnover tax (TOT 2% goods / 10% services)'],
    ],

    'languages' => [
        ['value' => 'en', 'label' => 'English'],
        ['value' => 'am', 'label' => 'አማርኛ'],
        ['value' => 'om', 'label' => 'Afaan Oromoo'],
    ],

    'departments' => ['General OPD', 'Pediatrics', 'Internal Medicine', 'Gynecology & Obstetrics', 'Surgery', 'Emergency', 'Dental', 'Ophthalmology'],

    'payer_types' => [
        ['value' => 'cbhi', 'label' => 'Community-Based Health Insurance (CBHI)', 'label_am' => 'የማህበረሰብ ጤና መድን'],
        ['value' => 'private_insurance', 'label' => 'Private insurance', 'label_am' => 'የግል መድን'],
        ['value' => 'corporate', 'label' => 'Corporate credit agreement', 'label_am' => 'የድርጅት ዱቤ ስምምነት'],
        ['value' => 'government', 'label' => 'Government / EHIS', 'label_am' => 'የመንግሥት'],
        ['value' => 'ngo', 'label' => 'NGO / sponsor', 'label_am' => 'ግብረ-ሰናይ'],
    ],

    /*
    | Common diagnoses in Ethiopian outpatient settings, ICD-10 coded (searchable when consulting).
    | The DHIS2 report groups OPD morbidity by these codes.
    */
    'diagnoses' => [
        ['code' => 'B54', 'name' => 'Malaria, unspecified'],
        ['code' => 'B50.9', 'name' => 'Plasmodium falciparum malaria'],
        ['code' => 'B51.9', 'name' => 'Plasmodium vivax malaria'],
        ['code' => 'A01.0', 'name' => 'Typhoid fever'],
        ['code' => 'A75.9', 'name' => 'Typhus fever'],
        ['code' => 'A09', 'name' => 'Acute gastroenteritis / diarrhoea'],
        ['code' => 'A06.9', 'name' => 'Amoebiasis'],
        ['code' => 'A07.1', 'name' => 'Giardiasis'],
        ['code' => 'B82.9', 'name' => 'Intestinal parasitism'],
        ['code' => 'J06.9', 'name' => 'Acute upper respiratory infection'],
        ['code' => 'J03.9', 'name' => 'Acute tonsillitis'],
        ['code' => 'J18.9', 'name' => 'Pneumonia'],
        ['code' => 'J45.9', 'name' => 'Asthma'],
        ['code' => 'A15.9', 'name' => 'Pulmonary tuberculosis'],
        ['code' => 'B24', 'name' => 'HIV disease'],
        ['code' => 'N39.0', 'name' => 'Urinary tract infection'],
        ['code' => 'K29.7', 'name' => 'Gastritis'],
        ['code' => 'K30', 'name' => 'Dyspepsia'],
        ['code' => 'I10', 'name' => 'Essential hypertension'],
        ['code' => 'E11.9', 'name' => 'Type 2 diabetes mellitus'],
        ['code' => 'E10.9', 'name' => 'Type 1 diabetes mellitus'],
        ['code' => 'D64.9', 'name' => 'Anaemia'],
        ['code' => 'E46', 'name' => 'Malnutrition'],
        ['code' => 'H66.9', 'name' => 'Otitis media'],
        ['code' => 'H10.9', 'name' => 'Conjunctivitis'],
        ['code' => 'L08.9', 'name' => 'Skin infection'],
        ['code' => 'B35.9', 'name' => 'Dermatophytosis (fungal skin infection)'],
        ['code' => 'M54.5', 'name' => 'Low back pain'],
        ['code' => 'R51', 'name' => 'Headache'],
        ['code' => 'F32.9', 'name' => 'Depressive episode'],
        ['code' => 'G40.9', 'name' => 'Epilepsy'],
        ['code' => 'O80', 'name' => 'Normal delivery'],
        ['code' => 'Z34.9', 'name' => 'Antenatal care, normal pregnancy'],
        ['code' => 'Z30.0', 'name' => 'Family planning counselling'],
        ['code' => 'T14.9', 'name' => 'Injury / trauma'],
    ],

    'dosage_forms' => [
        'Tablet', 'Capsule', 'Syrup', 'Suspension', 'Injection', 'IV fluid', 'Cream', 'Ointment',
        'Gel', 'Eye drops', 'Ear drops', 'Inhaler', 'Suppository', 'Powder (sachet)',
    ],

    'suppliers' => [
        'EPSS (Ethiopian Pharmaceutical Supply Service)', 'EPHARM', 'Addis Pharmaceutical Factory',
        'Cadila Pharmaceuticals Ethiopia', 'East African Pharmaceuticals', 'Julphar Ethiopia', 'Private importer',
    ],

    'blood_groups' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
];
