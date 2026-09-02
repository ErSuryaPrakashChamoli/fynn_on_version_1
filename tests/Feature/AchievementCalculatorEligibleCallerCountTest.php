<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementCalculatorEligibleCallerCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_caller_count_excludes_new_joiner_under_ten_days(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 27));

        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
        ]);

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'exit_status' => 'no',
        ]);

        // Reporting mid-month, only 3 days worked so far this month —
        // target for the month is 0, so this caller must not be eligible.
        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => Carbon::create(2026, 8, 25),
        ]);

        // Existing active caller with no reporting_date restriction.
        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        $eligibleCount = (new AchievementCalculatorService)->getEligibleCallerCount($manager);

        $this->assertSame(1, $eligibleCount);

        Carbon::setTestNow();
    }

    public function test_eligible_caller_count_excludes_caller_exited_before_this_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 27));

        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
        ]);

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'exit_status' => 'no',
        ]);

        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => null,
            'exit_status' => 'yes',
            'exit_date' => Carbon::create(2026, 7, 15),
        ]);

        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        $eligibleCount = (new AchievementCalculatorService)->getEligibleCallerCount($manager);

        $this->assertSame(1, $eligibleCount);

        Carbon::setTestNow();
    }
}
