<?php

/*
|--------------------------------------------------------------------------
| Demo seed accounts (local development / demo only)
|--------------------------------------------------------------------------
|
| Logins created by the database seeders, one per role. These are NOT
| production credentials: override them in .env and never reuse them on a
| real deployment. The live system's credentials are never used here.
|
| placement: "hq" = Head office (company scope), "branch" = Kakonko branch,
| "zone" = KANDA YA ZIWA zone (which contains Kakonko).
|
*/

return [
    'admin_phone' => env('DEMO_ADMIN_PHONE', '0700000000'),
    'admin_password' => env('DEMO_ADMIN_PASSWORD', 'password'),
    'admin_email' => env('DEMO_ADMIN_EMAIL', 'admin@example.com'),

    'branch' => 'Kakonko',
    'zone' => 'KANDA YA ZIWA',
    'hq_branch' => 'Head office',

    /*
     * Role accounts in addition to the Super Admin above (role key => account).
     */
    'accounts' => [
        'admin' => [
            'phone' => env('DEMO_HQ_ADMIN_PHONE', '0700000008'),
            'password' => env('DEMO_HQ_ADMIN_PASSWORD', 'password'),
            'employee_number' => 'MK-9082024',
            'placement' => 'hq',
        ],
        'teller' => [
            'phone' => env('DEMO_TELLER_PHONE', '0700000001'),
            'password' => env('DEMO_TELLER_PASSWORD', 'password'),
            'employee_number' => 'MK-9012024',
            'placement' => 'branch',
        ],
        'finance' => [
            'phone' => env('DEMO_FINANCE_PHONE', '0700000002'),
            'password' => env('DEMO_FINANCE_PASSWORD', 'password'),
            'employee_number' => 'MK-9022024',
            'placement' => 'hq',
        ],
        'zone_manager' => [
            'phone' => env('DEMO_ZONE_MANAGER_PHONE', '0700000003'),
            'password' => env('DEMO_ZONE_MANAGER_PASSWORD', 'password'),
            'employee_number' => 'MK-9032024',
            'placement' => 'zone',
        ],
        'branch_manager' => [
            'phone' => env('DEMO_BRANCH_MANAGER_PHONE', '0700000004'),
            'password' => env('DEMO_BRANCH_MANAGER_PASSWORD', 'password'),
            'employee_number' => 'MK-9042024',
            'placement' => 'branch',
        ],
        'loan_officer' => [
            'phone' => env('DEMO_LOAN_OFFICER_PHONE', '0700000005'),
            'password' => env('DEMO_LOAN_OFFICER_PASSWORD', 'password'),
            'employee_number' => 'MK-9052024',
            'placement' => 'branch',
        ],
        'credit_officer' => [
            'phone' => env('DEMO_CREDIT_OFFICER_PHONE', '0700000006'),
            'password' => env('DEMO_CREDIT_OFFICER_PASSWORD', 'password'),
            'employee_number' => 'MK-9062024',
            'placement' => 'hq',
        ],
        'hr' => [
            'phone' => env('DEMO_HR_PHONE', '0700000007'),
            'password' => env('DEMO_HR_PASSWORD', 'password'),
            'employee_number' => 'MK-9072024',
            'placement' => 'hq',
        ],
    ],
];
