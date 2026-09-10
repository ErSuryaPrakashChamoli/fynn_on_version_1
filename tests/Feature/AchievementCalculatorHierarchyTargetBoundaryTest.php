<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementCalculatorHierarchyTargetBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze "today" to the 15th of the current month so worked-day and
        // exit-day boundaries are deterministic regardless of when the
        // suite actually runs.
        $this->travelTo(now()->startOfMonth()->addDays(14));
    }

    /**
     * The rule this file is really about (changed 2026-09-10): a caller
     * still on the rolls is counted on the basis that they stay to
     * month-end, so their Team Leader sees the target from day one rather
     * than a 0 that silently turns into a target on day ten.
     *
     * Reporting on day 7 leaves only 9 elapsed days by the 15th — the old
     * rule returned 0 here.
     */
    public function test_new_joiner_still_on_the_rolls_is_targeted_from_the_day_they_report(): void
    {
        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => now()->startOfMonth()->addDays(6), // day 7
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($employee);

        $this->assertSame(1500000.0, $target);
    }

    /**
     * The 10-day minimum still bites — it is now measured against the days
     * the caller can still work, not the days that happen to have passed.
     * Anchored to month-end so it holds in 28-, 30- and 31-day months.
     */
    public function test_new_joiner_with_fewer_than_ten_days_left_in_the_month_gets_zero_target(): void
    {
        // 9 days inclusive: reporting day .. month end.
        $reportingDate = now()->endOfMonth()->startOfDay()->subDays(8);

        $this->travelTo($reportingDate);

        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => $reportingDate,
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($employee);

        $this->assertSame(0.0, $target);
    }

    public function test_new_joiner_with_exactly_ten_days_left_in_the_month_gets_the_partial_target(): void
    {
        // 10 days inclusive: reporting day .. month end.
        $reportingDate = now()->endOfMonth()->startOfDay()->subDays(9);

        $this->travelTo($reportingDate);

        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => $reportingDate,
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($employee);

        $this->assertSame(1500000.0, $target);
    }

    /**
     * The projection is self-correcting: recording an exit that lands the
     * caller under the 10-day minimum takes the target straight back to 0,
     * with no recalculation job — the figure is derived on every read.
     */
    public function test_a_joiner_who_leaves_before_completing_ten_days_reverts_to_zero(): void
    {
        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => now()->startOfMonth()->addDays(6), // day 7
            'exit_status' => 'no',
        ]);

        $calculator = new AchievementCalculatorService;

        $this->assertSame(1500000.0, $calculator->getHierarchyCallerTarget($employee));

        // Left on day 12 — six days on the rolls (7th to 12th inclusive).
        $employee->update([
            'exit_status' => 'yes',
            'exit_date' => now()->startOfMonth()->addDays(11),
        ]);

        $this->assertSame(0.0, $calculator->getHierarchyCallerTarget($employee->refresh()));
    }

    public function test_a_joiner_who_leaves_after_completing_ten_days_keeps_the_partial_target(): void
    {
        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => now()->startOfMonth()->addDays(6), // day 7
            'exit_status' => 'yes',
            'exit_date' => now()->startOfMonth()->addDays(16), // day 17: 11 days on the rolls
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($employee);

        $this->assertSame(1500000.0, $target);
    }

    /**
     * The exit window now starts at the reporting date rather than at day
     * 1 of the month. Before this change a caller who joined on the 7th
     * and left on the 12th was credited a full partial-month target,
     * because the rule only looked at the exit date's day-of-month (12
     * >= 10) and never at when they actually started.
     */
    public function test_exit_day_of_month_alone_no_longer_grants_a_target_to_a_mid_month_joiner(): void
    {
        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => now()->startOfMonth()->addDays(6),  // day 7
            'exit_status' => 'yes',
            'exit_date' => now()->startOfMonth()->addDays(11),      // day 12
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($employee);

        $this->assertSame(0.0, $target);
    }

    public function test_an_exit_status_with_no_exit_date_leaves_the_caller_counted_as_active(): void
    {
        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'yes',
            'exit_date' => null,
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($employee);

        $this->assertSame(3000000.0, $target);
    }

    public function test_exit_on_day_nine_of_current_month_gets_zero_target(): void
    {
        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'yes',
            'exit_date' => now()->startOfMonth()->addDays(8), // day 9
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($employee);

        $this->assertSame(0.0, $target);
    }

    public function test_exit_on_day_ten_of_current_month_gets_the_new_joiner_target(): void
    {
        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'yes',
            'exit_date' => now()->startOfMonth()->addDays(9), // day 10
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($employee);

        $this->assertSame(1500000.0, $target);
    }

    public function test_exit_before_the_current_month_gets_zero_target(): void
    {
        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'yes',
            'exit_date' => now()->subMonthNoOverflow(),
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($employee);

        $this->assertSame(0.0, $target);
    }

    public function test_existing_active_employee_gets_the_category_target(): void
    {
        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($employee);

        $this->assertSame(3000000.0, $target);
    }

    public function test_caller_own_target_is_always_category_based_regardless_of_joining_or_exit(): void
    {
        $newJoiner = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '4000000',
            'reporting_date' => now(), // 1 worked day: would be 0 under getHierarchyCallerTarget
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getTarget($newJoiner);

        // Caller's OWN target ignores joining/exit date entirely.
        $this->assertSame(4000000.0, $target);
    }
}
