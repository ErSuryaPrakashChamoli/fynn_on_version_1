<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Widgets\TargetDataWarnings;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\EmployeePositionChangeService;
use App\Services\ReportingLineService;
use App\Support\TargetLedger;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The target rules consolidated with the user on 2026-11-24, one decision
 * per test, each on a worked example in November 2026 (30 days). See
 * TargetLedger for the rules themselves.
 *
 * Business Head BH ── Cluster Manager K ── Manager M
 *   M ── Team Leader A ── 3 Silver callers (₹75L)
 *   M ── Team Leader B ── 5 Silver callers (₹1.25 Cr)
 *   M ── caller D (Silver, no Team Leader)
 */
class TargetRulesConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private const SILVER = '2500000';

    private const GOLD = '3000000';

    private const LAKH = 100000.0;

    private Employee $businessHead;

    private Employee $cluster;

    private Employee $manager;

    private Employee $teamLeaderA;

    private Employee $teamLeaderB;

    private Employee $directCaller;

    /** @var list<Employee> */
    private array $callersA = [];

    /** @var list<Employee> */
    private array $callersB = [];

    private AchievementCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 12, 5, 11));

        $this->calculator = new AchievementCalculatorService;

        $this->businessHead = $this->employee(Employee::DESIGNATION_BUSINESS_HEAD);
        $this->cluster = $this->employee(Employee::DESIGNATION_CLUSTER, $this->businessHead);
        $this->manager = $this->employee(Employee::DESIGNATION_MANAGER, $this->cluster);
        $this->teamLeaderA = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager);
        $this->teamLeaderB = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager);
        $this->directCaller = $this->employee(Employee::DESIGNATION_CALLER, $this->manager);

        foreach (range(1, 3) as $i) {
            $this->callersA[] = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeaderA);
        }

        foreach (range(1, 5) as $i) {
            $this->callersB[] = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeaderB);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(int $designation, ?Employee $boss = null, array $attributes = []): Employee
    {
        return Employee::factory()->create([
            'designation' => $designation,
            'position' => Designation::query()->firstOrCreate(['name' => 'Executive'])->name,
            'category' => self::SILVER,
            'doj' => '2025-01-01',
            'reporting_date' => '2025-01-01',
            'exit_status' => 'no',
            'exit_date' => null,
            ...app(ReportingLineService::class)->columnsUnder($boss?->id),
            ...$attributes,
        ]);
    }

    private function november(): Carbon
    {
        return Carbon::create(2026, 11, 1);
    }

    private function ledger(): TargetLedger
    {
        return TargetLedger::forMonth($this->november());
    }

    private function target(Employee $employee): float
    {
        return $this->calculator->getTarget($employee, $this->november());
    }

    private function move(Employee $employee, Employee $boss, string $from): void
    {
        app(ReportingLineService::class)->reportTo($employee, $boss->id, $from, ReportingLineService::CHANGE_TRANSFER);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Role::findOrCreate('Admin'));
    }

    public function test_the_starting_figures(): void
    {
        $this->assertSame(80 * self::LAKH, $this->target($this->teamLeaderA), 'A: ₹75L lifted to the ₹80L minimum.');
        $this->assertSame(125 * self::LAKH, $this->target($this->teamLeaderB));
        $this->assertSame(230 * self::LAKH, $this->target($this->manager), 'M = A + B + D.');
        $this->assertSame(230 * self::LAKH, $this->target($this->cluster));
        $this->assertSame(230 * self::LAKH, $this->target($this->businessHead));
        $this->assertSame(230 * self::LAKH, $this->ledger()->companyTarget());
    }

    /** Q1: two numbers — own screen ignores joining, the team needs 10 days. */
    public function test_q1_a_late_joiners_own_target_is_full_but_brings_nothing_to_the_team(): void
    {
        $joiner = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeaderB, ['doj' => '2026-11-25', 'reporting_date' => '2026-11-25']);

        $this->assertSame(25 * self::LAKH, $this->target($joiner), 'Own screen: the full Silver amount.');
        $this->assertSame(0.0, $this->calculator->getHierarchyCallerTarget($joiner, $this->november()), '6 days: nothing for the team.');
        $this->assertSame(125 * self::LAKH, $this->target($this->teamLeaderB));
    }

    /** Q2 + Q3: 10+ calendar days on the rolls brings the full category. */
    public function test_q2_q3_a_joiner_with_ten_calendar_days_brings_their_full_category(): void
    {
        // 21–30 Nov is 10 calendar days, weekends included.
        $joiner = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeaderB, ['category' => self::GOLD, 'doj' => '2026-11-21', 'reporting_date' => '2026-11-21']);

        $this->assertSame(10, $this->ledger()->window($joiner->id)['days']);
        $this->assertSame(30 * self::LAKH, $this->calculator->getHierarchyCallerTarget($joiner, $this->november()));
        $this->assertSame(155 * self::LAKH, $this->target($this->teamLeaderB));

        $tooLate = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeaderB, ['doj' => '2026-11-22', 'reporting_date' => '2026-11-22']);

        $this->assertSame(0.0, $this->calculator->getHierarchyCallerTarget($tooLate, $this->november()), '9 days.');
    }

    /** Q4: a joiner still on the rolls counts to month-end; an exit recalculates. */
    public function test_q4_a_joiner_is_projected_to_month_end_until_an_exit_is_entered(): void
    {
        $this->travelTo(Carbon::create(2026, 11, 20, 11));

        $joiner = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeaderB, ['doj' => '2026-11-18', 'reporting_date' => '2026-11-18']);

        $this->assertSame(25 * self::LAKH, $this->calculator->getHierarchyCallerTarget($joiner, $this->november()), 'Only 3 days so far, 13 to month-end.');

        $joiner->update(['exit_status' => 'yes', 'exit_date' => '2026-11-22']);

        $this->assertSame(0.0, $this->calculator->getHierarchyCallerTarget($joiner->refresh(), $this->november()), 'Left after 5 days.');
    }

    /** Q5 + Q6: a mover's target is split by days, and the teams add up. */
    public function test_q5_q6_a_caller_moved_mid_month_is_split_by_days_and_the_teams_add_up(): void
    {
        $this->move($this->callersA[0], $this->teamLeaderB, '2026-11-16');

        $this->assertSame(12.5 * self::LAKH, $this->ledger()->contribution($this->callersA[0]->id, $this->teamLeaderA->id));
        $this->assertSame(12.5 * self::LAKH, $this->ledger()->contribution($this->callersA[0]->id, $this->teamLeaderB->id));
        $this->assertSame(80 * self::LAKH, $this->target($this->teamLeaderA), 'A: ₹62.5L, lifted to ₹80L.');
        $this->assertSame(137.5 * self::LAKH, $this->target($this->teamLeaderB));
        $this->assertSame(242.5 * self::LAKH, $this->target($this->manager), 'A + B + D, nothing counted twice.');
    }

    /** Q6: a team with under 10 days gets nothing; the rest goes to the other. */
    public function test_q6_a_team_with_under_ten_days_gets_nothing(): void
    {
        $this->move($this->callersA[0], $this->teamLeaderB, '2026-11-26');

        $this->assertSame(25 * self::LAKH, $this->ledger()->contribution($this->callersA[0]->id, $this->teamLeaderA->id), '25 days.');
        $this->assertSame(0.0, $this->ledger()->contribution($this->callersA[0]->id, $this->teamLeaderB->id), '5 days.');
    }

    /** Q7: when no team reached 10 days, they all share by days. */
    public function test_q7_with_no_team_reaching_ten_days_every_team_shares_by_days(): void
    {
        $joiner = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeaderA, ['doj' => '2026-11-15', 'reporting_date' => '2026-11-15']);
        $this->move($joiner, $this->teamLeaderB, '2026-11-22');

        // 16 days on the rolls: 7 with A (15–21), 9 with B (22–30).
        $this->assertSame(1093750.0, $this->ledger()->contribution($joiner->id, $this->teamLeaderA->id));
        $this->assertSame(1406250.0, $this->ledger()->contribution($joiner->id, $this->teamLeaderB->id));
    }

    /** Q8: a category change inside the month splits it by days. */
    public function test_q8_a_category_change_splits_the_month_by_days(): void
    {
        $this->actingAs($this->admin());

        app(EmployeePositionChangeService::class)->change(
            $this->callersB[0],
            Employee::DESIGNATION_CALLER,
            null,
            self::GOLD,
            Carbon::parse('2026-11-16'),
            null,
            auth()->user(),
        );

        // Silver 1–15 Nov, Gold 16–30 Nov: 25L × 15/30 + 30L × 15/30.
        $this->assertSame(27.5 * self::LAKH, $this->target($this->callersB[0]->refresh()));
        $this->assertSame(27.5 * self::LAKH, $this->calculator->getHierarchyCallerTarget($this->callersB[0], $this->november()));
        $this->assertSame(25 * self::LAKH, $this->calculator->getTarget($this->callersB[0], Carbon::create(2026, 10, 1)), 'October keeps Silver.');
    }

    /** Q9 + Q9b: every Team Leader carries at least ₹80L, whatever the headcount. */
    public function test_q9_every_team_leader_carries_at_least_eighty_lakh(): void
    {
        $empty = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager);
        $four = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager);

        $this->employee(Employee::DESIGNATION_CALLER, $four);
        $this->employee(Employee::DESIGNATION_CALLER, $four);
        $this->employee(Employee::DESIGNATION_CALLER, $four, ['doj' => '2026-11-25', 'reporting_date' => '2026-11-25']);
        $this->employee(Employee::DESIGNATION_CALLER, $four, ['doj' => '2026-11-26', 'reporting_date' => '2026-11-26']);

        $this->assertSame(80 * self::LAKH, $this->target($empty), 'No callers at all.');
        $this->assertSame(80 * self::LAKH, $this->target($four), 'Four callers bringing only ₹50L.');
        $this->assertSame(30 * self::LAKH, $this->ledger()->floorTopUp($four->id));
        $this->assertSame(125 * self::LAKH, $this->target($this->teamLeaderB), 'Above the minimum: just the callers.');
    }

    /** Q9c: no minimum with fewer than 10 days in the seat. */
    public function test_q9c_a_team_leader_under_ten_days_in_the_seat_gets_no_minimum(): void
    {
        $joinedLate = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager, ['doj' => '2026-11-25', 'reporting_date' => '2026-11-25']);
        $leftEarly = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager, ['exit_status' => 'yes', 'exit_date' => '2026-11-08']);
        $leftLate = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager, ['exit_status' => 'yes', 'exit_date' => '2026-11-20']);

        $this->assertSame(0.0, $this->target($joinedLate), '6 days in the seat.');
        $this->assertSame(0.0, $this->target($leftEarly), '8 days in the seat.');
        $this->assertSame(80 * self::LAKH, $this->target($leftLate), '20 days in the seat keeps the minimum.');
    }

    /** Q10 + Q11: every level is exactly what its team brings. */
    public function test_q10_q11_every_level_is_the_sum_of_what_its_team_brings(): void
    {
        $ledger = $this->ledger();

        foreach ([$this->teamLeaderA, $this->teamLeaderB, $this->manager, $this->cluster, $this->businessHead] as $boss) {
            $parts = array_sum($ledger->contributions($boss->id, Employee::DESIGNATION_CALLER)) + array_sum($ledger->floorContributions($boss->id));

            $this->assertEqualsWithDelta($this->target($boss), $parts, 0.01, $boss->emp_name);
        }

        $this->assertSame(
            $this->target($this->teamLeaderA) + $this->target($this->teamLeaderB) + $this->target($this->directCaller),
            $this->target($this->manager),
        );
    }

    /** Q12 + Q13: a broken line climbs the own record; with nobody active, company only. */
    public function test_q12_q13_people_whose_bosses_left_climb_their_own_record_or_count_for_the_company(): void
    {
        $goneTeamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager, ['exit_status' => 'yes', 'exit_date' => '2026-10-31']);
        $climber = $this->employee(Employee::DESIGNATION_CALLER, $goneTeamLeader);

        $goneManager = $this->employee(Employee::DESIGNATION_MANAGER, null, ['exit_status' => 'yes', 'exit_date' => '2026-10-31']);
        $orphan = $this->employee(Employee::DESIGNATION_CALLER, $goneManager);

        // The climber's record names M: they count there.
        $this->assertSame(25 * self::LAKH, $this->ledger()->contribution($climber->id, $this->manager->id));
        $this->assertSame(255 * self::LAKH, $this->target($this->manager));

        // Nobody on the orphan's record is on the rolls: company only.
        $this->assertSame(0.0, $this->ledger()->contribution($orphan->id, $this->businessHead->id));
        $this->assertSame([$orphan->id], $this->ledger()->brokenLineIds());
        $this->assertSame($this->target($this->businessHead) + 25 * self::LAKH, $this->ledger()->companyTarget());
    }

    /** Q14: a promotion counts the whole month as the position on its last day. */
    public function test_q14_a_promoted_caller_is_a_team_leader_for_the_whole_month(): void
    {
        $this->actingAs($this->admin());

        app(EmployeePositionChangeService::class)->change(
            $this->callersA[0],
            Employee::DESIGNATION_TEAM_LEADER,
            $this->manager->id,
            null,
            Carbon::parse('2026-11-16'),
            null,
            auth()->user(),
        );

        $promoted = $this->callersA[0]->refresh();

        $this->assertSame(80 * self::LAKH, $this->target($promoted), 'A Team Leader with 15 days in the seat: the minimum.');
        $this->assertNotContains($promoted->id, $this->ledger()->memberIds($this->teamLeaderA->id), 'A carries nothing for them in November.');
        $this->assertSame(50 * self::LAKH, $this->ledger()->teamSum($this->teamLeaderA->id));
        $this->assertSame(25 * self::LAKH, $this->calculator->getTarget($promoted, Carbon::create(2026, 10, 1)), 'October: still a caller.');
    }

    /** Q21: no category with an amount → nothing, and it is listed. */
    public function test_q21_a_caller_without_a_category_counts_nothing_and_is_listed(): void
    {
        $noCategory = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeaderB, ['category' => null]);
        $nil = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeaderB, ['category' => 'nil']);

        $this->assertSame(0.0, $this->target($noCategory));
        $this->assertSame(0.0, $this->target($nil), 'NIL is a ₹1 placeholder, not a target.');
        $this->assertEqualsCanonicalizing([$noCategory->id, $nil->id], $this->ledger()->missingCategoryIds());
        $this->assertSame(125 * self::LAKH, $this->target($this->teamLeaderB));
    }

    public function test_the_admin_sees_who_makes_this_months_targets_wrong(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $noCategory = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeaderB, ['emp_name' => 'Nina Nocategory', 'category' => null]);
        $goneManager = $this->employee(Employee::DESIGNATION_MANAGER, null, ['exit_status' => 'yes', 'exit_date' => '2026-10-31']);
        $this->employee(Employee::DESIGNATION_CALLER, $goneManager, ['emp_name' => 'Omar Orphan']);

        $this->actingAs($this->admin());

        Livewire::test(ListEmployees::class)
            ->assertSeeLivewire(TargetDataWarnings::class);

        Livewire::test(TargetDataWarnings::class)
            ->assertSee(['Target data to fix', 'without a Target Category', 'Nina Nocategory', 'broken reporting line', 'Omar Orphan']);

        $this->actingAs(User::factory()->create(['employee_id' => $noCategory->id]));

        $this->assertFalse(TargetDataWarnings::canView());
    }
}
