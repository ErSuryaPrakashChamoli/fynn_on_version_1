<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A mid-month joiner must show a target to the people above them
 * immediately, rather than a 0 that becomes a target on day ten.
 *
 * This is the case the rule change of 2026-09-10 was made for: a caller
 * reported on the 7th, and on the 10th their Team Leader's rollup still
 * showed nothing against them — indistinguishable from a target nobody
 * had set. See AchievementCalculatorService::activeWindowForMonth().
 */
class NewJoinerTargetProjectionTest extends TestCase
{
    use RefreshDatabase;

    private AchievementCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        // The reported scenario, made deterministic: joined on the 7th,
        // evaluated on the 10th.
        $this->travelTo(now()->startOfMonth()->addDays(9));

        $this->calculator = new AchievementCalculatorService;
    }

    private function teamLeaderWithCallers(int $establishedCallers): Employee
    {
        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'category' => 'team_leader',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'no',
        ]);

        Employee::factory()->count($establishedCallers)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '2500000',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'no',
            'superviser_id' => $teamLeader->id,
        ]);

        return $teamLeader;
    }

    private function joinerUnder(Employee $teamLeader): Employee
    {
        return Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '2500000',
            'reporting_date' => now()->startOfMonth()->addDays(6), // the 7th
            'exit_status' => 'no',
            'superviser_id' => $teamLeader->id,
        ]);
    }

    public function test_a_team_leaders_target_includes_a_caller_who_joined_three_days_ago(): void
    {
        // Three established callers keeps the team off the understaffed
        // top-up, so the arithmetic below is just the callers' targets.
        $teamLeader = $this->teamLeaderWithCallers(3);

        $before = $this->calculator->getTarget($teamLeader);
        $this->assertSame(7500000.0, $before, 'Three established callers at 25L each.');

        $this->joinerUnder($teamLeader);

        $after = $this->calculator->getTarget($teamLeader->refresh());

        $this->assertSame(
            9000000.0,
            $after,
            'The joiner must add the 15L partial-month target straight away, not 0.',
        );
    }

    public function test_the_joiners_own_contribution_is_visible_on_the_day_they_report(): void
    {
        $teamLeader = $this->teamLeaderWithCallers(3);
        $joiner = $this->joinerUnder($teamLeader);

        $this->assertSame(1500000.0, $this->calculator->getHierarchyCallerTarget($joiner));
    }

    /**
     * The joiner's OWN screen is unchanged — a caller has always seen the
     * flat category target regardless of when they joined.
     */
    public function test_the_joiners_own_target_screen_is_unaffected(): void
    {
        $teamLeader = $this->teamLeaderWithCallers(3);
        $joiner = $this->joinerUnder($teamLeader);

        $this->assertSame(2500000.0, $this->calculator->getTarget($joiner));
    }

    public function test_the_team_leaders_target_drops_back_when_the_joiner_leaves_early(): void
    {
        $teamLeader = $this->teamLeaderWithCallers(3);
        $joiner = $this->joinerUnder($teamLeader);

        $this->assertSame(9000000.0, $this->calculator->getTarget($teamLeader));

        // Leaves on the 12th — six days on the rolls, under the minimum.
        $joiner->update([
            'exit_status' => 'yes',
            'exit_date' => now()->startOfMonth()->addDays(11),
        ]);

        $this->assertSame(
            7500000.0,
            $this->calculator->getTarget($teamLeader->refresh()),
            'An early exit must take the projected target back out automatically.',
        );
    }

    public function test_the_team_leaders_target_stands_when_the_joiner_stays_past_ten_days(): void
    {
        $teamLeader = $this->teamLeaderWithCallers(3);
        $joiner = $this->joinerUnder($teamLeader);

        // Leaves on the 17th — eleven days on the rolls.
        $joiner->update([
            'exit_status' => 'yes',
            'exit_date' => now()->startOfMonth()->addDays(16),
        ]);

        $this->assertSame(9000000.0, $this->calculator->getTarget($teamLeader->refresh()));
    }

    /**
     * A Manager sees the same figure roll up through their Team Leaders.
     */
    public function test_a_managers_target_also_picks_the_joiner_up_immediately(): void
    {
        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'category' => 'manager',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'no',
        ]);

        $teamLeader = $this->teamLeaderWithCallers(3);
        $teamLeader->update(['manager_id' => $manager->id]);

        $before = $this->calculator->getTarget($manager);

        $this->joinerUnder($teamLeader);

        $after = $this->calculator->getTarget($manager->refresh());

        $this->assertSame(1500000.0, $after - $before);
    }

    /**
     * The Team Leader is told what the figure rests on, so a projected
     * target can never be mistaken for a settled one.
     */
    public function test_the_projected_target_carries_an_explanatory_note(): void
    {
        $teamLeader = $this->teamLeaderWithCallers(3);
        $joiner = $this->joinerUnder($teamLeader);

        $note = $this->calculator->hierarchyCallerTargetNote($joiner);

        $this->assertNotNull($note);
        $this->assertStringContainsString('stay to month-end', $note);
        $this->assertStringContainsString('drops to zero automatically', $note);
    }

    public function test_an_established_caller_needs_no_note(): void
    {
        $teamLeader = $this->teamLeaderWithCallers(1);
        $established = Employee::where('superviser_id', $teamLeader->id)->firstOrFail();

        $this->assertNull($this->calculator->hierarchyCallerTargetNote($established));
    }

    public function test_a_caller_under_the_minimum_is_told_why_they_count_for_nothing(): void
    {
        $teamLeader = $this->teamLeaderWithCallers(1);

        $joiner = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'category' => '2500000',
            'reporting_date' => now()->endOfMonth()->startOfDay()->subDays(3),
            'exit_status' => 'no',
            'superviser_id' => $teamLeader->id,
        ]);

        $note = $this->calculator->hierarchyCallerTargetNote($joiner);

        $this->assertNotNull($note);
        $this->assertStringContainsString('10-day minimum', $note);
        $this->assertSame(0.0, $this->calculator->getHierarchyCallerTarget($joiner));
    }
}
