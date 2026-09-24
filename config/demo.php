<?php

/*
|--------------------------------------------------------------------------
| Demo configuration
|--------------------------------------------------------------------------
|
| Two independent demo setups share this file:
|
|  1. DEMO MODE (`enabled`, `password`, `personas`) — a separate demo
|     deployment where /admin itself runs against a demo database
|     (APP_ENV=demo, DEMO_MODE=true; see .env.demo.example and
|     App\Providers\DemoModeServiceProvider). Dormant everywhere else.
|
|  2. THE /demo PANEL (`panel_enabled` and everything below it) — the same
|     application, in the SAME deployment, serving the admin UI at /demo
|     against its own demo database (see App\Support\Demo\DemoContext).
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

    /*
    |--------------------------------------------------------------------------
    | Demo Panel
    |--------------------------------------------------------------------------
    |
    | The /demo panel: the admin application on its own demo database (see
    | App\Support\Demo\DemoContext). Switching `panel_enabled` off makes every
    | /demo URL answer 404 without touching /admin or either database.
    |
    | `connection` names the entry in config/database.php that holds the
    | sandbox's data. `main_connection` is the main application's connection,
    | captured here at config-load time because commands such as
    | `db:seed --database=demo` switch the runtime default.
    | App\Support\Demo\DemoDatabase refuses to run if the two resolve to the
    | same database.
    |
    */

    'panel_enabled' => (bool) env('DEMO_PANEL_ENABLED', true),

    /*
     * Whether the scheduler also runs the demo counterparts of the
     * scheduled jobs (see routes/console.php) against the demo database.
     */
    'schedule_enabled' => (bool) env('DEMO_SCHEDULE_ENABLED', true),

    'connection' => 'demo',

    'main_connection' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Seeded Demo Logins
    |--------------------------------------------------------------------------
    |
    | Read by Database\Seeders\Demo\DemoOrganisationSeeder: `email` is the
    | demo Admin login, and every seeded demo login shares `password`. The
    | password is never hardcoded: when DEMO_USER_PASSWORD is empty the
    | seeder generates a random one and prints it once.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | "View as" Role Switcher
    |--------------------------------------------------------------------------
    |
    | The one-click role switcher in the /demo topbar signs the visitor in as
    | one of these seeded demo logins, so a demonstration can show what each
    | role sees and may change. Keys are the URL slugs; the emails match the
    | logins created by Database\Seeders\Demo\DemoOrganisationSeeder. Only
    | ever resolved against the DEMO database.
    |
    */

    'role_logins' => [
        'admin' => ['label' => 'Admin', 'email' => env('DEMO_USER_EMAIL', 'demo@example.com')],
        'business-head' => ['label' => 'Business Head', 'email' => 'demo.businesshead@demo-fynnon.test'],
        'cluster-manager' => ['label' => 'Cluster Manager', 'email' => 'demo.cluster@demo-fynnon.test'],
        'manager' => ['label' => 'Manager', 'email' => 'demo.manager@demo-fynnon.test'],
        'team-leader' => ['label' => 'Team Leader', 'email' => 'demo.teamleader@demo-fynnon.test'],
        'caller' => ['label' => 'Caller', 'email' => 'demo.caller@demo-fynnon.test'],
        'mis' => ['label' => 'MIS', 'email' => 'demo.mis@demo-fynnon.test'],
        'accounts' => ['label' => 'Accounts', 'email' => 'demo.accounts@demo-fynnon.test'],
        'it' => ['label' => 'IT', 'email' => 'demo.it@demo-fynnon.test'],
        'other-bank-support' => ['label' => 'Other Bank Support', 'email' => 'demo.otherbank@demo-fynnon.test'],
    ],

    'user' => [
        'name' => env('DEMO_USER_NAME', 'FYNN-ON Demo User'),
        'email' => env('DEMO_USER_EMAIL', 'demo@example.com'),
        'password' => env('DEMO_USER_PASSWORD'),
    ],
];
