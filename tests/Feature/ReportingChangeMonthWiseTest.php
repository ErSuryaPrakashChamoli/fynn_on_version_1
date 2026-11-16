<?php

namespace Tests\Feature;

use App\Filament\Resources\Teams\Pages\ViewTeam;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\ReportingLineService;
use App\Support\HierarchyHelper;
use App\Support\ReportingTimeline;
use App\Support\ReportingTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A reporting change only moves somebody from its effective date.
 *
 * Caller A reports to Team Leader B (Manager M1) until 25 Oct 2026 and to
 * Team Leader C (Manager M2) from 26 Oct. The move is keyed in on 14 Nov.
 * Months before the change must stay with B and October is split by date.
 * The target follows the joiner/leaver rule: a team the caller was in for
 * their whole month counts their own target; a team they moved into or out
 * of counts the ₹15L part-month target for 10+ days in it, otherwise 0.
 */
class ReportingChangeMonthWiseTest extends TestCase
{
    use RefreshDatabase;

    private const CALLER_TARGET = 2500000.0;

    private const TOP_UP = 3000000.0;

    private const PART_MONTH_TARGET = 1500000.0;

    private Employee $managerOne;

    private Employee $managerTwo;

    private Employee $teamLeaderB;

    private Employee $teamLeaderC;

    private Employee $callerA;

