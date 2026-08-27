<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\Performance\EmployeePerformanceMetricsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves EmployeePerformanceMetricsService::rawMetrics() always reflects
 * the period IT was asked for, never the ambient global month selector —
 * previously, its single-calendar-month fast path called
 * AchievementCalculatorService::getPerformance($employee) with no explicit
 * reference month, which silently fell back to whatever month the global
 * selector cookie happened to be parked on.
 */
class EmployeePerformanceMetricsServiceMonthScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_month_metrics_use_the_requested_period_not_the_global_month_selector_cookie(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 27));

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        // June achievement — what would leak in if the bug were still
        // present, since the global selector cookie below is parked there.
        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => 1000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'created_at' => Carbon::create(2026, 6, 15),
        ]);

        // August achievement — this call's own requested period.
        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_bank' => 'HDFC Bank',
            'sanctioned_loan_amount' => 3000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'created_at' => Carbon::create(2026, 8, 10),
        ]);

        // Global month selector parked on a different month than the
        // period this call explicitly asks for.
        request()->cookies->set('selected_month', '2026-06');

        $metrics = app(EmployeePerformanceMetricsService::class)->rawMetrics(
            $caller,
            Carbon::create(2026, 8, 1)->startOfMonth(),
            Carbon::create(2026, 8, 31)->endOfMonth()
        );

        $this->assertSame(3000000.0, $metrics['actual_achievement']);
        $this->assertSame(3000000.0, $metrics['count_achievement']);

        Carbon::setTestNow();
    }
}
