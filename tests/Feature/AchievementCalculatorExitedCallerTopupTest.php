<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use App\Support\TargetLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Exited callers under an otherwise-active Team Leader.
 *
 * Since 2026-11-24 there is no headcount threshold in the target: every
 * Team Leader in the seat ten days carries at least ₹80L (TargetLedger), and
 * a caller who left brings their full category target only with ten or
 * more days on the rolls that month. A caller who left before the month
 * began brings nothing.
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

    public function test_a_caller_who_left_before_the_month_brings_nothing(): void
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

        // The third caller left last month and brings 0. The 2 active
        // callers bring 2,500,000 each, lifted to the 8,000,000 minimum.
        $this->assertSame(8000000.0, $target);
    }

    public function test_a_caller_who_left_after_ten_days_this_month_brings_their_full_target(): void
    {
        $this->travelTo(Carbon::create(2026, 11, 25));

        $manager = $this->manager();
        $teamLeader = $this->activeTeamLeaderUnder($manager);
        $this->callersUnder($teamLeader, $manager, 2);
        $this->callersUnder($teamLeader, $manager, 1, ['exit_status' => 'yes', 'exit_date' => '2026-11-20']);

        // 20 days on the rolls: the one who left brings their full ₹25L
        // (10+ days). ₹75L in all, lifted to the ₹80L minimum.
        $this->assertSame(8000000.0, (new AchievementCalculatorService)->getTarget($manager));
        $this->assertSame(7500000.0, TargetLedger::forMonth(now())->teamSum($teamLeader->id));
    }

    public function test_a_caller_who_left_inside_the_first_ten_days_brings_nothing(): void
    {
        $this->travelTo(Carbon::create(2026, 11, 25));

        $manager = $this->manager();
        $teamLeader = $this->activeTeamLeaderUnder($manager);
        $this->callersUnder($teamLeader, $manager, 2);
        $this->callersUnder($teamLeader, $manager, 1, ['exit_status' => 'yes', 'exit_date' => '2026-11-05']);

        // 5 days on the rolls: the one who left brings no target (under ten
        // days), so ₹50L, lifted to the ₹80L minimum.
        $this->assertSame(8000000.0, (new AchievementCalculatorService)->getTarget($manager));
        $this->assertSame(5000000.0, TargetLedger::forMonth(now())->teamSum($teamLeader->id));
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

    public function test_a_small_team_is_lifted_to_the_team_leader_minimum(): void
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

        // Only 2 callers (₹50L) -> lifted to the ₹80L minimum.
        $this->assertSame(8000000.0, $target);
    }
}
