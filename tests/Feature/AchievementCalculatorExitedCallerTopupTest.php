<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Documents the ₹30L understaffed-team top-up's caller-headcount rule,
 * confirmed earlier in this session as intentional and explicitly left
 * unchanged: getTarget()/getTargetForPeriod() count ALL callers reporting
 * to a TL (Employee::where('superviser_id', $tlId)->count(), no
 * exit_status filter) when checking the "< 3 callers" threshold — an
 * exited caller still counts toward that headcount, potentially
 * suppressing a top-up that would otherwise apply if only active callers
 * were counted. This is distinct from AchievementCalculatorManagerTargetTest,
 * which covers an EXITED TL's whole team being excluded — this covers an
 * exited CALLER under an otherwise-ACTIVE TL. Not a bug fix — a locked-in
 * regression test for a confirmed business decision.
 */
class AchievementCalculatorExitedCallerTopupTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Employee
    {
        return Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
    }

    private function activeTeamLeaderUnder(Employee $manager): Employee
    {
        return Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'exit_status' => 'no',
        ]);
    }

    public function test_an_exited_caller_still_counts_toward_the_understaffed_threshold(): void
    {
        $manager = $this->manager();
        $teamLeader = $this->activeTeamLeaderUnder($manager);

        Employee::factory()->count(2)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'no',
        ]);

        // A third caller under the same TL, but exited.
        Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'yes',
            'exit_date' => now()->subMonthNoOverflow(),
        ]);

        $target = (new AchievementCalculatorService)->getTarget($manager);

        // 3 callers counted (headcount is not filtered by exit_status), so
        // the TL is NOT considered understaffed -> no ₹30L top-up, even
        // though only 2 of the 3 are actually active. The 2 active callers
        // still contribute their full 2,500,000 category target each; the
        // exited caller (exited before this month) contributes 0.
        $this->assertSame(5000000.0, $target);
    }

    public function test_with_only_active_callers_the_same_headcount_correctly_triggers_the_topup(): void
    {
        $manager = $this->manager();
        $teamLeader = $this->activeTeamLeaderUnder($manager);

        Employee::factory()->count(2)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'no',
        ]);

        $target = (new AchievementCalculatorService)->getTarget($manager);

        // Only 2 callers total -> understaffed -> +3,000,000 top-up.
        $this->assertSame(8000000.0, $target);
    }
}
