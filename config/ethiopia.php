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
        ['value' => 'chapa', 'label' => 'Chapa', 'label_am' => 'ቻፓ'],
        ['value' => 'bank_transfer', 'label' => 'Bank transfer', 'label_am' => 'የባንክ ዝውውር'],
        ['value' => 'insurance', 'label' => 'Health insurance (CBHI / EHIS)', 'label_am' => 'የጤና መድን'],
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
