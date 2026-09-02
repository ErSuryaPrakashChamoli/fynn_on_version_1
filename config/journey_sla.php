<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Manager-stage SLA thresholds (minutes)
    |--------------------------------------------------------------------------
    |
    | How long a customer may sit in a given journey module before (a) a
    | reminder is raised against the current owner and (b) the case is
    | escalated to the Cluster Manager. Keyed by App\Enums\JourneyModule value.
    */
    'reminder_minutes' => [
        'document_verification' => (int) env('SLA_REMINDER_DOCUMENT_VERIFICATION', 30),
        'approval' => (int) env('SLA_REMINDER_APPROVAL', 60),
        'bank_processing' => (int) env('SLA_REMINDER_BANK_PROCESSING', 120),
        'disbursal_processing' => (int) env('SLA_REMINDER_DISBURSAL_PROCESSING', 240),
        'customer_follow_up' => (int) env('SLA_REMINDER_CUSTOMER_FOLLOW_UP', 120),
    ],

    'escalation_minutes' => [
        'document_verification' => (int) env('SLA_ESCALATE_DOCUMENT_VERIFICATION', 60),
        'approval' => (int) env('SLA_ESCALATE_APPROVAL', 120),
        'bank_processing' => (int) env('SLA_ESCALATE_BANK_PROCESSING', 240),
        'disbursal_processing' => (int) env('SLA_ESCALATE_DISBURSAL_PROCESSING', 480),
        'customer_follow_up' => (int) env('SLA_ESCALATE_CUSTOMER_FOLLOW_UP', 240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delegation approval
    |--------------------------------------------------------------------------
    |
    | When true, a newly created Customer Journey Delegation starts as
    | "pending" and requires an Admin/Cluster Manager/Business Head to
    | approve it before it can become active. When false (default), a
    | delegation created by a Manager is auto-approved and immediately
    | scheduled (still subject to its start_at/end_at window).
    */
    'delegation_requires_approval' => (bool) env('JOURNEY_DELEGATION_REQUIRES_APPROVAL', false),

];
