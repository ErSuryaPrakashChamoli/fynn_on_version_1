<?php

namespace Tests\Feature;

use App\Filament\Imports\EmployeeImporter;
use App\Filament\Imports\EmployeeUpdateImporter;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\Performance\EmployeeFigureBreakdownService;
use App\Services\ReportingLineService;
use App\Support\EmployeeLookupPeriod;
use App\Support\ReportingTree;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A promotion only changes somebody's designation from its effective date.
 *
 * Business Head BH ── Cluster Manager K ── Manager M ── Team Leader T ──
 * Callers C1, C2, C3. On 16 Nov 2026 M is promoted to Cluster Manager
 * reporting straight to BH. October must still be M's month as a Manager —
 * with the team that reported to them through manager_id — and K's month as
 * their Cluster Manager; without that M's October target and achievement
 * read 0 (Employee Lookup, ME0045).
 */
class DesignationChangeMonthWiseTest extends TestCase
{
    use RefreshDatabase;

    private const CALLER_TARGET = 2500000.0;

    /** T's three callers (₹75L), lifted to the ₹80L Team Leader minimum. */
    private const TEAM_TARGET = 8000000.0;

    private const PROMOTION_DAY = '2026-11-16';

    private const BACKFILL_MIGRATION = 'migrations/2026_11_16_105540_backfill_designation_changes_in_employee_reporting_history.php';

    private Employee $businessHead;

    private Employee $cluster;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $callerOne;

    private Employee $callerTwo;

    private Employee $callerThree;

