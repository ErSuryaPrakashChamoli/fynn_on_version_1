<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementCalculatorAdminTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_wide_target_applies_hierarchy_proration_like_every_other_level(): void
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

        // New joiner this month, still on the rolls: contributes the flat
        // partial-month target, NOT its inflated category value — the
        // proration is what this test is guarding.
        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '9999999',
            'reporting_date' => now()->subDays(3),
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getTarget($admin);

        $this->assertSame(3000000.0 + 1500000.0, $target);
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
