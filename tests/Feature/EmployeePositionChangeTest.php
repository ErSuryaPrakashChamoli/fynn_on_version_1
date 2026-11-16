<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Models\Customer;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Models\Lead;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\EmployeePositionChangeService;
use App\Services\Performance\EmployeeFigureBreakdownService;
use App\Services\ReportingLineService;
use App\Support\EmployeeLookupPeriod;
use App\Support\HierarchyHelper;
use App\Support\TargetCategoryTimeline;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A promotion, a demotion or a new target category — "Change Position /
 * Target" — needs nothing attached to the employee, like a transfer or an
 * exit: their team, open customers, leads and delegations are handed over
 * first in the same pop-up, then the change happens on the spot, dated so
 * the months before it keep what was held then.
 *
 * Cluster Manager K ── Managers M, M2. M ── Team Leaders T, T2.
 * T ── Caller C. T2 ── Caller C2. Everyone on Silver (₹25L) since 2025.
 */
class EmployeePositionChangeTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-16';

    private const SILVER = '2500000';

    private const GOLD = '3000000';

    private Employee $cluster;

    private Employee $manager;

    private Employee $otherManager;

    private Employee $teamLeader;

    private Employee $otherTeamLeader;

    private Employee $caller;

    private Employee $otherCaller;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 11:00'));

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->cluster = $this->employee(Employee::DESIGNATION_CLUSTER);
        $this->manager = $this->employee(Employee::DESIGNATION_MANAGER, $this->cluster);
        $this->otherManager = $this->employee(Employee::DESIGNATION_MANAGER, $this->cluster);
        $this->teamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager);
        $this->otherTeamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, $this->manager);
        $this->caller = $this->employee(Employee::DESIGNATION_CALLER, $this->teamLeader);
        $this->otherCaller = $this->employee(Employee::DESIGNATION_CALLER, $this->otherTeamLeader);

        $this->admin = User::factory()->create()->assignRole(Role::findOrCreate('Admin'));
        $this->actingAs($this->admin);
    }

    /*
    |--------------------------------------------------------------------------
    | Promotion and demotion
    |--------------------------------------------------------------------------
    */

    public function test_a_caller_with_nothing_attached_is_promoted_on_the_spot(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->otherCaller->id])
            ->mountAction('changePosition')
            ->assertMountedActionModalSee(["Change {$this->otherCaller->emp_name}'s position or target", 'Position', 'Target Category', 'Save change'])
            ->setActionData([
                'designation' => Employee::DESIGNATION_TEAM_LEADER,
                'reports_to' => $this->manager->id,
                'effective_date' => self::TODAY,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->otherCaller->refresh();

        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $this->otherCaller->designation);
        $this->assertSame($this->manager->id, HierarchyHelper::directBossId($this->otherCaller));

        $row = EmployeeReportingHistory::query()
            ->where('employee_id', $this->otherCaller->id)
            ->where('change_type', ReportingLineService::CHANGE_DESIGNATION)
            ->sole();

        $this->assertSame(Employee::DESIGNATION_CALLER, $row->old_designation);
        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $row->new_designation);
        $this->assertSame(self::TODAY, $row->effective_date->toDateString());
    }

    public function test_a_team_leader_hands_over_their_team_and_work_then_is_promoted(): void
    {
        $customer = $this->customer($this->teamLeader);
        $lead = $this->lead($this->teamLeader);
        $handoverDate = Carbon::parse(self::TODAY)->subDay()->toDateString();

        $page = Livewire::test(EditEmployee::class, ['record' => $this->teamLeader->id])
            ->mountAction('changePosition')
            ->assertMountedActionModalSee([
                "{$this->teamLeader->emp_name} still has work attached",
                '1 direct report, 1 open customer and 1 open lead',
                $this->caller->emp_name,
                'Hand over & continue',
            ])
            ->assertMountedActionModalDontSee('Save change')
            ->setActionData([
                'direct_reports_target' => $this->otherTeamLeader->id,
                'handover_date' => $handoverDate,
                'customers_target' => $this->otherTeamLeader->id,
                'leads_target' => $this->otherTeamLeader->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
        $this->assertSame($this->otherTeamLeader->id, (int) $customer->fresh()->assign_to);
        $this->assertSame($this->otherTeamLeader->id, (int) $lead->fresh()->employee_id);
        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $this->teamLeader->fresh()->designation, 'Handing over must not promote them by itself.');

        // The same pop-up is back on the change itself, dated like the hand-over.
        $this->assertSame('changePosition', $page->instance()->mountedActions[0]['name'] ?? null);

        $page->assertMountedActionModalSee(["Change {$this->teamLeader->emp_name}'s position or target", 'Save change'])
            ->assertActionDataSet(['effective_date' => $handoverDate])
            ->setActionData([
                'designation' => Employee::DESIGNATION_MANAGER,
                'reports_to' => $this->cluster->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->teamLeader->refresh();

        $this->assertSame(Employee::DESIGNATION_MANAGER, $this->teamLeader->designation);
        $this->assertSame($this->cluster->id, HierarchyHelper::directBossId($this->teamLeader));
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()), 'The team stays where it was handed over.');
        $this->assertSame($handoverDate, EmployeeReportingHistory::query()
            ->where('employee_id', $this->teamLeader->id)
            ->where('change_type', ReportingLineService::CHANGE_DESIGNATION)
            ->sole()
            ->effective_date
            ->toDateString());
    }

    public function test_a_manager_with_nothing_attached_is_demoted_on_the_spot(): void
    {
        $this->service()->change(
            $this->otherManager,
            Employee::DESIGNATION_TEAM_LEADER,
            $this->manager->id,
            self::SILVER,
            Carbon::parse(self::TODAY),
            null,
            $this->admin,
        );

        $this->otherManager->refresh();

        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $this->otherManager->designation);
        $this->assertSame($this->manager->id, HierarchyHelper::directBossId($this->otherManager));
        $this->assertSame(
            'Demotion: Manager → Team Leader.',
            EmployeeReportingHistory::query()->where('employee_id', $this->otherManager->id)->where('change_type', ReportingLineService::CHANGE_DESIGNATION)->sole()->remarks,
        );
    }

    public function test_the_service_refuses_a_promotion_while_anything_is_attached(): void
    {
        try {
            $this->service()->change($this->teamLeader, Employee::DESIGNATION_MANAGER, $this->cluster->id, self::SILVER, Carbon::parse(self::TODAY), null, $this->admin);
            $this->fail('A Team Leader with a caller was promoted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('still has 1 direct report', $exception->errors()['dependencies'][0]);
        }

        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $this->teamLeader->fresh()->designation);
        $this->assertFalse(EmployeeReportingHistory::query()->where('change_type', ReportingLineService::CHANGE_DESIGNATION)->exists());
    }

    /*
    |--------------------------------------------------------------------------
    | Target category
    |--------------------------------------------------------------------------
    */

    public function test_a_new_target_category_keeps_past_months_and_splits_the_month_it_starts_in(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->otherCaller->id])
            ->mountAction('changePosition')
            ->setActionData(['category' => self::GOLD, 'effective_date' => self::TODAY])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->otherCaller->refresh();
        $calculator = app(AchievementCalculatorService::class);

        $this->assertSame(self::GOLD, $this->otherCaller->category);
        $this->assertSame(Employee::DESIGNATION_CALLER, $this->otherCaller->designation);

        // October is untouched; November is 15 days Silver + 15 days Gold.
        $this->assertSame(2500000.0, $calculator->getTarget($this->otherCaller, Carbon::create(2026, 10, 1)));
        $this->assertSame(2750000.0, $calculator->getTarget($this->otherCaller, Carbon::create(2026, 11, 1)));
        $this->assertSame(3000000.0, $calculator->getTarget($this->otherCaller, Carbon::create(2026, 12, 1)));

        // The team targets read the same split.
        $this->assertSame(2500000.0, $calculator->getHierarchyCallerTarget($this->otherCaller, Carbon::create(2026, 10, 1)));
        $this->assertSame(2750000.0, $calculator->getHierarchyCallerTarget($this->otherCaller, Carbon::create(2026, 11, 1)));

        $row = EmployeeReportingHistory::query()
            ->where('employee_id', $this->otherCaller->id)
            ->where('change_type', ReportingLineService::CHANGE_TARGET_CATEGORY)
            ->sole();

        $this->assertSame(self::SILVER, $row->old_category);
        $this->assertSame(self::GOLD, $row->new_category);
        $this->assertSame(self::TODAY, $row->effective_date->toDateString());

        // A target change moves nobody and leaves the reporting line open.
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->otherCaller));
        $this->assertNull(EmployeeReportingHistory::query()->where('employee_id', $this->otherCaller->id)->where('change_type', 'joining')->sole()->effective_to);
    }

    public function test_the_employee_lookup_shows_the_category_held_in_the_month_viewed(): void
    {
        $this->service()->change($this->otherCaller, Employee::DESIGNATION_CALLER, null, self::GOLD, Carbon::parse(self::TODAY), null, $this->admin);

        $breakdowns = app(EmployeeFigureBreakdownService::class);
        $october = $breakdowns->target($this->otherCaller->refresh(), EmployeeLookupPeriod::forMonth(Carbon::create(2026, 10, 1)));
        $november = $breakdowns->target($this->otherCaller, EmployeeLookupPeriod::forMonth(Carbon::create(2026, 11, 1)));

        $this->assertSame('Silver', $october['category']);
        $this->assertSame([], $october['category_notes']);
        $this->assertSame('Gold', $november['category']);
        $this->assertSame(2750000.0, $november['total']);
        $this->assertStringContainsString('split by days', $november['category_notes'][0]);
    }

    public function test_a_target_change_also_needs_everything_handed_over_first(): void
    {
        $lead = $this->lead($this->caller);

        $page = Livewire::test(EditEmployee::class, ['record' => $this->caller->id])
            ->mountAction('changePosition')
            ->assertMountedActionModalSee(["{$this->caller->emp_name} still has work attached", '1 open lead'])
            ->setActionData(['leads_target' => $this->otherCaller->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($this->otherCaller->id, (int) $lead->fresh()->employee_id);
        $this->assertSame(self::SILVER, $this->caller->fresh()->category);
        $this->assertSame('changePosition', $page->instance()->mountedActions[0]['name'] ?? null);

        $page->setActionData(['category' => self::GOLD])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(self::GOLD, $this->caller->fresh()->category);
    }

    public function test_the_service_refuses_a_target_change_while_anything_is_attached(): void
    {
        $this->lead($this->caller);

        $this->expectException(ValidationException::class);

        try {
            $this->service()->change($this->caller, Employee::DESIGNATION_CALLER, null, self::GOLD, Carbon::parse(self::TODAY), null, $this->admin);
        } finally {
            $this->assertSame(self::SILVER, $this->caller->fresh()->category);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    */

    public function test_nothing_changing_is_refused(): void
    {
        try {
            $this->service()->change($this->otherCaller, Employee::DESIGNATION_CALLER, null, self::SILVER, Carbon::parse(self::TODAY), null, $this->admin);
            $this->fail('A change that changed nothing was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('nothing would change', $exception->errors()['designation'][0]);
        }
    }

    public function test_the_effective_date_cannot_be_in_the_future_or_before_the_last_change(): void
    {
        try {
            $this->service()->change($this->otherCaller, Employee::DESIGNATION_CALLER, null, self::GOLD, Carbon::parse(self::TODAY)->addDay(), null, $this->admin);
            $this->fail('A change dated tomorrow was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_date', $exception->errors());
        }

        $this->service()->change($this->otherCaller, Employee::DESIGNATION_CALLER, null, self::GOLD, Carbon::parse('2026-11-10'), null, $this->admin);

        try {
            $this->service()->change($this->otherCaller->refresh(), Employee::DESIGNATION_CALLER, null, self::SILVER, Carbon::parse('2026-11-01'), null, $this->admin);
            $this->fail('A change dated before the last target change was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('10 Nov 2026', $exception->errors()['effective_date'][0]);
        }

        $this->assertSame(self::GOLD, $this->otherCaller->fresh()->category);
    }

    public function test_the_edit_form_leaves_position_and_target_category_to_the_pop_up(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->otherCaller->id])
            ->assertFormFieldIsDisabled('designation')
            ->assertFormFieldIsDisabled('category')
            ->fillForm(['category' => self::GOLD])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->otherCaller->refresh();

        $this->assertSame(Employee::DESIGNATION_CALLER, $this->otherCaller->designation);
        $this->assertSame(self::SILVER, $this->otherCaller->category);
    }

    public function test_the_lifecycle_shows_the_target_change(): void
    {
        $this->service()->change($this->otherCaller, Employee::DESIGNATION_CALLER, null, self::GOLD, Carbon::parse(self::TODAY), null, $this->admin);

        $histories = $this->otherCaller->reportingHistories()
            ->with(['oldSupervisor', 'oldManager', 'oldCluster', 'newSupervisor', 'newManager', 'newCluster', 'oldBusinessHead', 'newBusinessHead', 'updatedBy'])
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get();

        $this->view('filament.employees.lifecycle', ['employee' => $this->otherCaller, 'histories' => $histories])
            ->assertSeeInOrder(['Target Change', 'Target category:', 'Silver', '→', 'Gold']);
    }

    public function test_a_category_written_without_a_history_row_applies_from_the_last_recorded_change(): void
    {
        $this->service()->change($this->otherCaller, Employee::DESIGNATION_CALLER, null, self::GOLD, Carbon::parse('2026-11-10'), null, $this->admin);

        // e.g. a correction saved on an employee who has since left.
        $this->otherCaller->refresh()->forceFill(['category' => '3500000'])->save();

        $this->assertSame(self::SILVER, TargetCategoryTimeline::categoryOn($this->otherCaller, Carbon::parse('2026-11-09')));
        $this->assertSame('3500000', TargetCategoryTimeline::categoryOn($this->otherCaller, Carbon::parse('2026-11-10')));
    }

    private function service(): EmployeePositionChangeService
    {
        return app(EmployeePositionChangeService::class);
    }

    private function employee(int $designation, ?Employee $boss = null): Employee
    {
        $position = Designation::query()->firstOrCreate(['name' => 'Executive']);

        return Employee::factory()->create([
            'designation' => $designation,
            'position' => $position->name,
            'category' => self::SILVER,
            'doj' => '2025-01-01',
            'reporting_date' => '2025-01-01',
            'exit_status' => 'no',
            'exit_date' => null,
            ...app(ReportingLineService::class)->columnsUnder($boss?->id),
        ]);
    }

    private function customer(Employee $owner): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $owner->id,
            'employee_id' => $this->caller->id,
            'journey_status' => 'sfl',
            'disbursal_finalized' => false,
            'disbursal_status' => null,
        ]);
    }

    private function lead(Employee $owner): Lead
    {
        return Lead::query()->create([
            'employee_id' => $owner->id,
            'customer_name' => fake()->name(),
            'mobile_no' => '98765'.random_int(10000, 99999),
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'Followed up.',
        ]);
    }
}
