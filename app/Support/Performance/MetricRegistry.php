<?php

namespace App\Support\Performance;

/**
 * Every raw metric key an admin can pick from when building a custom
 * ratio, and the human label shown for it throughout the Performance
 * module. Add a key here (and populate it in
 * EmployeePerformanceMetricsService::rawMetrics()) to make a new metric
 * available everywhere — stat cards, ratio builder, exports — at once.
 */
class MetricRegistry
{
    public static function keys(): array
    {
        return [
            'otp_count' => 'Total OTPs',
            'eligible_otp_count' => 'Eligible OTPs',
            'login_count' => 'Logins',
            'approval_count' => 'Approvals',
            'disbursal_count' => 'Disbursals',
            'disbursal_amount' => 'Disbursal Amount (₹)',
            'dropped_count' => 'Dropped Cases',
            'not_approved_count' => 'Not Approved Cases',
            'target_amount' => 'Target Amount (₹)',
            'actual_achievement' => 'Actual Achievement (₹)',
            'count_achievement' => 'Count Achievement (₹)',
            'present_days' => 'Present Days',
            'working_days' => 'Working Days',
            'screen_time_hours' => 'Screen Time (Hours)',
        ];
    }

    public static function label(string $key): string
    {
        return static::keys()[$key] ?? $key;
    }

    public static function options(): array
    {
        return static::keys();
    }
}