    private AchievementCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::PROMOTION_DAY.' 11:00'));

        Role::firstOrCreate(['name' => 'Admin']);
        $this->actingAs(User::factory()->create()->assignRole('Admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->calculator = new AchievementCalculatorService;

        $this->businessHead = $this->employee(Employee::DESIGNATION_BUSINESS_HEAD);
        $this->cluster = $this->employee(Employee::DESIGNATION_CLUSTER, $this->businessHead);
        $this->manager = $this->employee(Employee::DESIGNATION_MANAGER, $this->cluster);
        $this->teamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager);
        $this->callerOne = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeader);
        $this->callerTwo = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeader);
        $this->callerThree = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeader);
    }

    private function employee(int $designation, ?Employee $boss = null): Employee
    {
        return Employee::factory()->create([
            'designation' => $designation,
            'position' => 'Staff',
            'category' => (string) self::CALLER_TARGET,
            'cost_center' => 'kanak_kumar',
            'unit_name' => 'kanak_kumar',
            'exit_status' => 'no',
            'exit_date' => null,
            'doj' => '2026-01-01',
            'reporting_date' => '2026-01-01',
            ...app(ReportingLineService::class)->columnsUnder($boss?->id),
        ]);
    }

    /**
     * A promotion that takes the team along — through the employee import,
     * the one path that still does since the Edit page's Position field
     * was locked (2026-11-16: "Change Position / Target" needs the team
     * handed over first). Promotions saved on the Edit page before then
     * look exactly like this in the history.
     */
    private function promote(Employee $employee, int $designation, Employee $reportsTo): void
    {
        $this->importRow([
            'emp_id' => $employee->emp_id,
            'designation' => Employee::designationOptions()[$designation],
            'reports_to' => $reportsTo->emp_id,
        ]);

        $employee->refresh();

        $this->assertSame($designation, $employee->designation);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function importRow(array $values): void
    {
        $import = Import::query()->forceCreate([
            'file_name' => 'employees.csv',
            'file_path' => 'employees.csv',
            'importer' => EmployeeUpdateImporter::class,
            'total_rows' => 1,
            'user_id' => auth()->id(),
        ]);

        $row = [
            'emp_id' => '',
            'emp_name' => '',
            'email' => '',
            'designation' => '',
            'doj' => '',
            'reporting_date' => '',
            'reports_to' => '',
            'cost_center' => '',
            'unit_name' => '',
            'status' => '',
            'inactive_date' => '',
            ...$values,
        ];
        $columns = array_keys($row);

        (new EmployeeUpdateImporter($import, array_combine($columns, $columns), ['move' => EmployeeImporter::MOVE_ALONE]))($row);
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

    private function october(): Carbon
    {
        return Carbon::create(2026, 10, 1);
    }

    private function november(): Carbon
    {
        return Carbon::create(2026, 11, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Months before a promotion
    |--------------------------------------------------------------------------
    */

    public function test_a_promoted_manager_keeps_their_team_in_the_months_before_the_promotion(): void
    {
        $this->disburse($this->callerOne, '2026-10-10', 9000000);

        $this->promote($this->manager, Employee::DESIGNATION_CLUSTER, $this->businessHead);

        $this->assertSame(Employee::DESIGNATION_CLUSTER, $this->manager->designation);
        $this->assertSame(self::TEAM_TARGET, $this->calculator->getTarget($this->manager, $this->october()));
        $this->assertSame(9000000.0, $this->calculator->getCountAchievement($this->manager, $this->october()));
    }

    public function test_the_old_cluster_manager_keeps_the_promoted_managers_team_for_those_months(): void
    {
        $this->disburse($this->callerOne, '2026-10-10', 9000000);

        $this->promote($this->manager, Employee::DESIGNATION_CLUSTER, $this->businessHead);

        $this->assertSame(self::TEAM_TARGET, $this->calculator->getTarget($this->cluster, $this->october()));
        $this->assertSame(9000000.0, $this->calculator->getCountAchievement($this->cluster, $this->october()));
        $this->assertContains($this->teamLeader->id, ReportingTree::load($this->october())->childIds($this->manager->id));
        $this->assertSame($this->cluster->id, ReportingTree::load($this->october())->bossId($this->manager->id));
    }

    public function test_the_employee_lookup_shows_the_promoted_managers_october_as_a_manager(): void
    {
        $this->disburse($this->callerOne, '2026-10-10', 9000000);

        $this->promote($this->manager, Employee::DESIGNATION_CLUSTER, $this->businessHead);

        $summary = app(EmployeeFigureBreakdownService::class)
            ->summary($this->manager, EmployeeLookupPeriod::forMonth($this->october()));

        $this->assertSame(self::TEAM_TARGET, $summary['target']);
        $this->assertSame(9000000.0, $summary['count_achievement']);
        $this->assertSame(112.5, $summary['percentage']);
        // October is paid as a Manager, over the Manager's own team, on the
        // PPP scheme (₹90L over 3 eligible callers = ₹30L PPP → 0.06%).
        $this->assertEqualsWithDelta(5400.0, $summary['incentive'], 0.01);
        $this->assertSame(Employee::DESIGNATION_MANAGER, $this->calculator->designationFor($this->manager, $this->october()));
        $this->assertEqualsWithDelta(5400.0, $this->calculator->getPerformance($this->manager, $this->october())['incentive'], 0.01);
    }

    public function test_the_month_of_the_promotion_is_split_on_its_effective_date(): void
    {
        $this->disburse($this->callerOne, '2026-11-10', 1000000);
        $this->disburse($this->callerTwo, self::PROMOTION_DAY, 500000);

        $this->promote($this->manager, Employee::DESIGNATION_CLUSTER, $this->businessHead);

        $this->assertSame(1500000.0, $this->calculator->getCountAchievement($this->manager, $this->november()));
        $this->assertSame(1000000.0, $this->calculator->getCountAchievement($this->cluster, $this->november()));
        $this->assertSame(1500000.0, $this->calculator->getCountAchievement($this->businessHead, $this->november()));
        $this->assertSame(Employee::DESIGNATION_CLUSTER, $this->calculator->designationFor($this->manager, $this->november()));
    }

    public function test_a_promoted_caller_keeps_their_caller_target_in_the_months_before_the_promotion(): void
    {
        $this->promote($this->callerThree, Employee::DESIGNATION_TEAM_LEADER, $this->manager);

        $this->assertSame(self::CALLER_TARGET, $this->calculator->getTarget($this->callerThree, $this->october()));
        $this->assertSame(self::TEAM_TARGET, $this->calculator->getTarget($this->teamLeader, $this->october()));
    }

    /*
    |--------------------------------------------------------------------------
    | Recording the change
    |--------------------------------------------------------------------------
    */

    public function test_a_promotion_dates_the_designation_change(): void
    {
        $this->promote($this->manager, Employee::DESIGNATION_CLUSTER, $this->businessHead);

        $row = EmployeeReportingHistory::query()
            ->where('employee_id', $this->manager->id)
            ->where('change_type', ReportingLineService::CHANGE_DESIGNATION)
            ->sole();

        $this->assertSame(Employee::DESIGNATION_MANAGER, $row->old_designation);
        $this->assertSame(Employee::DESIGNATION_CLUSTER, $row->new_designation);
        $this->assertSame(self::PROMOTION_DAY, $row->effective_date->toDateString());
        $this->assertNull($row->effective_to);
        $this->assertSame($this->businessHead->id, $row->new_business_head_id);
    }

    public function test_saving_without_a_designation_change_writes_no_designation_row(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->manager->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(EmployeeReportingHistory::query()->where('change_type', ReportingLineService::CHANGE_DESIGNATION)->exists());
    }

    public function test_a_later_reporting_move_leaves_the_designation_row_open(): void
    {
        $this->promote($this->manager, Employee::DESIGNATION_CLUSTER, $this->businessHead);

        $this->travelTo(Carbon::parse('2026-11-20 11:00'));
        $otherBusinessHead = $this->employee(Employee::DESIGNATION_BUSINESS_HEAD);
        app(ReportingLineService::class)->reportTo($this->manager, $otherBusinessHead->id, '2026-11-20');

        $this->assertNull(EmployeeReportingHistory::query()
            ->where('employee_id', $this->manager->id)
            ->where('change_type', ReportingLineService::CHANGE_DESIGNATION)
            ->sole()
            ->effective_to);
    }

    public function test_an_import_row_that_promotes_dates_the_designation_change(): void
    {
        $this->importRow([
            'emp_id' => $this->teamLeader->emp_id,
            'designation' => 'Manager',
            'reports_to' => $this->cluster->emp_id,
        ]);

        $designationRow = EmployeeReportingHistory::query()
            ->where('employee_id', $this->teamLeader->id)
            ->where('change_type', ReportingLineService::CHANGE_DESIGNATION)
            ->sole();

        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $designationRow->old_designation);
        $this->assertSame(Employee::DESIGNATION_MANAGER, $designationRow->new_designation);
        $this->assertSame(self::PROMOTION_DAY, $designationRow->effective_date->toDateString());
        $this->assertSame(self::TEAM_TARGET, $this->calculator->getTarget($this->teamLeader->refresh(), $this->october()));
    }

    public function test_the_lifecycle_shows_the_promotion(): void
    {
        $this->promote($this->manager, Employee::DESIGNATION_CLUSTER, $this->businessHead);

        $histories = $this->manager->reportingHistories()
            ->with(['oldSupervisor', 'oldManager', 'oldCluster', 'newSupervisor', 'newManager', 'newCluster', 'oldBusinessHead', 'newBusinessHead', 'updatedBy'])
            ->get();

        $this->view('filament.employees.lifecycle', ['employee' => $this->manager, 'histories' => $histories])
            ->assertSeeInOrder(['Promotion', 'Designation:', 'Manager', '→', 'Cluster Manager']);
    }

    /*
    |--------------------------------------------------------------------------
    | Promotions saved before designation rows existed
    |--------------------------------------------------------------------------
    */

    public function test_the_backfill_dates_a_promotion_saved_before_designation_rows_existed(): void
    {
        $this->disburse($this->callerOne, '2026-10-10', 9000000);

        $this->promote($this->manager, Employee::DESIGNATION_CLUSTER, $this->businessHead);

        // As production saved it: the team re-slotted, no designation row.
        EmployeeReportingHistory::query()->where('change_type', ReportingLineService::CHANGE_DESIGNATION)->delete();

        $this->assertSame(0.0, $this->calculator->getTarget($this->manager, $this->october()));
        $this->assertSame(0.0, $this->calculator->getCountAchievement($this->manager, $this->october()));

        (require database_path(self::BACKFILL_MIGRATION))->up();

        $row = EmployeeReportingHistory::query()
            ->where('change_type', ReportingLineService::CHANGE_DESIGNATION)
            ->sole();

        $this->assertSame($this->manager->id, $row->employee_id);
        $this->assertSame(Employee::DESIGNATION_MANAGER, $row->old_designation);
        $this->assertSame(Employee::DESIGNATION_CLUSTER, $row->new_designation);
        $this->assertSame(self::PROMOTION_DAY, $row->effective_date->toDateString());

        $this->assertSame(self::TEAM_TARGET, $this->calculator->getTarget($this->manager, $this->october()));
        $this->assertSame(9000000.0, $this->calculator->getCountAchievement($this->manager, $this->october()));
        $this->assertSame(9000000.0, $this->calculator->getCountAchievement($this->cluster, $this->october()));
    }

    public function test_the_backfill_runs_once_and_ignores_ordinary_moves(): void
    {
        $this->promote($this->manager, Employee::DESIGNATION_CLUSTER, $this->businessHead);
        EmployeeReportingHistory::query()->where('change_type', ReportingLineService::CHANGE_DESIGNATION)->delete();

        $otherTeamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager);
        app(ReportingLineService::class)->reportTo($this->callerTwo, $otherTeamLeader->id, self::PROMOTION_DAY);

        (require database_path(self::BACKFILL_MIGRATION))->up();
        (require database_path(self::BACKFILL_MIGRATION))->up();

        $this->assertSame(
            [$this->manager->id],
            EmployeeReportingHistory::query()->where('change_type', ReportingLineService::CHANGE_DESIGNATION)->pluck('employee_id')->all(),
        );
    }
}
