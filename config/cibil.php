<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bureau driver
    |--------------------------------------------------------------------------
    |
    | Which App\Services\Cibil\CibilClient answers a request:
    |  - "unconfigured" (default): every request is recorded as Failed with
    |    "API not connected", so nothing pretends to work before it does.
    |  - "surepass": the live Surepass CIBIL APIs (billable). Requests still
    |    only go out once the Admin switches them on in CIBIL Settings.
    |  - "fake": a deterministic stand-in for local development. Ignored in
    |    production. The /demo environment always uses it.
    */
    'driver' => env('CIBIL_DRIVER', 'unconfigured'),

    /*
    |--------------------------------------------------------------------------
    | Surepass
    |--------------------------------------------------------------------------
    |
    | The token lives only in the server's environment. It is never shown in
    | the app, stored on a report or written to a log, and no other bureau's
    | token is ever used in its place.
    */
    'surepass' => [
        'base_url' => env('SUREPASS_CIBIL_BASE_URL', 'https://kyc-api.surepass.app'),
        'token' => env('SUREPASS_CIBIL_TOKEN'),

        'endpoints' => [
            'score' => '/api/v1/credit-report-cibil/score',
            'full_report' => '/api/v1/credit-report-cibil/fetch-report',
            'pdf' => '/api/v1/credit-report-cibil/fetch-report-pdf',
        ],

        // Seconds. A request that outlives the timeout is kept as "outcome
        // uncertain" — the provider may still have processed (and billed) it.
        'connect_timeout' => (int) env('SUREPASS_CIBIL_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('SUREPASS_CIBIL_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Surepass — Experian
    |--------------------------------------------------------------------------
    |
    | The Experian bureau, used when the user picks Experian (both bureaus
    | are always offered in the LMS). Its own token: the CIBIL token is never
    | used in its place. Until the token and an endpoint are set, an Experian
    | request is recorded as failed ("not connected") and nothing is sent.
    | Confirm the paths below against the Surepass account's API docs.
    */
    'experian' => [
        'base_url' => env('SUREPASS_EXPERIAN_BASE_URL', env('SUREPASS_CIBIL_BASE_URL', 'https://kyc-api.surepass.app')),
        'token' => env('SUREPASS_EXPERIAN_TOKEN'),

        'endpoints' => [
            'score' => env('SUREPASS_EXPERIAN_SCORE_ENDPOINT'),
            'full_report' => env('SUREPASS_EXPERIAN_REPORT_ENDPOINT', '/api/v1/credit-report-experian/fetch-report'),
            'pdf' => env('SUREPASS_EXPERIAN_PDF_ENDPOINT', '/api/v1/credit-report-experian/fetch-report-pdf'),
        ],

        'connect_timeout' => (int) env('SUREPASS_EXPERIAN_CONNECT_TIMEOUT', env('SUREPASS_CIBIL_CONNECT_TIMEOUT', 10)),
        'timeout' => (int) env('SUREPASS_EXPERIAN_TIMEOUT', env('SUREPASS_CIBIL_TIMEOUT', 60)),
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF reports
    |--------------------------------------------------------------------------
    |
    | Saved to a private disk (never "public") and served only through the
    | app's authorised route.
    */
    'pdf' => [
        'disk' => env('CIBIL_PDF_DISK', 'local'),
        'directory' => 'cibil-reports',
        'max_bytes' => (int) env('CIBIL_PDF_MAX_BYTES', 15 * 1024 * 1024),
        'download_timeout' => (int) env('CIBIL_PDF_DOWNLOAD_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale requests
    |--------------------------------------------------------------------------
    |
    | Minutes after which a request still "pending" (the app stopped waiting —
    | e.g. the PHP process was killed mid-call) is treated as outcome
    | uncertain. Keep it above the Surepass timeout.
    */
    'pending_stale_after_minutes' => (int) env('CIBIL_PENDING_STALE_MINUTES', 5),

];
