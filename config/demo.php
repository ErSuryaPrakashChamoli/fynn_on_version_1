<?php

/*
|--------------------------------------------------------------------------
| Demo environment
|--------------------------------------------------------------------------
|
| The client-facing demo is the real /admin panel, unchanged, running
| against its own database (see .env.demo.example). Nothing here does
| anything unless DEMO_MODE is true, so the live application never sees
| the persona switcher, the switch route or the refresh command.
|
| Every persona is a seeded login in the demo database. The switcher may
| only ever sign in as one of these addresses.
|
*/

return [

    'enabled' => (bool) env('DEMO_MODE', false),

    'password' => env('DEMO_PASSWORD', 'Demo@123'),

    /*
     * Keyed by the slug the switch route takes. The order here is the
     * order the login page and the topbar switcher list them in.
     */
    'personas' => [
        'admin' => [
            'label' => 'Admin',
            'email' => 'admin@fynnon-demo.test',
            'description' => 'Company-wide view of every module and setting.',
        ],
        'business-head' => [
            'label' => 'Business Head',
            'email' => 'business.head@fynnon-demo.test',
            'description' => 'Runs a business line of clusters.',
        ],
        'cluster-manager' => [
            'label' => 'Cluster Manager',
            'email' => 'cluster.manager@fynnon-demo.test',
            'description' => 'Oversees several managers and their teams.',
        ],
        'manager' => [
            'label' => 'Manager',
            'email' => 'manager@fynnon-demo.test',
            'description' => 'Owns team leaders, callers and their targets.',
        ],
        'team-leader' => [
            'label' => 'Team Leader',
            'email' => 'team.leader@fynnon-demo.test',
            'description' => 'Leads a team of callers day to day.',
        ],
        'caller' => [
            'label' => 'Caller',
            'email' => 'caller@fynnon-demo.test',
            'description' => 'Works leads, follow-ups and customer journeys.',
        ],
        'accounts' => [
            'label' => 'Accounts',
            'email' => 'accounts@fynnon-demo.test',
            'description' => 'Account verification and settlements.',
        ],
        'mis' => [
            'label' => 'MIS',
            'email' => 'mis@fynnon-demo.test',
            'description' => 'Reporting, imports and data management.',
        ],
        'other-bank-support' => [
            'label' => 'Other Bank Support',
            'email' => 'other.bank@fynnon-demo.test',
            'description' => 'Customers routed to partner banks.',
        ],
        'it' => [
            'label' => 'IT',
            'email' => 'it@fynnon-demo.test',
            'description' => 'Users, access and system settings.',
        ],
    ],

];
