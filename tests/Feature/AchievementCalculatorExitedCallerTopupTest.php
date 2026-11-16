<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The ₹30L understaffed-team top-up's caller headcount and exited callers.
 *
 * Decided with the user 2026-11-16, superseding the 2026-08 rule that every
 * exited caller still counted: a caller counts toward a Team Leader's
 * "< 3 callers" threshold only with ten or more days reporting to them while
 * on the rolls that month (ReportingTimeline::headcountCallerIds()). So a
 * caller who left during the month still counts if they had ten days, and
 * one who left before the month began does not. This is distinct from
 * AchievementCalculatorManagerTargetTest, which covers an EXITED TL's whole
 * team being excluded — this covers an exited CALLER under an
 * otherwise-ACTIVE TL.
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

    public function test_a_caller_who_left_before_the_month_no_longer_counts_toward_the_threshold(): void
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

        // The third caller left last month: no days on the rolls this month,
        // so only 2 count -> understaffed -> +3,000,000 top-up. The 2 active
        // callers bring 2,500,000 each; the one who left brings 0.
        $this->assertSame(8000000.0, $target);
    }

    public function test_a_caller_who_left_after_ten_days_this_month_still_counts(): void
    {
        $this->travelTo(Carbon::create(2026, 11, 25));

        $manager = $this->manager();
        $teamLeader = $this->activeTeamLeaderUnder($manager);
        $this->callersUnder($teamLeader, $manager, 2);
        $this->callersUnder($teamLeader, $manager, 1, ['exit_status' => 'yes', 'exit_date' => '2026-11-20']);

        // 20 days on the rolls: 3 callers count, so no top-up. The one who
        // left brings the part-month target (10+ days).
        $this->assertSame(5000000.0 + 1500000.0, (new AchievementCalculatorService)->getTarget($manager));
    }

    public function test_a_caller_who_left_inside_the_first_ten_days_does_not_count(): void
    {
        $this->travelTo(Carbon::create(2026, 11, 25));

        $manager = $this->manager();
        $teamLeader = $this->activeTeamLeaderUnder($manager);
        $this->callersUnder($teamLeader, $manager, 2);
        $this->callersUnder($teamLeader, $manager, 1, ['exit_status' => 'yes', 'exit_date' => '2026-11-05']);

        // 5 days on the rolls: only 2 count, so the top-up applies; the one
        // who left brings no target (under ten days).
        $this->assertSame(5000000.0 + 3000000.0, (new AchievementCalculatorService)->getTarget($manager));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function callersUnder(Employee $teamLeader, Employee $manager, int $count, array $attributes = []): void
    {
        Employee::factory()->count($count)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'category' => '2500000',
            'reporting_date' => '2025-01-01',
            'exit_status' => 'no',
            ...$attributes,
        ]);
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
