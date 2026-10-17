<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bureau driver
    |--------------------------------------------------------------------------
    |
    | Which App\Services\Cibil\CibilClient answers a pull:
    |  - "unconfigured" (default): every pull is recorded as Failed with
    |    "API not connected", so nothing pretends to work before it does.
    |  - "fake": a deterministic stand-in for local development. Ignored in
    |    production. The /demo environment always uses it.
    | The live bureau driver is added once the API details are shared.
    */
    'driver' => env('CIBIL_DRIVER', 'unconfigured'),

];
