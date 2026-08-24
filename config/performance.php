<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Funnel Stage Mapping
    |--------------------------------------------------------------------------
    |
    | Maps a performance metric key to the `journey_status` value on
    | `customers` whose transition (recorded in `customer_stage_histories`)
    | counts toward that metric. Adjust here — not in code — if the
    | business definition of a stage changes.
    |
    | Note: `journey_status` defaults to 'sfl' the moment a Customer row
    | is created (see create_customers_table migration), so nothing ever
    | "transitions into" sfl — it can't be counted as a stage-history
    | event. "Login" (the case being logged in for underwriting) is
    | therefore mapped to the sfl -> underwriting transition instead,
    | which Customer::booted() reliably logs on every journey_status
    | change.
    |
    */

    'stages' => [
        'login_count' => 'underwriting',
        'approval_count' => 'approved',
        'disbursal_count' => 'sanctioned',
        'dropped_count' => 'dropped',
        'not_approved_count' => 'not_approved',
    ],

    /*
    |--------------------------------------------------------------------------
    | Working Days
    |--------------------------------------------------------------------------
    |
    | Days of the week excluded when computing "working days" for
    | attendance-rate calculations. 0 = Sunday ... 6 = Saturday.
    |
    */

    'working_days_exclude' => [0],

    /*
    |--------------------------------------------------------------------------
    | Default Period
    |--------------------------------------------------------------------------
    */

    'default_period_type' => 'monthly',
];