    private AchievementCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 11, 14, 11));

        $this->calculator = new AchievementCalculatorService;

        $cluster = $this->employee(Employee::DESIGNATION_CLUSTER);
        $this->managerOne = $this->employee(Employee::DESIGNATION_MANAGER, ['cluster_id' => $cluster->id]);
        $this->managerTwo = $this->employee(Employee::DESIGNATION_MANAGER, ['cluster_id' => $cluster->id]);
        $this->teamLeaderB = $this->employee(Employee::DESIGNATION_TEAM_LEADER, [
            'manager_id' => $this->managerOne->id,
            'cluster_id' => $cluster->id,
        ]);
        $this->teamLeaderC = $this->employee(Employee::DESIGNATION_TEAM_LEADER, [
            'manager_id' => $this->managerTwo->id,
            'cluster_id' => $cluster->id,
        ]);
        $this->callerA = $this->employee(Employee::DESIGNATION_CALLER, [
            'superviser_id' => $this->teamLeaderB->id,
            'manager_id' => $this->managerOne->id,
            'cluster_id' => $cluster->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(int $designation, array $attributes = []): Employee
    {
        return Employee::factory()->create([
            'designation' => $designation,
            'exit_status' => 'no',
            'exit_date' => null,
            'doj' => '2026-01-01',
            'reporting_date' => '2026-01-01',
            'category' => (string) self::CALLER_TARGET,
            ...$attributes,
        ]);
    }

    private function moveCallerAToTeamLeaderC(string $effectiveDate = '2026-10-26'): void
    {
        app(ReportingLineService::class)->reportTo(
            $this->callerA,
            $this->teamLeaderC->id,
            $effectiveDate,
            ReportingLineService::CHANGE_TRANSFER,
        );
    }

    private function disburse(Employee $owner, string $date, float $amount): void
    {
        Customer::factory()->create([
            'employee_id' => $owner->id,
            'sanctioned_loan_amount' => $amount,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'disbursal_date' => $date,
        ]);
    }

    private function month(int $month): Carbon
    {
        return Carbon::create(2026, $month, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Achievement follows the team on the disbursal date
    |--------------------------------------------------------------------------
    */

    public function test_months_before_the_change_stay_with_the_old_team(): void
    {
        $this->disburse($this->callerA, '2026-09-15', 800000);

        $this->moveCallerAToTeamLeaderC();

        $this->assertSame(800000.0, $this->calculator->getCountAchievement($this->teamLeaderB, $this->month(9)));
        $this->assertSame(800000.0, $this->calculator->getCountAchievement($this->managerOne, $this->month(9)));
        $this->assertSame(0.0, $this->calculator->getCountAchievement($this->teamLeaderC, $this->month(9)));
        $this->assertSame(0.0, $this->calculator->getCountAchievement($this->managerTwo, $this->month(9)));
    }

    public function test_the_month_of_the_change_is_split_by_disbursal_date(): void
    {
        $this->disburse($this->callerA, '2026-10-10', 1000000);
        $this->disburse($this->callerA, '2026-10-25', 300000);
        $this->disburse($this->callerA, '2026-10-26', 200000);
        $this->disburse($this->callerA, '2026-10-28', 500000);

        $this->moveCallerAToTeamLeaderC();

        $this->assertSame(1300000.0, $this->calculator->getCountAchievement($this->teamLeaderB, $this->month(10)));
        $this->assertSame(1300000.0, $this->calculator->getCountAchievement($this->managerOne, $this->month(10)));
        $this->assertSame(700000.0, $this->calculator->getCountAchievement($this->teamLeaderC, $this->month(10)));
        $this->assertSame(700000.0, $this->calculator->getCountAchievement($this->managerTwo, $this->month(10)));

        // The caller's own figure never splits.
        $this->assertSame(2000000.0, $this->calculator->getCountAchievement($this->callerA, $this->month(10)));
    }

    public function test_months_after_the_change_belong_to_the_new_team(): void
    {
        $this->disburse($this->callerA, '2026-11-05', 400000);

        $this->moveCallerAToTeamLeaderC();

        $this->assertSame(0.0, $this->calculator->getCountAchievement($this->teamLeaderB, $this->month(11)));
        $this->assertSame(400000.0, $this->calculator->getCountAchievement($this->teamLeaderC, $this->month(11)));
    }

    public function test_performance_rows_add_up_to_the_team_total(): void
    {
        $this->disburse($this->callerA, '2026-10-10', 1000000);
        $this->disburse($this->callerA, '2026-10-28', 500000);

        $this->moveCallerAToTeamLeaderC();

        $withinB = $this->calculator->getPerformance($this->callerA, $this->month(10), $this->teamLeaderB);
        $withinC = $this->calculator->getPerformance($this->callerA, $this->month(10), $this->teamLeaderC);

        $this->assertSame(1000000.0, $withinB['count_achievement']);
        $this->assertSame(500000.0, $withinC['count_achievement']);
        $this->assertSame(self::PART_MONTH_TARGET, $withinB['target']);
        $this->assertSame(0.0, $withinC['target']);

        // Seen on their own, the caller still has their whole month.
        $own = $this->calculator->getPerformance($this->callerA, $this->month(10));
        $this->assertSame(1500000.0, $own['count_achievement']);
        $this->assertSame(self::CALLER_TARGET, $own['target']);
    }

    /*
    |--------------------------------------------------------------------------
    | Target: the 10-day joiner/leaver rule for a move, full month otherwise
    |--------------------------------------------------------------------------
    */

    public function test_a_late_month_move_counts_the_part_month_target_only_in_the_old_team(): void
    {
        $this->moveCallerAToTeamLeaderC();

        $timeline = ReportingTimeline::forMonth($this->month(10));

        // 25 days with B (10+), 6 days with C (under 10).
        $this->assertSame(self::PART_MONTH_TARGET, $this->calculator->callerTargetShares($this->teamLeaderB, $timeline)[$this->callerA->id]);
        $this->assertSame(self::PART_MONTH_TARGET, $this->calculator->callerTargetShares($this->managerOne, $timeline)[$this->callerA->id]);
        $this->assertSame(0.0, $this->calculator->callerTargetShares($this->teamLeaderC, $timeline)[$this->callerA->id]);
        $this->assertSame(0.0, $this->calculator->callerTargetShares($this->managerTwo, $timeline)[$this->callerA->id]);
    }

    public function test_a_mid_month_move_counts_the_part_month_target_in_both_teams(): void
    {
        $this->moveCallerAToTeamLeaderC('2026-10-16');

        $timeline = ReportingTimeline::forMonth($this->month(10));

        // 15 days with B and 16 with C: both are 10+.
        $this->assertSame(self::PART_MONTH_TARGET, $this->calculator->callerTargetShares($this->teamLeaderB, $timeline)[$this->callerA->id]);
        $this->assertSame(self::PART_MONTH_TARGET, $this->calculator->callerTargetShares($this->teamLeaderC, $timeline)[$this->callerA->id]);
    }

    public function test_a_boss_the_caller_never_left_counts_their_full_month_target(): void
    {
        $this->moveCallerAToTeamLeaderC();

        // Both teams sit under the same Cluster Manager, so for them it is
        // no move at all: 1st to 31st, the full category target.
        $this->assertSame(
            self::CALLER_TARGET,
            $this->calculator->callerTargetShares($this->managerOne->cluster, ReportingTimeline::forMonth($this->month(10)))[$this->callerA->id],
        );
    }

    public function test_whole_months_on_either_side_carry_the_full_target(): void
    {
        $this->moveCallerAToTeamLeaderC();

        $septemberB = ReportingTimeline::forMonth($this->month(9));
        $novemberC = ReportingTimeline::forMonth($this->month(11));

        $this->assertSame(self::CALLER_TARGET, $this->calculator->callerTargetShares($this->teamLeaderB, $septemberB)[$this->callerA->id]);
        $this->assertArrayNotHasKey($this->callerA->id, $this->calculator->callerTargetShares($this->teamLeaderC, $septemberB));
        $this->assertSame(self::CALLER_TARGET, $this->calculator->callerTargetShares($this->teamLeaderC, $novemberC)[$this->callerA->id]);
        $this->assertArrayNotHasKey($this->callerA->id, $this->calculator->callerTargetShares($this->teamLeaderB, $novemberC));
    }

    /*
    |--------------------------------------------------------------------------
    | Headcount: every team the caller spent 10+ days in
    |--------------------------------------------------------------------------
    */

    public function test_a_late_month_move_counts_the_caller_only_in_the_old_teams_headcount(): void
    {
        $this->moveCallerAToTeamLeaderC();

        $october = ReportingTimeline::forMonth($this->month(10));

        $this->assertSame([$this->callerA->id], $october->headcountCallerIds($this->teamLeaderB->id));
        $this->assertSame([], $october->headcountCallerIds($this->teamLeaderC->id));
        $this->assertSame(1, $this->calculator->getEligibleCallerCount($this->managerOne, $this->month(10)));
        $this->assertSame(0, $this->calculator->getEligibleCallerCount($this->managerTwo, $this->month(10)));
    }

    public function test_a_mid_month_move_counts_the_caller_in_both_teams_headcount(): void
    {
        $this->moveCallerAToTeamLeaderC('2026-10-16');

        $october = ReportingTimeline::forMonth($this->month(10));

        $this->assertSame([$this->callerA->id], $october->headcountCallerIds($this->teamLeaderB->id));
        $this->assertSame([$this->callerA->id], $october->headcountCallerIds($this->teamLeaderC->id));

        $this->assertSame(1, $this->calculator->getTeamLeaderIncentiveBreakdown($this->teamLeaderB, $this->month(10))['caller_count']);
        $this->assertSame(1, $this->calculator->getTeamLeaderIncentiveBreakdown($this->teamLeaderC, $this->month(10))['caller_count']);
    }

    public function test_the_headcount_counts_only_days_on_the_rolls(): void
    {
        $underB = ['superviser_id' => $this->teamLeaderB->id, 'manager_id' => $this->managerOne->id, 'cluster_id' => $this->managerOne->cluster_id];

        $joinedOnThe20th = $this->employee(Employee::DESIGNATION_CALLER, ['doj' => '2026-10-20', 'reporting_date' => '2026-10-20', ...$underB]);
        $joinedOnThe25th = $this->employee(Employee::DESIGNATION_CALLER, ['doj' => '2026-10-25', 'reporting_date' => '2026-10-25', ...$underB]);
        $leftOnThe3rd = $this->employee(Employee::DESIGNATION_CALLER, ['exit_status' => 'yes', 'exit_date' => '2026-10-03', ...$underB]);
        $leftOnThe20th = $this->employee(Employee::DESIGNATION_CALLER, ['exit_status' => 'yes', 'exit_date' => '2026-10-20', ...$underB]);

        $october = ReportingTimeline::forMonth($this->month(10));

        // 12 and 20 days on the rolls count; 7 and 3 do not.
        $this->assertEqualsCanonicalizing(
            [$this->callerA->id, $joinedOnThe20th->id, $leftOnThe20th->id],
            $october->headcountCallerIds($this->teamLeaderB->id, directOnly: true),
        );
        $this->assertEqualsCanonicalizing(
            [$this->callerA->id, $joinedOnThe20th->id, $leftOnThe20th->id],
            $october->headcountCallerIds($this->managerOne->id),
        );
        $this->assertNotContains($joinedOnThe25th->id, $october->headcountCallerIds($this->teamLeaderB->id, directOnly: true));
        $this->assertNotContains($leftOnThe3rd->id, $october->headcountCallerIds($this->teamLeaderB->id, directOnly: true));
    }

    public function test_a_team_leader_moved_in_late_adds_no_top_up_to_the_new_team(): void
    {
        app(ReportingLineService::class)->reportTo($this->teamLeaderC, $this->managerOne->id, '2026-10-25', ReportingLineService::CHANGE_TRANSFER);

        $october = ReportingTimeline::forMonth($this->month(10));

        // C: 24 days with M2 (10+), 7 with M1 (under 10). The top-up is whole, never split.
        $this->assertSame([$this->teamLeaderB->id], $this->calculator->understaffedTeamLeaderIds($this->managerOne, $october));
        $this->assertSame([$this->teamLeaderC->id], $this->calculator->understaffedTeamLeaderIds($this->managerTwo, $october));
        $this->assertSame(self::CALLER_TARGET + self::TOP_UP, $this->calculator->getTarget($this->managerOne, $this->month(10)));
        $this->assertSame(self::TOP_UP, $this->calculator->getTarget($this->managerTwo, $this->month(10)));
    }

    public function test_a_team_leader_moved_mid_month_adds_the_whole_top_up_to_both_teams(): void
    {
        app(ReportingLineService::class)->reportTo($this->teamLeaderC, $this->managerOne->id, '2026-10-16', ReportingLineService::CHANGE_TRANSFER);

        $october = ReportingTimeline::forMonth($this->month(10));

        $this->assertEqualsCanonicalizing([$this->teamLeaderB->id, $this->teamLeaderC->id], $this->calculator->understaffedTeamLeaderIds($this->managerOne, $october));
        $this->assertSame([$this->teamLeaderC->id], $this->calculator->understaffedTeamLeaderIds($this->managerTwo, $october));
    }

    public function test_a_team_leader_who_joined_late_in_the_month_adds_no_top_up(): void
    {
        $this->employee(Employee::DESIGNATION_TEAM_LEADER, [
            'doj' => '2026-10-25',
            'reporting_date' => '2026-10-25',
            'manager_id' => $this->managerOne->id,
            'cluster_id' => $this->managerOne->cluster_id,
        ]);

        $this->assertSame(
            [$this->teamLeaderB->id],
            $this->calculator->understaffedTeamLeaderIds($this->managerOne, ReportingTimeline::forMonth($this->month(10))),
        );
    }

    public function test_the_understaffed_top_up_follows_the_headcount_of_the_month(): void
    {
        // B's headcount for September: A only (< 3 callers) — so the top-up
        // applies there; C has no callers before October either.
        $this->moveCallerAToTeamLeaderC();

        $this->assertSame(
            self::CALLER_TARGET + self::TOP_UP,
            $this->calculator->getTarget($this->teamLeaderB, $this->month(9)),
        );

        // October: B keeps the part-month target for A's 25 days, C gets
        // nothing for 6, and both Team Leaders are understaffed all month.
        $this->assertSame(
            self::PART_MONTH_TARGET + self::TOP_UP,
            $this->calculator->getTarget($this->teamLeaderB, $this->month(10)),
        );
        $this->assertSame(
            self::TOP_UP,
            $this->calculator->getTarget($this->teamLeaderC, $this->month(10)),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Team listings and the tree for a past day
    |--------------------------------------------------------------------------
    */

    public function test_team_listings_for_a_month_show_who_reported_then(): void
    {
        $this->moveCallerAToTeamLeaderC();

        $september = [$this->month(9), $this->month(9)->endOfMonth()];
        $october = [$this->month(10), $this->month(10)->endOfMonth()];

        $this->assertTrue(HierarchyHelper::childrenDuring($this->teamLeaderB, ...$september)->whereKey($this->callerA->id)->exists());
        $this->assertFalse(HierarchyHelper::childrenDuring($this->teamLeaderC, ...$september)->whereKey($this->callerA->id)->exists());

        $this->assertTrue(HierarchyHelper::childrenDuring($this->teamLeaderB, ...$october)->whereKey($this->callerA->id)->exists());
        $this->assertTrue(HierarchyHelper::childrenDuring($this->teamLeaderC, ...$october)->whereKey($this->callerA->id)->exists());

        // Today's tree — used for record visibility — is the new team.
        $this->assertTrue(HierarchyHelper::subordinateIds($this->teamLeaderC)->contains($this->callerA->id));
        $this->assertFalse(HierarchyHelper::subordinateIds($this->teamLeaderB)->contains($this->callerA->id));
    }

    public function test_the_tree_for_a_past_day_is_rebuilt_from_history(): void
    {
        $this->moveCallerAToTeamLeaderC();

        $this->assertSame($this->teamLeaderB->id, ReportingTree::load(Carbon::create(2026, 10, 25))->bossId($this->callerA->id));
        $this->assertSame($this->teamLeaderC->id, ReportingTree::load(Carbon::create(2026, 10, 26))->bossId($this->callerA->id));
        $this->assertSame($this->teamLeaderC->id, ReportingTree::load()->bossId($this->callerA->id));
    }

    public function test_a_moved_team_leader_takes_their_team_only_from_the_effective_date(): void
    {
        $this->disburse($this->callerA, '2026-10-05', 600000);
        $this->disburse($this->callerA, '2026-10-25', 400000);

        app(ReportingLineService::class)->reportTo(
            $this->teamLeaderB,
            $this->managerTwo->id,
            '2026-10-20',
            ReportingLineService::CHANGE_TRANSFER,
        );

        $this->assertSame(600000.0, $this->calculator->getCountAchievement($this->managerOne, $this->month(10)));
        $this->assertSame(400000.0, $this->calculator->getCountAchievement($this->managerTwo, $this->month(10)));
    }

    public function test_the_team_view_for_a_past_month_lists_who_was_in_the_team_then(): void
    {
        $this->disburse($this->callerA, '2026-10-10', 1000000);
        $this->disburse($this->callerA, '2026-10-28', 500000);

        $this->moveCallerAToTeamLeaderC();

        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => 'Admin'])));

        Livewire::withCookie('selected_month', '2026-09')
            ->test(ViewTeam::class, ['record' => $this->teamLeaderB])
            ->assertCanSeeTableRecords([$this->callerA]);

        Livewire::withCookie('selected_month', '2026-09')
            ->test(ViewTeam::class, ['record' => $this->teamLeaderC])
            ->assertCanNotSeeTableRecords([$this->callerA]);

        Livewire::withCookie('selected_month', '2026-10')
            ->test(ViewTeam::class, ['record' => $this->teamLeaderB])
            ->assertCanSeeTableRecords([$this->callerA])
            ->assertTableColumnStateSet('count_achievement', 1000000.0, $this->callerA)
            ->assertTableColumnStateSet('target', self::PART_MONTH_TARGET, $this->callerA);

        Livewire::withCookie('selected_month', '2026-10')
            ->test(ViewTeam::class, ['record' => $this->teamLeaderC])
            ->assertCanSeeTableRecords([$this->callerA])
            ->assertTableColumnStateSet('count_achievement', 500000.0, $this->callerA);

        $this->assertSame(
            'In this team 01 Oct – 25 Oct (25 of 31 days).',
            $this->calculator->reportingSpanNote($this->callerA, $this->teamLeaderB, $this->month(10)),
        );
        $this->assertSame(
            'In this team 01 Oct – 25 Oct (25 of 31 days). Only cases disbursed on those days count here.'
                .' With 10 or more days here, the part-month target of ₹15,00,000 counts.',
            $this->calculator->reportingSpanNote($this->callerA, $this->teamLeaderB, $this->month(10), withRule: true),
        );
        $this->assertSame(
            'In this team 26 Oct – 31 Oct (6 of 31 days). Only cases disbursed on those days count here.'
                .' Under 10 days here, so no target counts.',
            $this->calculator->reportingSpanNote($this->callerA, $this->teamLeaderC, $this->month(10), withRule: true),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | History is what makes this possible
    |--------------------------------------------------------------------------
    */

    public function test_moving_an_exited_employee_is_recorded_in_their_history(): void
    {
        $this->callerA->forceFill(['exit_status' => 'yes', 'exit_date' => '2026-10-31'])->save();
        $this->disburse($this->callerA, '2026-10-10', 700000);

        $this->moveCallerAToTeamLeaderC('2026-11-10');

        $this->assertTrue(EmployeeReportingHistory::query()
            ->where('employee_id', $this->callerA->id)
            ->where('new_superviser_id', $this->teamLeaderC->id)
            ->whereDate('effective_date', '2026-11-10')
            ->exists());

        // Their October case stays with the team they worked in.
        $this->assertSame(700000.0, $this->calculator->getCountAchievement($this->teamLeaderB, $this->month(10)));
        $this->assertSame(0.0, $this->calculator->getCountAchievement($this->teamLeaderC, $this->month(10)));
    }

    public function test_without_a_reporting_change_every_figure_is_the_whole_month(): void
    {
        $this->disburse($this->callerA, '2026-10-10', 1000000);

        $this->assertSame(1000000.0, $this->calculator->getCountAchievement($this->teamLeaderB, $this->month(10)));
        $this->assertSame(self::CALLER_TARGET + self::TOP_UP, $this->calculator->getTarget($this->teamLeaderB, $this->month(10)));
        $this->assertSame(1, $this->calculator->getEligibleCallerCount($this->teamLeaderB, $this->month(10)));
    }
}
