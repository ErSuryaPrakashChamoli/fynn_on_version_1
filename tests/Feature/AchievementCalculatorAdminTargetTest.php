<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementCalculatorAdminTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_wide_target_applies_the_ten_day_rule_like_every_other_level(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(14));

        $admin = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_ADMIN,
        ]);

        // Active caller, joined long ago: contributes its full category target.
        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '3000000',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'no',
        ]);

        // New joiner this month, still on the rolls and projected to 10+
        // days: brings their full category target (user decision
        // 2026-11-24), exactly as they do in any Team Leader's target.
        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '9999999',
            'reporting_date' => now()->subDays(3),
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getTarget($admin);

        $this->assertSame(3000000.0 + 9999999.0, $target);
    }

    /**
     * The other half of the same guarantee: a joiner who cannot reach the
     * 10-day minimum before the month ends still contributes nothing.
     */
    public function test_company_wide_target_excludes_a_joiner_who_cannot_reach_ten_days(): void
    {
        $this->travelTo(now()->endOfMonth()->startOfDay()->subDays(8));

        $admin = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_ADMIN,
        ]);

        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '3000000',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'no',
        ]);

        // Joins with only 9 days left in the month.
        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '9999999',
            'reporting_date' => now(),
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getTarget($admin);

        $this->assertSame(3000000.0, $target);
    }
}
