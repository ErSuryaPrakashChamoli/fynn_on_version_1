<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the period-aware target engine (getHierarchyCallerTargetForPeriod /
 * getTargetForPeriod) applies the exact same joining/exit business rules to
 * a historical (fully elapsed) month as getHierarchyCallerTarget()/getTarget()
 * apply to the current month — so a given month never reports a different
 * target depending on when it's evaluated.
 *
 * "Today" is frozen to 15 Sep 2026, comfortably after the August 2026 month
 * used throughout as the historical period under test, so every assertion
 * is deterministic regardless of when the suite actually runs.
 */
class AchievementCalculatorTargetForPeriodTest extends TestCase
{
    use RefreshDatabase;

    private AchievementCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 9, 15));

        $this->calculator = new AchievementCalculatorService;
    }

    private function augustStart(): Carbon
    {
        return Carbon::create(2026, 8, 1)->startOfDay();
    }

    private function augustEnd(): Carbon
    {
        return Carbon::create(2026, 8, 31)->endOfDay();
    }

    private function caller(array $attributes = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'designation' => Employee::DESIGNATION_CALLER,
        ], $attributes));
    }

    // 1. Full-month active caller
    public function test_full_month_active_caller_gets_category_target(): void
    {
        $employee = $this->caller([
            'category' => '3000000',
            'reporting_date' => '2025-01-01',
            'exit_status' => 'no',
        ]);

        $target = $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd());

        $this->assertSame(3000000.0, $target);
    }

    // 2-5. New joiner on various days of the historical month
    public function test_new_joiner_on_day_one_of_historical_month(): void
    {
        $employee = $this->caller(['reporting_date' => '2026-08-01', 'exit_status' => 'no']);

        // (31 - 1) + 1 = 31 worked days
        $this->assertSame(1500000.0, $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd()));
    }

    public function test_new_joiner_on_day_nine_of_historical_month(): void
    {
        $employee = $this->caller(['reporting_date' => '2026-08-09', 'exit_status' => 'no']);

        // (31 - 9) + 1 = 23 worked days
        $this->assertSame(1500000.0, $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd()));
    }

    public function test_new_joiner_on_day_ten_of_historical_month(): void
    {
        $employee = $this->caller(['reporting_date' => '2026-08-10', 'exit_status' => 'no']);

        // (31 - 10) + 1 = 22 worked days
        $this->assertSame(1500000.0, $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd()));
    }

    public function test_new_joiner_on_day_twenty_of_historical_month(): void
    {
        $employee = $this->caller(['reporting_date' => '2026-08-20', 'exit_status' => 'no']);

        // (31 - 20) + 1 = 12 worked days
        $this->assertSame(1500000.0, $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd()));
    }

    // 6-9. Exit on various days of the historical month
    public function test_exit_on_day_five_of_historical_month(): void
    {
        $employee = $this->caller([
            'reporting_date' => '2025-01-01',
            'exit_status' => 'yes',
            'exit_date' => '2026-08-05',
        ]);

        $this->assertSame(0.0, $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd()));
    }

    public function test_exit_on_day_nine_of_historical_month(): void
    {
        $employee = $this->caller([
            'reporting_date' => '2025-01-01',
            'exit_status' => 'yes',
            'exit_date' => '2026-08-09',
        ]);

        $this->assertSame(0.0, $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd()));
    }

    public function test_exit_on_day_ten_of_historical_month(): void
    {
        $employee = $this->caller([
            'reporting_date' => '2025-01-01',
            'exit_status' => 'yes',
            'exit_date' => '2026-08-10',
        ]);

        $this->assertSame(1500000.0, $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd()));
    }

    public function test_exit_on_day_twenty_of_historical_month(): void
    {
        $employee = $this->caller([
            'reporting_date' => '2025-01-01',
            'exit_status' => 'yes',
            'exit_date' => '2026-08-20',
        ]);

        $this->assertSame(1500000.0, $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd()));
    }

    // 10. Exited before the historical period
    public function test_exit_before_the_historical_month_gets_zero(): void
    {
        $employee = $this->caller([
            'reporting_date' => '2025-01-01',
            'exit_status' => 'yes',
            'exit_date' => '2026-07-15',
        ]);

        $this->assertSame(0.0, $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd()));
    }

    // 11. Category target is respected (distinct value, to prove it's not a coincidental default)
    public function test_category_target_is_respected_for_a_historical_month(): void
    {
        $employee = $this->caller([
            'category' => '4750000',
            'reporting_date' => '2025-01-01',
            'exit_status' => 'no',
        ]);

        $this->assertSame(4750000.0, $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd()));
    }

    // Caller not yet joined as of the historical month (new correctness
    // requirement introduced by generalizing to periods before "today")
    public function test_caller_not_yet_joined_as_of_the_historical_month_gets_zero(): void
    {
        $employee = $this->caller(['reporting_date' => '2026-09-01', 'exit_status' => 'no']);

        $this->assertSame(0.0, $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd()));
    }

    /**
     * CRITICAL TEST: boundary of the <10-day historical rule, and proof
     * that the same employee evaluated under the equivalent current-period
     * rules produces the identical result.
     */
    public function test_boundary_and_current_period_equivalence_for_a_less_than_ten_day_historical_target(): void
    {
        $employee = $this->caller(['reporting_date' => '2026-08-23', 'exit_status' => 'no']);

        // (31 - 23) + 1 = 9 worked days -> below the 10-day threshold.
        $this->assertSame(
            0.0,
            $this->calculator->getHierarchyCallerTargetForPeriod($employee, $this->augustStart(), $this->augustEnd())
        );

        // One day earlier joining crosses the boundary the other way.
        $employeeAtBoundary = $this->caller(['reporting_date' => '2026-08-22', 'exit_status' => 'no']);

        // (31 - 22) + 1 = 10 worked days -> meets the threshold.
        $this->assertSame(
            1500000.0,
            $this->calculator->getHierarchyCallerTargetForPeriod($employeeAtBoundary, $this->augustStart(), $this->augustEnd())
        );

        // Now evaluate the SAME 9-worked-day employee under the
        // current-period (today-based) rules, with "today" frozen to the
        // last day of that same month, so the current-month method's own
        // "today" cutoff coincides with the historical month's end — the
        // two engines must agree.
        $this->travelTo(Carbon::create(2026, 8, 31));

        $this->assertSame(
            0.0,
            $this->calculator->getHierarchyCallerTarget($employee)
        );
    }

    // 16. Multi-month / custom date range: decomposes into full calendar
    // months and evaluates each independently rather than prorating a flat
    // total, so a mid-range joining date is handled correctly.
    public function test_multi_month_range_evaluates_each_calendar_month_independently(): void
    {
        // Joins on 20 August: not yet employed at all during July, then a
        // new joiner within August itself.
        $employee = $this->caller(['reporting_date' => '2026-08-20', 'exit_status' => 'no']);

        $julyStart = Carbon::create(2026, 7, 1)->startOfDay();

        $target = $this->calculator->getHierarchyCallerTargetForPeriod($employee, $julyStart, $this->augustEnd());

        // July: not yet joined -> 0. August: (31-20)+1=12 worked days -> 1,500,000.
        $this->assertSame(1500000.0, $target);
    }

    // 12. Historical Team Leader target — including the ₹30L top-up, which
    // the pre-fix implementation never applied historically at all.
    public function test_historical_team_leader_target_includes_the_topup(): void
    {
        $teamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER]);

        Employee::factory()->count(2)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'category' => '2500000',
            'reporting_date' => '2025-01-01',
            'exit_status' => 'no',
        ]);

        $target = $this->calculator->getTargetForPeriod($teamLeader, $this->augustStart(), $this->augustEnd());

        // 2 callers x 2,500,000 = 5,000,000, understaffed (< 3 callers) -> +3,000,000
        $this->assertSame(8000000.0, $target);
    }

    // 13. Historical Manager target
    public function test_historical_manager_target_includes_the_topup(): void
    {
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'exit_status' => 'no',
        ]);

        Employee::factory()->count(2)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => '2025-01-01',
            'exit_status' => 'no',
        ]);

        $target = $this->calculator->getTargetForPeriod($manager, $this->augustStart(), $this->augustEnd());

        $this->assertSame(8000000.0, $target);
    }

    // 14. Historical Cluster target
    public function test_historical_cluster_target_includes_the_topup(): void
    {
        $cluster = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);

        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $cluster->id,
            'exit_status' => 'no',
        ]);

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'cluster_id' => $cluster->id,
            'exit_status' => 'no',
        ]);

        Employee::factory()->count(2)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'cluster_id' => $cluster->id,
            'category' => '2500000',
            'reporting_date' => '2025-01-01',
            'exit_status' => 'no',
        ]);

        $target = $this->calculator->getTargetForPeriod($cluster, $this->augustStart(), $this->augustEnd());

        $this->assertSame(8000000.0, $target);
    }

    // 15. Historical Admin/company target — flat sum of every caller's
    // period-aware target, no top-up (matches current-month Admin behavior).
    public function test_historical_admin_target_sums_every_caller_with_no_topup(): void
    {
        $admin = Employee::factory()->create(['designation' => Employee::DESIGNATION_ADMIN]);

        Employee::factory()->count(2)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '2500000',
            'reporting_date' => '2025-01-01',
            'exit_status' => 'no',
        ]);

        $target = $this->calculator->getTargetForPeriod($admin, $this->augustStart(), $this->augustEnd());

        $this->assertSame(5000000.0, $target);
    }
}
