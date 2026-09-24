<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Panel
    |--------------------------------------------------------------------------
    |
    | The /demo sandbox. Switching `enabled` off makes every /demo URL answer
    | 404 without touching the main /admin panel or either database.
    |
    | `connection` names the entry in config/database.php that holds the
    | sandbox's data. `main_connection` is the main application's connection,
    | captured here at config-load time because commands such as
    | `db:seed --database=demo` switch the runtime default.
    | App\Support\Demo\DemoDatabase refuses to run if the two resolve to the
    | same database.
    |
    */

    'enabled' => (bool) env('DEMO_PANEL_ENABLED', true),

    'connection' => 'demo',

    'main_connection' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Seeded Demo Login
    |--------------------------------------------------------------------------
    |
    | Read by Database\Seeders\Demo\DemoUserSeeder. The password is never
    | hardcoded: when DEMO_USER_PASSWORD is empty the seeder generates a
    | random one and prints it once.
    |
    */

    'user' => [
        'name' => env('DEMO_USER_NAME', 'FYNN-ON Demo User'),
        'email' => env('DEMO_USER_EMAIL', 'demo@example.com'),
        'password' => env('DEMO_USER_PASSWORD'),
    ],

];
