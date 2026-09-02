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

    public function test_new_joiner_with_nine_worked_days_gets_zero_target(): void
    {
        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => now()->subDays(8), // day 7: (15-7)+1 = 9 worked days
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($employee);

        $this->assertSame(0.0, $target);
    }

    public function test_new_joiner_with_ten_worked_days_gets_the_new_joiner_target(): void
    {
        $employee = Employee::factory()->create([
            'category' => '3000000',
            'reporting_date' => now()->subDays(9), // day 6: (15-6)+1 = 10 worked days
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getHierarchyCallerTarget($employee);

        $this->assertSame(1500000.0, $target);
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
