<?php

namespace Tests\Feature;

use App\Filament\Imports\EmployeeImporter;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Models\HierarchyTransferLog;
use App\Models\User;
use App\Services\HierarchyReassignmentService;
use App\Services\ReportingLineService;
use App\Support\HierarchyHelper;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An employee only ever picks the one person they report to. Every
 * reporting column follows from that boss, and a move carries the whole
 * team below along with it.
 *
 * Business Head BH
 * ├ Cluster Manager CM ── Manager M ── Team Leader TL ── Caller C
 * └ Cluster Manager CM2
 */
class ReportingLineChangeTest extends TestCase
{
    use RefreshDatabase;

    private const REPORTING_DATE = '2025-01-15';

    private ReportingLineService $service;

    private Employee $businessHead;

    private Employee $cluster;

    private Employee $otherCluster;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        $this->actingAs(User::factory()->create()->assignRole('Admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->service = app(ReportingLineService::class);

        $this->businessHead = $this->employee(Employee::DESIGNATION_BUSINESS_HEAD);
        $this->cluster = $this->employee(Employee::DESIGNATION_CLUSTER, $this->businessHead);
        $this->otherCluster = $this->employee(Employee::DESIGNATION_CLUSTER, $this->businessHead);
        $this->manager = $this->employee(Employee::DESIGNATION_MANAGER, $this->cluster);
        $this->teamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager);
        $this->caller = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeader);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(int $designation, ?Employee $boss = null, array $attributes = []): Employee
    {
        return Employee::factory()->create([
            'designation' => $designation,
            'position' => 'Staff',
            'category' => '2500000',
            'cost_center' => 'kanak_kumar',
            'unit_name' => 'kanak_kumar',
            'exit_status' => 'no',
            'reporting_date' => self::REPORTING_DATE,
            ...app(ReportingLineService::class)->columnsUnder($boss?->id),
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function newEmployeeForm(int $designation, ?int $reportsTo): array
    {
        return [
            'emp_id' => 'EMP-NEW-1',
            'emp_name' => 'New Joiner',
            'email' => 'new.joiner@example.com',
            'position' => 'Staff',
            'designation' => $designation,
            'category' => '2500000',
            'cost_center' => 'kanak_kumar',
            'unit_name' => 'kanak_kumar',
            'exit_status' => 'no',
            'reports_to' => $reportsTo,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Columns follow the boss
    |--------------------------------------------------------------------------
    */

    public function test_the_columns_follow_the_chosen_boss_and_leave_skipped_levels_empty(): void
    {
        $this->assertSame([
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
            'business_head_id' => $this->businessHead->id,
        ], $this->service->columnsUnder($this->teamLeader->id));

        $this->assertSame([
            'superviser_id' => null,
            'manager_id' => null,
            'cluster_id' => $this->cluster->id,
            'business_head_id' => $this->businessHead->id,
        ], $this->service->columnsUnder($this->cluster->id));
    }

    public function test_the_reporting_line_summary_reads_from_the_top_down(): void
    {
        $this->assertSame(
            "{$this->businessHead->emp_name} (Business Head) › {$this->cluster->emp_name} (Cluster Manager) › {$this->manager->emp_name} (Manager)",
            $this->service->lineSummary($this->manager->id),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Creating
    |--------------------------------------------------------------------------
    */

    public function test_creating_a_team_leader_under_a_cluster_manager_skips_the_manager_column(): void
    {
        Livewire::test(CreateEmployee::class)
            ->fillForm($this->newEmployeeForm(Employee::DESIGNATION_TEAM_LEADER, $this->cluster->id))
            ->call('create')
            ->assertHasNoFormErrors();

        $teamLeader = Employee::query()->where('emp_id', 'EMP-NEW-1')->sole();

        $this->assertNull($teamLeader->manager_id);
        $this->assertSame($this->cluster->id, $teamLeader->cluster_id);
        $this->assertSame($this->businessHead->id, $teamLeader->business_head_id);
        $this->assertSame($this->cluster->id, $teamLeader->directBossId());

        $joining = EmployeeReportingHistory::query()->where('employee_id', $teamLeader->id)->sole();

        $this->assertSame('joining', $joining->change_type);
        $this->assertSame($this->cluster->id, $joining->new_cluster_id);
        $this->assertSame($this->businessHead->id, $joining->new_business_head_id);
    }

    public function test_a_caller_cannot_report_to_another_caller(): void
    {
        Livewire::test(CreateEmployee::class)
            ->fillForm($this->newEmployeeForm(Employee::DESIGNATION_CALLER, $this->caller->id))
            ->call('create')
            ->assertHasFormErrors(['reports_to']);

        $this->assertFalse(Employee::query()->where('emp_id', 'EMP-NEW-1')->exists());
    }

    public function test_every_level_below_business_head_must_report_to_somebody(): void
    {
        Livewire::test(CreateEmployee::class)
            ->fillForm($this->newEmployeeForm(Employee::DESIGNATION_CALLER, null))
            ->call('create')
            ->assertHasFormErrors(['reports_to' => 'required']);
    }

    public function test_nobody_can_report_to_an_employee_who_has_left(): void
    {
        $this->manager->forceFill(['exit_status' => 'yes', 'exit_date' => now()->subDay()->toDateString()])->save();

        $this->assertNotNull($this->service->bossProblem(Employee::DESIGNATION_TEAM_LEADER, $this->manager->id));

        Livewire::test(CreateEmployee::class)
            ->fillForm($this->newEmployeeForm(Employee::DESIGNATION_TEAM_LEADER, $this->manager->id))
            ->call('create')
            ->assertHasFormErrors(['reports_to']);
    }

    public function test_a_business_head_reports_to_nobody(): void
    {
        Livewire::test(CreateEmployee::class)
            ->fillForm($this->newEmployeeForm(Employee::DESIGNATION_BUSINESS_HEAD, null))
            ->call('create')
            ->assertHasNoFormErrors();

        $businessHead = Employee::query()->where('emp_id', 'EMP-NEW-1')->sole();

        $this->assertNull($businessHead->directBossId());
        $this->assertNull($businessHead->cluster_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Editing
    |--------------------------------------------------------------------------
    */

    public function test_moving_a_manager_carries_their_whole_team_to_the_new_cluster_manager(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->manager->getRouteKey()])
            ->assertSchemaStateSet(['reports_to' => $this->cluster->id])
            ->fillForm(['reports_to' => $this->otherCluster->id])
            ->call('save')
            ->assertHasNoFormErrors();

        foreach ([$this->manager, $this->teamLeader, $this->caller] as $employee) {
            $employee->refresh();

            $this->assertSame($this->otherCluster->id, $employee->cluster_id);
            $this->assertSame(self::REPORTING_DATE, Carbon::parse($employee->reporting_date)->toDateString());
            $this->assertTrue(EmployeeReportingHistory::query()
                ->where('employee_id', $employee->id)
                ->where('old_cluster_id', $this->cluster->id)
                ->where('new_cluster_id', $this->otherCluster->id)
                ->exists());
        }

        $this->assertTrue(HierarchyHelper::subordinateIds($this->otherCluster)->contains($this->caller->id));
        $this->assertFalse(HierarchyHelper::subordinateIds($this->cluster)->contains($this->caller->id));
    }

    public function test_promoting_a_team_leader_moves_their_callers_into_the_manager_column(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->teamLeader->getRouteKey()])
            ->fillForm([
                'designation' => Employee::DESIGNATION_MANAGER,
                'reports_to' => $this->cluster->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->caller->refresh();

        $this->assertNull($this->caller->superviser_id);
        $this->assertSame($this->teamLeader->id, $this->caller->manager_id);
        $this->assertSame($this->teamLeader->id, $this->caller->directBossId());
        $this->assertSame([$this->caller->id], HierarchyHelper::callerIds($this->teamLeader->refresh())->all());
    }

    public function test_a_manager_with_a_team_cannot_be_demoted_below_the_people_reporting_to_them(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->manager->getRouteKey()])
            ->fillForm(['designation' => Employee::DESIGNATION_TEAM_LEADER])
            ->call('save')
            ->assertHasFormErrors(['designation']);

        $this->assertSame(Employee::DESIGNATION_MANAGER, $this->manager->refresh()->designation);
    }

    public function test_saving_an_employee_tidies_a_boss_filed_in_the_wrong_column(): void
    {
        $misfiled = $this->employee(Employee::DESIGNATION_CALLER, attributes: [
            'superviser_id' => $this->manager->id,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
        ]);

        Livewire::test(EditEmployee::class, ['record' => $misfiled->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $misfiled->refresh();

        $this->assertNull($misfiled->superviser_id);
        $this->assertSame($this->manager->id, $misfiled->manager_id);
        $this->assertSame($this->businessHead->id, $misfiled->business_head_id);
    }

    public function test_the_transfer_action_can_move_a_caller_straight_to_a_manager(): void
    {
        $otherManager = $this->employee(Employee::DESIGNATION_MANAGER, $this->otherCluster);

        Livewire::test(EditEmployee::class, ['record' => $this->caller->getRouteKey()])
            ->callAction('transferEmployee', data: [
                'reports_to' => $otherManager->id,
                'effective_date' => now()->toDateString(),
                'remarks' => 'Team Leader left.',
            ])
            ->assertHasNoActionErrors();

        $this->caller->refresh();

        $this->assertNull($this->caller->superviser_id);
        $this->assertSame($otherManager->id, $this->caller->manager_id);
        $this->assertSame($this->otherCluster->id, $this->caller->cluster_id);
        $this->assertTrue(EmployeeReportingHistory::query()
            ->where('employee_id', $this->caller->id)
            ->where('change_type', 'transfer')
            ->where('new_manager_id', $otherManager->id)
            ->exists());
    }

    /*
    |--------------------------------------------------------------------------
    | Transfers
    |--------------------------------------------------------------------------
    */

    public function test_flexible_reassignment_moves_a_team_leader_straight_to_a_cluster_manager_with_their_callers(): void
    {
        app(HierarchyReassignmentService::class)->reassign(
            assignments: [['employee_id' => $this->teamLeader->id, 'target_id' => $this->otherCluster->id]],
            performedBy: auth()->id(),
        );

        $this->teamLeader->refresh();
        $this->caller->refresh();

        $this->assertNull($this->teamLeader->manager_id);
        $this->assertSame($this->otherCluster->id, $this->teamLeader->cluster_id);
        $this->assertNull($this->caller->manager_id);
        $this->assertSame($this->otherCluster->id, $this->caller->cluster_id);
        $this->assertSame(2, HierarchyTransferLog::query()->sole()->affected_count);
    }

    public function test_flexible_reassignment_refuses_a_boss_who_is_not_senior(): void
    {
        $otherCaller = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeader);

        $this->expectException(ValidationException::class);

        app(HierarchyReassignmentService::class)->reassign(
            assignments: [['employee_id' => $this->caller->id, 'target_id' => $otherCaller->id]],
            performedBy: auth()->id(),
        );
    }

    public function test_a_full_cluster_transfer_moves_every_direct_report_and_their_teams(): void
    {
        $skipLevelTeamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->cluster);

        app(HierarchyReassignmentService::class)->transfer([
            'source_cluster_manager_id' => $this->cluster->id,
            'target_cluster_manager_id' => $this->otherCluster->id,
            'transfer_type' => 'full_cluster',
        ], auth()->id());

        foreach ([$this->manager, $this->teamLeader, $this->caller, $skipLevelTeamLeader] as $employee) {
            $this->assertSame($this->otherCluster->id, $employee->refresh()->cluster_id);
        }

        $this->assertTrue(HierarchyHelper::children($this->otherCluster)->pluck('id')->contains($skipLevelTeamLeader->id));
        $this->assertSame(4, HierarchyTransferLog::query()->sole()->affected_count);
    }

    /*
    |--------------------------------------------------------------------------
    | Import and lifecycle
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, string>  $row
     */
    private function importRow(array $row): void
    {
        $import = Import::query()->forceCreate([
            'file_name' => 'employees.csv',
            'file_path' => 'employees.csv',
            'importer' => EmployeeImporter::class,
            'total_rows' => 1,
            'user_id' => auth()->id(),
        ]);

        $columns = array_keys($row);

        (new EmployeeImporter($import, array_combine($columns, $columns), []))($row);
    }

    public function test_an_import_row_takes_its_reporting_line_from_reports_to(): void
    {
        $this->importRow([
            'emp_id' => 'EMP-IMPORT-1',
            'emp_name' => 'Imported Team Leader',
            'email' => 'imported@example.com',
            'designation' => (string) Employee::DESIGNATION_TEAM_LEADER,
            'reports_to' => $this->cluster->emp_id,
        ]);

        $imported = Employee::query()->where('emp_id', 'EMP-IMPORT-1')->sole();

        $this->assertNull($imported->manager_id);
        $this->assertSame($this->cluster->id, $imported->cluster_id);
        $this->assertSame($this->businessHead->id, $imported->business_head_id);
    }

    public function test_an_import_row_reporting_to_somebody_not_senior_fails(): void
    {
        $this->expectException(RowImportFailedException::class);

        $this->importRow([
            'emp_id' => 'EMP-IMPORT-2',
            'emp_name' => 'Imported Caller',
            'email' => 'imported.caller@example.com',
            'designation' => (string) Employee::DESIGNATION_CALLER,
            'reports_to' => $this->caller->emp_id,
        ]);
    }

    public function test_the_lifecycle_shows_the_business_head(): void
    {
        Livewire::test(ViewEmployee::class, ['record' => $this->cluster->getRouteKey()])
            ->assertActionVisible('lifecycle');

        // The same relations ViewEmployee's lifecycle modal eager-loads.
        $employee = $this->cluster->load(['superviser', 'manager', 'clusterManager', 'businessHead']);
        $histories = $employee->reportingHistories()
            ->with(['oldSupervisor', 'oldManager', 'oldCluster', 'newSupervisor', 'newManager', 'newCluster', 'oldBusinessHead', 'newBusinessHead', 'updatedBy'])
            ->get();

        $this->view('filament.employees.lifecycle', ['employee' => $employee, 'histories' => $histories])
            ->assertSee('Current Business Head')
            ->assertSee('Business Head')
            ->assertSee($this->businessHead->emp_name);
    }
}
