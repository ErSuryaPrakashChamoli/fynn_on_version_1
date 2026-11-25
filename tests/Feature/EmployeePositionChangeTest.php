<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Models\Customer;
use App\Models\CustomerReassignment;
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
 * Target" — hands nothing over: while they stay on the rolls their open
 * customers, leads and delegations stay theirs. When the position changes
 * their team stays with them while the new position is still senior to all
 * of it, or gets the new boss chosen in the same form (required when it
 * would not be). The change happens on the spot, dated so the months before it keep
 * what was held then.
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

    public function test_a_team_leader_is_promoted_keeping_their_work_while_their_team_gets_a_new_boss(): void
    {
        $customer = $this->customer($this->teamLeader);
        $lead = $this->lead($this->teamLeader);
        $effectiveDate = Carbon::parse(self::TODAY)->subDay()->toDateString();

        Livewire::test(EditEmployee::class, ['record' => $this->teamLeader->id])
            ->mountAction('changePosition')
            ->assertMountedActionModalSee([
                "Change {$this->teamLeader->emp_name}'s position or target",
                'Their customers and leads stay with them.',
                'Save change',
            ])
            ->assertMountedActionModalDontSee(['still has work attached', 'Hand over & continue', 'Their team reports to'])
            ->setActionData([
                'designation' => Employee::DESIGNATION_MANAGER,
                'reports_to' => $this->cluster->id,
                'effective_date' => $effectiveDate,
            ])
            ->assertMountedActionModalSee(['Their team reports to', 'Leave empty to keep their 1 direct report with them'])
            ->setActionData(['team_boss' => $this->otherTeamLeader->id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->teamLeader->refresh();

        $this->assertSame(Employee::DESIGNATION_MANAGER, $this->teamLeader->designation);
        $this->assertSame($this->cluster->id, HierarchyHelper::directBossId($this->teamLeader));
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()), 'The team moves to the boss chosen for it.');
        $this->assertSame($effectiveDate, EmployeeReportingHistory::query()
            ->where('employee_id', $this->caller->id)
            ->where('change_type', ReportingLineService::CHANGE_TRANSFER)
            ->sole()
            ->effective_date
            ->toDateString(), 'The team moves from the same effective date.');
        $this->assertSame($effectiveDate, EmployeeReportingHistory::query()
            ->where('employee_id', $this->teamLeader->id)
            ->where('change_type', ReportingLineService::CHANGE_DESIGNATION)
            ->sole()
            ->effective_date
            ->toDateString());

        // Nothing they hold is handed over.
        $this->assertSame($this->teamLeader->id, (int) $customer->fresh()->assign_to);
        $this->assertSame($this->teamLeader->id, (int) $lead->fresh()->employee_id);
        $this->assertSame(0, CustomerReassignment::query()->count());
    }

    public function test_a_promoted_team_leader_keeps_their_team_when_no_new_boss_is_chosen(): void
    {
        $callerLead = $this->lead($this->caller);
        $effectiveDate = Carbon::parse(self::TODAY)->subDay()->toDateString();

        Livewire::test(EditEmployee::class, ['record' => $this->teamLeader->id])
            ->mountAction('changePosition')
            ->setActionData([
                'designation' => Employee::DESIGNATION_MANAGER,
                'reports_to' => $this->cluster->id,
                'effective_date' => $effectiveDate,
            ])
            ->assertMountedActionModalSee(["Stays with {$this->teamLeader->emp_name}"])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $caller = $this->caller->fresh();

        $this->assertSame(Employee::DESIGNATION_MANAGER, $this->teamLeader->fresh()->designation);
        $this->assertSame($this->teamLeader->id, HierarchyHelper::directBossId($caller), 'The team stays with them.');
        $this->assertSame($this->teamLeader->id, (int) $caller->manager_id, 'The caller now sits straight under their Manager.');
        $this->assertNull($caller->superviser_id);
        $this->assertSame($this->caller->id, (int) $callerLead->fresh()->employee_id);
        $this->assertSame($effectiveDate, EmployeeReportingHistory::query()
            ->where('employee_id', $this->caller->id)
            ->whereNull('effective_to')
            ->where('change_type', '!=', ReportingLineService::CHANGE_DESIGNATION)
            ->latest('id')
            ->firstOrFail()
            ->effective_date
            ->toDateString(), 'Their new line is dated like the promotion.');
        $this->assertSame(1, EmployeeReportingHistory::query()
            ->where('employee_id', $this->caller->id)
            ->whereDate('effective_date', $effectiveDate)
            ->count(), 'Staying with them writes one row, not a detour through the old Manager.');
    }

    public function test_a_demotion_needs_a_new_boss_for_a_team_it_would_no_longer_be_senior_to(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->teamLeader->id])
            ->mountAction('changePosition')
            ->setActionData([
                'designation' => Employee::DESIGNATION_CALLER,
                'reports_to' => $this->otherTeamLeader->id,
                'effective_date' => self::TODAY,
            ])
            ->assertMountedActionModalSee(['Choose a new boss', 'would no longer have a more senior boss'])
            ->callMountedAction()
            ->assertHasActionErrors(['team_boss' => 'required']);

        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $this->teamLeader->fresh()->designation);
        $this->assertSame($this->teamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
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

    public function test_the_service_refuses_a_demotion_that_would_strand_their_team(): void
    {
        foreach ([null, $this->teamLeader->id] as $teamBossId) {
            try {
                $this->service()->change($this->teamLeader, Employee::DESIGNATION_CALLER, $this->otherTeamLeader->id, self::SILVER, Carbon::parse(self::TODAY), null, $this->admin, teamBossId: $teamBossId);
                $this->fail('A Team Leader was demoted to Caller with a caller still reporting to them.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('still has 1 direct report who would no longer have a more senior boss', $exception->errors()['team_boss'][0]);
            }
        }

        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $this->teamLeader->fresh()->designation);
        $this->assertSame($this->teamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
        $this->assertFalse(EmployeeReportingHistory::query()->where('change_type', ReportingLineService::CHANGE_DESIGNATION)->exists());
    }

    public function test_a_demoted_manager_keeps_their_open_work_and_delegations(): void
    {
        $standIn = $this->employee(Employee::DESIGNATION_MANAGER, $this->cluster);
        $customer = $this->customer($this->manager);
        $lead = $this->lead($this->manager);

        $this->service()->change(
            $this->manager,
            Employee::DESIGNATION_TEAM_LEADER,
            $this->otherManager->id,
            self::SILVER,
            Carbon::parse(self::TODAY),
            null,
            $this->admin,
            teamBossId: $standIn->id,
        );

        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $this->manager->fresh()->designation);
        $this->assertSame($standIn->id, HierarchyHelper::directBossId($this->teamLeader->fresh()));
        $this->assertSame($standIn->id, HierarchyHelper::directBossId($this->otherTeamLeader->fresh()));
        $this->assertSame($this->teamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()), 'The callers stay with their Team Leader.');
        $this->assertSame($this->manager->id, (int) $customer->fresh()->assign_to);
        $this->assertSame($this->manager->id, (int) $lead->fresh()->employee_id);
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

    public function test_a_caller_with_open_work_changes_target_straight_away_and_keeps_it(): void
    {
        $customer = $this->customer($this->caller);
        $lead = $this->lead($this->caller);

        Livewire::test(EditEmployee::class, ['record' => $this->caller->id])
            ->mountAction('changePosition')
            ->assertMountedActionModalSee(["Change {$this->caller->emp_name}'s position or target", 'Save change'])
            ->assertMountedActionModalDontSee(['still has work attached', 'Hand over & continue'])
            ->setActionData(['category' => self::GOLD, 'effective_date' => self::TODAY])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(self::GOLD, $this->caller->fresh()->category);
        $this->assertSame($this->caller->id, (int) $customer->fresh()->assign_to);
        $this->assertSame($this->caller->id, (int) $lead->fresh()->employee_id);
    }

    /**
     * Only a caller carries a Target Category (user decision 2026-11-24): a
     * leader's target is built from their team, so a category on its own
     * changes nothing for them and their team stays where it is.
     */
    public function test_a_team_leader_carries_no_category_so_a_category_only_change_is_refused(): void
    {
        $before = $this->teamLeader->category;

        try {
            $this->service()->change($this->teamLeader, Employee::DESIGNATION_TEAM_LEADER, null, self::GOLD, Carbon::parse(self::TODAY), null, $this->admin);
            $this->fail('A category-only change for a Team Leader was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('nothing would change', $exception->errors()['designation'][0]);
        }

        $this->assertSame($before, $this->teamLeader->fresh()->category);
        $this->assertSame($this->teamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
    }

    public function test_a_caller_may_only_be_given_a_category_carrying_a_monthly_target(): void
    {
        try {
            $this->service()->change($this->otherCaller, Employee::DESIGNATION_CALLER, null, 'team_leader', Carbon::parse(self::TODAY), null, $this->admin);
            $this->fail('A caller was given a category with no monthly target.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category', $exception->errors());
        }

        $this->assertSame(self::SILVER, $this->otherCaller->fresh()->category);
    }

    public function test_a_promotion_keeps_the_category_on_record_untouched(): void
    {
        $this->service()->change($this->otherCaller, Employee::DESIGNATION_TEAM_LEADER, $this->manager->id, self::GOLD, Carbon::parse(self::TODAY), null, $this->admin);

        // A leader is never given a category; the caller's one stays on
        // record for a later demotion, with no category change dated.
        $this->assertSame(self::SILVER, $this->otherCaller->fresh()->category);
        $this->assertFalse(EmployeeReportingHistory::query()
            ->where('employee_id', $this->otherCaller->id)
            ->where('change_type', ReportingLineService::CHANGE_TARGET_CATEGORY)
            ->exists());
    }

    public function test_a_caller_with_open_work_is_promoted_and_takes_it_along(): void
    {
        $lead = $this->lead($this->otherCaller);

        $this->service()->change($this->otherCaller, Employee::DESIGNATION_TEAM_LEADER, $this->manager->id, self::SILVER, Carbon::parse(self::TODAY), null, $this->admin);

        $this->assertSame(Employee::DESIGNATION_TEAM_LEADER, $this->otherCaller->fresh()->designation);
        $this->assertSame($this->otherCaller->id, (int) $lead->fresh()->employee_id);
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
