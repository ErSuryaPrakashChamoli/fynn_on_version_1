<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementCalculatorManagerTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_target_excludes_understaffed_topup_for_exited_team_leader(): void
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

        // Understaffed (1 caller), but the Team Leader has exited, so this
        // team must not contribute a top-up to the Manager's target.
        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $exitedTeamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        $target = (new AchievementCalculatorService)->getTarget($manager);

        // 3 active callers @ 2500000 each = 7500000, no top-up because the
        // active team leader has >= 3 callers and the exited team leader's
        // understaffed team is excluded entirely.
        $this->assertSame(7500000.0, $target);
    }
}
