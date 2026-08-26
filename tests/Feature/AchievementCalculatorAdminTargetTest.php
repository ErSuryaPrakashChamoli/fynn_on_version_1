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

        // New joiner this month with fewer than 10 worked days: must
        // contribute 0, not its category, to the company-wide target.
        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '9999999',
            'reporting_date' => now()->subDays(3),
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getTarget($admin);

        $this->assertSame(3000000.0, $target);
    }
}
