<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use App\Support\ReportingTimeline;
use App\Support\ReportingTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The live October 2026 restructure, rebuilt as it was keyed in on 14 Nov,
 * then corrected by the 2026_11_16_112144 migration (run after the
 * designation backfill, as on deploy).
 *
 * Kanak Kumar and Kunwar Pratap Singh (Cluster Managers) left on 30 Sep.
 * Anuj Singh Thakur, Deepak Singh and Nitin Kumar were promoted from Manager
 * to Cluster Manager under Sales Admin — really from 1 Oct, recorded 14 Nov.
 */
class OctoberRestructureCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private const CALLER_TARGET = 2500000.0;

    private const RECORDED = '2026-11-14';

    private const FOLLOWS_ANUJ = "Follows Anuj Singh Thakur's designation change.";

    /** @var array<string, Employee> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 11, 16, 11));

        $this->buildTheTeamsAsTheyWereBeforeTheRestructure();
        $this->keyInTheRestructureOnTheFourteenth();
    }

    private function buildTheTeamsAsTheyWereBeforeTheRestructure(): void
    {
        $this->person('salesAdmin', 'ME0063', 'Sales Admin', Employee::DESIGNATION_BUSINESS_HEAD);
        $this->person('kanak', 'ME0006', 'Kanak Kumar', Employee::DESIGNATION_CLUSTER);
        $this->person('kunwar', 'ME0008', 'Kunwar Pratap Singh', Employee::DESIGNATION_CLUSTER);

        foreach (['anuj' => ['ME0045', 'Anuj Singh Thakur'], 'deepak' => ['ME0161', 'Deepak Singh'], 'nitin' => ['ME0009', 'Nitin Kumar']] as $key => [$empId, $name]) {
            $this->person($key, $empId, $name, Employee::DESIGNATION_MANAGER, ['cluster_id' => $this->id('kanak')]);
        }

        $this->person('ronit', 'ME0271', 'Ronit Tomar', Employee::DESIGNATION_MANAGER, ['cluster_id' => $this->id('kanak')], '2026-08-17');
        $this->person('prabhat', 'ME0033', 'Prabhat Tyagi', Employee::DESIGNATION_MANAGER, ['cluster_id' => $this->id('kanak')]);

        $underAnuj = ['manager_id' => $this->id('anuj'), 'cluster_id' => $this->id('kanak')];
        $this->person('nisha', 'ME0014', 'Nisha Kumari', Employee::DESIGNATION_TEAM_LEADER, $underAnuj);
        $this->person('shabnoor', 'ME0187', 'Shabnoor', Employee::DESIGNATION_TEAM_LEADER, $underAnuj);
        $this->person('aarish', 'FA0548', 'Aarish', Employee::DESIGNATION_CALLER, ['superviser_id' => $this->id('nisha')] + $underAnuj);

        $underDeepak = ['manager_id' => $this->id('deepak'), 'cluster_id' => $this->id('kanak')];
        $this->person('vinayak', 'ME0080', 'Vinayak Gupta', Employee::DESIGNATION_TEAM_LEADER, $underDeepak);
        $this->person('vinayaksCaller', 'FA0600', 'Vinayak Caller', Employee::DESIGNATION_CALLER, ['superviser_id' => $this->id('vinayak')] + $underDeepak);

        // Rohit Sharma was filed as a Manager though he sat as Cluster Manager.
        $this->person('rohit', 'ME0148', 'Rohit Sharma', Employee::DESIGNATION_MANAGER, ['cluster_id' => $this->id('kanak')]);
        $this->person('chanchal', 'ME0189', 'Chanchal Chaudhary', Employee::DESIGNATION_MANAGER, ['cluster_id' => $this->id('rohit')], '2026-05-07');
        $this->person('preeti', 'ME0190', 'Preeti', Employee::DESIGNATION_CALLER, ['superviser_id' => $this->id('chanchal'), 'manager_id' => $this->id('chanchal'), 'cluster_id' => $this->id('rohit')], '2026-05-07');
    }

    private function keyInTheRestructureOnTheFourteenth(): void
    {
        $salesAdmin = $this->id('salesAdmin');

        // Left on 30 Sep (Kanak, Kunwar), 7 Sep (Shabnoor), 6 Oct (Prabhat), 6 Aug / 27 Jul (Rohit, Chanchal).
        foreach (['kanak' => '2026-09-30', 'kunwar' => '2026-09-30', 'shabnoor' => '2026-09-07', 'prabhat' => '2026-10-06', 'rohit' => '2026-08-06', 'chanchal' => '2026-07-27'] as $key => $day) {
            $this->leave($key, $day);
        }

        // Untracked edits: Kanak's and Kunwar's Business Head, Prabhat into Kunwar's cluster.
        $this->setColumns('kanak', ['business_head_id' => $salesAdmin]);
        $this->setColumns('kunwar', ['business_head_id' => $salesAdmin]);
        $this->setColumns('prabhat', ['cluster_id' => $this->id('kunwar'), 'business_head_id' => $salesAdmin]);

        // 5 Oct: Vinayak moved to Anuj with his team, keyed in when Anuj already sat under Kunwar.
        $this->change('vinayak', '2026-10-05', ['manager_id' => $this->id('anuj'), 'cluster_id' => $this->id('kunwar')], 'transfer');
        $this->change('vinayaksCaller', '2026-10-05', ['manager_id' => $this->id('anuj'), 'cluster_id' => $this->id('kunwar')], 'transfer');

        // 19 Oct: Vaibhav joins under Kanak, who had already left.
        $this->person('vaibhav', 'ME0294', 'Vaibhav Agrawal', Employee::DESIGNATION_MANAGER, ['cluster_id' => $this->id('kanak')], '2026-10-19');

        // 14 Nov: the three promotions, each to Sales Admin.
        foreach (['anuj', 'deepak', 'nitin'] as $key) {
            DB::table('employees')->where('id', $this->id($key))->update(['designation' => Employee::DESIGNATION_CLUSTER]);
            $this->change($key, self::RECORDED, ['cluster_id' => null, 'business_head_id' => $salesAdmin], 'reporting_change', 'Reporting hierarchy updated from Employee Edit.');
        }

        // Anuj's team re-slotted under him; Shabnoor had left, so no row was written for her.
        $anujAsCluster = ['manager_id' => null, 'cluster_id' => $this->id('anuj'), 'business_head_id' => $salesAdmin];

        foreach (['nisha', 'aarish', 'vinayak', 'vinayaksCaller'] as $key) {
            $this->change($key, self::RECORDED, $anujAsCluster, 'reporting_change', self::FOLLOWS_ANUJ);
        }

        $this->setColumns('shabnoor', $anujAsCluster);

        // Ronit and Vaibhav moved from Kanak to the new Cluster Managers.
        $this->change('ronit', self::RECORDED, ['cluster_id' => $this->id('nitin'), 'business_head_id' => $salesAdmin]);
        $this->change('vaibhav', self::RECORDED, ['cluster_id' => $this->id('anuj'), 'business_head_id' => $salesAdmin]);
    }

    private function runTheMigrations(): void
    {
        (require database_path('migrations/2026_11_16_105540_backfill_designation_changes_in_employee_reporting_history.php'))->up();
        (require database_path('migrations/2026_11_16_112144_correct_reporting_history_for_october_2026_restructure.php'))->up();
    }

    /*
    |--------------------------------------------------------------------------
    | The screen the user reported
    |--------------------------------------------------------------------------
    */

    public function test_before_the_correction_anujs_callers_read_as_moved_on_the_fourteenth(): void
    {
        $calculator = new AchievementCalculatorService;

        $this->assertNotNull($calculator->reportingSpanNote($this->people['aarish'], $this->people['anuj'], Carbon::create(2026, 11, 1)));
    }

    public function test_anujs_callers_count_their_full_target_for_october_and_november(): void
    {
        $this->runTheMigrations();

        $calculator = new AchievementCalculatorService;

        foreach ([10, 11] as $month) {
            $timeline = ReportingTimeline::forMonth(Carbon::create(2026, $month, 1));

            $this->assertSame(self::CALLER_TARGET, $calculator->callerTargetShares($this->people['anuj'], $timeline)[$this->id('aarish')]);
            $this->assertNull($calculator->reportingSpanNote($this->people['aarish'], $this->people['anuj'], Carbon::create(2026, $month, 1)));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Promotions from 1 Oct
    |--------------------------------------------------------------------------
    */

    public function test_the_promotions_take_effect_on_the_first_of_october(): void
    {
        $this->runTheMigrations();

        foreach (['anuj', 'deepak', 'nitin'] as $key) {
            $this->assertSame($this->id('salesAdmin'), $this->bossOn($key, '2026-10-01'), $key);
            $this->assertSame(Employee::DESIGNATION_CLUSTER, ReportingTimeline::designationOn($this->people[$key]->fresh(), Carbon::parse('2026-10-01')), $key);
            $this->assertSame(Employee::DESIGNATION_MANAGER, ReportingTimeline::designationOn($this->people[$key]->fresh(), Carbon::parse('2026-09-30')), $key);
        }

        $this->assertSame($this->id('kanak'), $this->bossOn('anuj', '2026-09-30'));

        // Their own lifecycle now ends the old line on 1 Oct.
        $this->assertSame('2026-10-01', substr((string) DB::table('employee_reporting_history')
            ->where('employee_id', $this->id('anuj'))->where('change_type', 'joining')->value('effective_to'), 0, 10));
    }

    public function test_the_team_stays_under_their_boss_across_the_promotion(): void
    {
        $this->runTheMigrations();

        foreach (['2026-09-15', '2026-10-01', '2026-10-20', '2026-11-15'] as $day) {
            $this->assertSame($this->id('anuj'), $this->bossOn('nisha', $day), $day);
            $this->assertContains($this->id('aarish'), ReportingTree::load(Carbon::parse($day))->descendantIds($this->id('anuj'), true), $day);
        }
    }

    public function test_a_team_leader_moved_in_during_october_joins_from_their_own_move_date(): void
    {
        $this->runTheMigrations();

        $this->assertSame($this->id('deepak'), $this->bossOn('vinayak', '2026-10-04'));
        $this->assertSame($this->id('anuj'), $this->bossOn('vinayak', '2026-10-05'));
        $this->assertSame($this->id('anuj'), $this->bossOn('vinayak', '2026-11-01'));

        $days = ReportingTimeline::forMonth(Carbon::create(2026, 10, 1))->branchDays($this->id('anuj'), includeBoss: false);
        $this->assertSame(27, $days[$this->id('vinayaksCaller')]);
    }

    public function test_an_exited_report_re_slotted_without_a_row_keeps_their_old_months(): void
    {
        $this->runTheMigrations();

        $this->assertSame($this->id('anuj'), $this->bossOn('shabnoor', '2026-08-10'));
        $this->assertContains($this->id('shabnoor'), ReportingTree::load(Carbon::parse('2026-08-10'))->descendantIds($this->id('anuj')));
    }

    /*
    |--------------------------------------------------------------------------
    | The other moves
    |--------------------------------------------------------------------------
    */

    public function test_ronit_and_vaibhav_follow_the_restructure(): void
    {
        $this->runTheMigrations();

        $this->assertSame($this->id('kanak'), $this->bossOn('ronit', '2026-09-30'));
        $this->assertSame($this->id('nitin'), $this->bossOn('ronit', '2026-10-01'));
        $this->assertSame($this->id('anuj'), $this->bossOn('vaibhav', '2026-10-19'));
    }

    public function test_deepak_and_prabhat_moved_to_kunwars_cluster_in_september(): void
    {
        $this->runTheMigrations();

        $this->assertSame($this->id('kanak'), $this->bossOn('deepak', '2026-08-31'));
        $this->assertSame($this->id('kunwar'), $this->bossOn('deepak', '2026-09-01'));
        $this->assertSame($this->id('salesAdmin'), $this->bossOn('deepak', '2026-10-01'));

        $this->assertSame($this->id('kanak'), $this->bossOn('prabhat', '2026-09-01'));
        $this->assertSame($this->id('kunwar'), $this->bossOn('prabhat', '2026-09-02'));
    }

    public function test_rohit_sharma_is_a_cluster_manager_over_chanchals_team(): void
    {
        $this->runTheMigrations();

        $this->assertSame(Employee::DESIGNATION_CLUSTER, (int) $this->people['rohit']->fresh()->designation);
        $this->assertSame($this->id('rohit'), $this->bossOn('chanchal', '2026-06-15'));
        $this->assertContains($this->id('preeti'), ReportingTree::load(Carbon::parse('2026-06-15'))->descendantIds($this->id('rohit')));
    }

    /*
    |--------------------------------------------------------------------------
    | Safety
    |--------------------------------------------------------------------------
    */

    public function test_running_the_correction_twice_changes_nothing_more(): void
    {
        $this->runTheMigrations();
        $first = DB::table('employee_reporting_history')->orderBy('id')->get(['id', 'employee_id', 'effective_date', 'new_manager_id', 'new_cluster_id'])->toArray();

        (require database_path('migrations/2026_11_16_112144_correct_reporting_history_for_october_2026_restructure.php'))->up();

        $this->assertEquals($first, DB::table('employee_reporting_history')->orderBy('id')->get(['id', 'employee_id', 'effective_date', 'new_manager_id', 'new_cluster_id'])->toArray());
    }

    public function test_a_database_without_these_employees_is_left_alone(): void
    {
        DB::table('employee_reporting_history')->delete();
        foreach (DB::table('employees')->pluck('id') as $employeeId) {
            DB::table('employees')->where('id', $employeeId)->update(['emp_id' => 'X'.$employeeId]);
        }

        $this->person('someoneElse', 'FA9999', 'Someone Else', Employee::DESIGNATION_CALLER);
        $before = DB::table('employee_reporting_history')->get()->toArray();

        $this->runTheMigrations();

        $this->assertEquals($before, DB::table('employee_reporting_history')->get()->toArray());
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, int|null>  $columns
     */
    private function person(string $key, string $empId, string $name, int $designation, array $columns = [], string $reportingDate = '2026-01-01'): void
    {
        $this->people[$key] = Employee::factory()->create([
            'emp_id' => $empId,
            'emp_name' => $name,
            'designation' => $designation,
            'exit_status' => 'no',
            'exit_date' => null,
            'doj' => $reportingDate,
            'reporting_date' => $reportingDate,
            'category' => (string) self::CALLER_TARGET,
            'superviser_id' => null,
            'manager_id' => null,
            'cluster_id' => null,
            'business_head_id' => null,
            ...$columns,
        ]);
    }

    private function id(string $key): int
    {
        return $this->people[$key]->id;
    }

    private function bossOn(string $key, string $day): ?int
    {
        return ReportingTree::load(Carbon::parse($day))->bossId($this->id($key));
    }

    /**
     * @param  array<string, int|null>  $columns
     */
    private function setColumns(string $key, array $columns): void
    {
        DB::table('employees')->where('id', $this->id($key))->update($columns);
    }

    /**
     * A reporting change as ReportingLineService records it.
     *
     * @param  array<string, int|null>  $columns
     */
    private function change(string $key, string $day, array $columns, string $type = 'reporting_change', ?string $remarks = null): void
    {
        $current = (array) DB::table('employees')->where('id', $this->id($key))->first(['superviser_id', 'manager_id', 'cluster_id', 'business_head_id']);
        $new = $columns + $current;

        DB::table('employees')->where('id', $this->id($key))->update($new);

        DB::table('employee_reporting_history')->insert([
            'employee_id' => $this->id($key),
            'old_superviser_id' => $current['superviser_id'],
            'old_manager_id' => $current['manager_id'],
            'old_cluster_id' => $current['cluster_id'],
            'old_business_head_id' => $current['business_head_id'],
            'new_superviser_id' => $new['superviser_id'],
            'new_manager_id' => $new['manager_id'],
            'new_cluster_id' => $new['cluster_id'],
            'new_business_head_id' => $new['business_head_id'],
            'effective_date' => $day,
            'change_type' => $type,
            'remarks' => $remarks,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function leave(string $key, string $day): void
    {
        DB::table('employees')->where('id', $this->id($key))->update(['exit_status' => 'yes', 'exit_date' => $day]);

        DB::table('employee_reporting_history')->insert([
            'employee_id' => $this->id($key),
            'effective_date' => $day,
            'change_type' => 'exit',
            'remarks' => 'Employee exited.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
