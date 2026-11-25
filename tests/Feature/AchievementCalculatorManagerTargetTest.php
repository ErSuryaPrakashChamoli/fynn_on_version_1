<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementCalculatorManagerTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_exited_team_leader_carries_no_minimum_and_their_caller_counts_with_the_manager(): void
    {
        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
        ]);

        $activeTeamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'exit_status' => 'no',
        ]);

        Employee::factory()->count(3)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $activeTeamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        $exitedTeamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'exit_status' => 'yes',
        ]);

        // The Team Leader has left: their caller counts straight under the
        // Manager their own record names (user decision 2026-11-24), and
        // the exited Team Leader brings no ₹80L minimum.
        $orphan = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $exitedTeamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        $calculator = new AchievementCalculatorService;

        // The active Team Leader's 3 callers (₹75L) lifted to the ₹80L
        // minimum, plus the orphaned caller's ₹25L.
        $this->assertSame(10500000.0, $calculator->getTarget($manager));
        $this->assertSame(0.0, $calculator->getTarget($exitedTeamLeader));
        $this->assertSame(2500000.0, $calculator->getTarget($orphan, null, $manager));
    }
}
