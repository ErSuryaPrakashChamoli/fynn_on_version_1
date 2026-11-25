<?php

namespace Tests\Feature;

use App\Enums\JourneyModule;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerAssignmentBatch;
use App\Models\CustomerJourneyDelegation;
use App\Models\CustomerReassignment;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Models\Lead;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\EmployeeDependencyService;
use App\Services\EmployeeTransferService;
use App\Support\HierarchyHelper;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An employee moved to a new boss — "Transfer Employee", the Flexible
 * Reassignment, or the Edit form's Reports To — takes all their own work
 * along, whoever created or gave it to them: nothing is handed over while
 * they stay on the rolls. Their team never moves with them: anyone reporting
 * to them is given a new boss first and keeps their own work. Past months
 * stay with the old team, and delegations run on.
 */
class EmployeeTransferTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $otherManager;

    private Employee $movingTeamLeader;

    private Employee $otherTeamLeader;

    private Employee $caller;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->manager = $this->employee(Employee::DESIGNATION_MANAGER);
        $this->otherManager = $this->employee(Employee::DESIGNATION_MANAGER);
        $this->movingTeamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, ['manager_id' => $this->manager->id]);
        $this->otherTeamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, ['manager_id' => $this->manager->id]);
        $this->caller = $this->employee(Employee::DESIGNATION_CALLER, [
            'superviser_id' => $this->movingTeamLeader->id,
            'manager_id' => $this->manager->id,
        ]);

        $this->admin = User::factory()->create()->assignRole(Role::findOrCreate('Admin'));
        $this->actingAs($this->admin);
    }

    public function test_an_employee_with_nothing_attached_is_transferred_on_the_spot(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->otherTeamLeader->id])
            ->mountAction('transferEmployee')
            ->assertMountedActionModalSee(["Transfer {$this->otherTeamLeader->emp_name}", 'New Boss'])
            ->setActionData(['reports_to' => $this->otherManager->id, 'effective_date' => today()->toDateString()])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Employee transferred successfully.');

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->otherTeamLeader->fresh()));
        $this->assertTrue(EmployeeReportingHistory::query()
            ->where('employee_id', $this->otherTeamLeader->id)
            ->where('change_type', 'transfer')
            ->where('new_manager_id', $this->otherManager->id)
            ->exists());
    }

    public function test_a_caller_moves_straight_away_and_takes_all_their_open_work_along(): void
    {
        $customer = $this->customer($this->caller);
        $lead = $this->lead($this->caller);
        $leadFromSomebodyElse = $this->leadHandedTo($this->caller, $this->movingTeamLeader);
        $assignedLead = $this->assignedLead($this->caller, assignedBy: $this->movingTeamLeader);
        $delegation = $this->delegation($this->caller, $this->movingTeamLeader);

        Livewire::test(EditEmployee::class, ['record' => $this->caller->id])
            ->mountAction('transferEmployee')
            ->assertMountedActionModalSee([
                "Transfer {$this->caller->emp_name}",
                'New Boss',
                'Their open customers, leads and delegations go with them.',
            ])
            ->assertMountedActionModalDontSee(['still has', 'Hand over & continue'])
            ->setActionData(['reports_to' => $this->otherTeamLeader->id, 'effective_date' => today()->toDateString()])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Employee transferred successfully.');

        $customer->refresh();

        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
        $this->assertSame($this->caller->id, (int) $customer->assign_to);
        $this->assertSame($this->caller->id, (int) $customer->employee_id);
        $this->assertSame($this->caller->id, (int) $lead->fresh()->employee_id);
        $this->assertSame($this->caller->id, (int) $leadFromSomebodyElse->fresh()->employee_id, 'A caller takes even work somebody else gave them.');
        $this->assertSame($this->caller->id, (int) $assignedLead->fresh()->employee_id);
        $this->assertSame(CustomerJourneyDelegation::STATUS_ACTIVE, $delegation->fresh()->status);
        $this->assertSame(0, CustomerReassignment::query()->count(), 'Nothing is handed over when a caller moves.');
    }

    public function test_a_moved_caller_keeps_past_months_with_the_old_team_and_brings_later_work_to_the_new_one(): void
    {
        $this->travelTo(Carbon::create(2026, 11, 14, 11));

        $october = $this->disbursedCustomer($this->caller, '2026-10-10');
        $november = $this->disbursedCustomer($this->caller, '2026-11-05');

        app(EmployeeTransferService::class)->transfer($this->caller, $this->otherTeamLeader->id, Carbon::parse('2026-11-01'), null, $this->admin);

        $calculator = new AchievementCalculatorService;
        $disbursedFor = fn (Employee $teamLeader, int $month): array => $calculator
            ->disbursedCustomersFor($teamLeader, Carbon::create(2026, $month, 1), Carbon::create(2026, $month, 1)->endOfMonth())
            ->pluck('customers.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertSame([$october->id], $disbursedFor($this->movingTeamLeader, 10), 'October stays with the old Team Leader.');
        $this->assertSame([], $disbursedFor($this->otherTeamLeader, 10));
        $this->assertSame([$november->id], $disbursedFor($this->otherTeamLeader, 11), 'From the effective date the caller counts for the new Team Leader.');
        $this->assertSame([], $disbursedFor($this->movingTeamLeader, 11));
        $this->assertSame($this->caller->id, (int) $october->fresh()->employee_id, 'Past cases stay the caller\'s own.');
    }

    public function test_a_team_leader_takes_all_their_work_along_and_hands_over_only_their_team(): void
    {
        $ownCustomer = $this->customer($this->movingTeamLeader);
        $ownLead = $this->lead($this->movingTeamLeader);
        $delegation = $this->delegation($this->movingTeamLeader, $this->otherTeamLeader);
        $customerFromCaller = $this->customerHandedTo($this->movingTeamLeader, $this->caller);
        $leadFromCaller = $this->leadHandedTo($this->movingTeamLeader, $this->caller);
        $leadFromTheAdmin = $this->assignedLead($this->movingTeamLeader, assignedBy: null);
        $callerCustomer = $this->customer($this->caller);
        $callerLead = $this->lead($this->caller);
        $handoverDate = today()->subDay()->toDateString();

        Livewire::test(EditEmployee::class, ['record' => $this->movingTeamLeader->id])
            ->mountAction('transferEmployee')
            ->assertMountedActionModalSee([
                "Transfer {$this->movingTeamLeader->emp_name}",
                '1 person reports straight to',
                'Whole team moves with them',
                'Move alone',
                'New Boss',
            ])
            ->assertMountedActionModalDontSee([
                $ownCustomer->customer_name,
                $customerFromCaller->customer_name,
                $leadFromCaller->customer_name,
                'Open customers (',
                'Open leads (',
                'open assigned lead',
                'live delegation',
                'Hand over & continue',
            ])
            ->setActionData([
                'reports_to' => $this->otherManager->id,
                'team' => EmployeeTransferService::TEAM_NONE,
                'staying_team_boss' => $this->otherTeamLeader->id,
                'effective_date' => $handoverDate,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Employee transferred successfully.');

        $this->assertSame($handoverDate, EmployeeReportingHistory::query()
            ->where('employee_id', $this->caller->id)
            ->where('new_superviser_id', $this->otherTeamLeader->id)
            ->value('effective_date')?->toDateString(), 'The team is handed over from the same effective date.');

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->movingTeamLeader->fresh()));
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()), 'The team stays where it was handed over.');
        $this->assertSame($this->manager->id, (int) $this->caller->fresh()->manager_id);

        // Everything they held went with them, whoever created or gave it.
        $this->assertSame($this->movingTeamLeader->id, (int) $ownCustomer->fresh()->assign_to);
        $this->assertSame($this->movingTeamLeader->id, (int) $ownLead->fresh()->employee_id);
        $this->assertSame($this->movingTeamLeader->id, (int) $customerFromCaller->fresh()->assign_to);
        $this->assertSame($this->movingTeamLeader->id, (int) $leadFromCaller->fresh()->employee_id);
        $this->assertSame($this->movingTeamLeader->id, (int) $leadFromTheAdmin->fresh()->employee_id);
        $this->assertSame(CustomerJourneyDelegation::STATUS_ACTIVE, $delegation->fresh()->status);
        $this->assertSame(0, CustomerReassignment::query()->count(), 'Nothing is handed over while they stay on the rolls.');

        // Their old team keeps its own work.
        $this->assertSame($this->caller->id, (int) $callerCustomer->fresh()->assign_to);
        $this->assertSame($this->caller->id, (int) $callerLead->fresh()->employee_id);
    }

    public function test_work_somebody_else_gave_a_team_leader_never_holds_up_a_move(): void
    {
        $dependencies = app(EmployeeDependencyService::class);
        $selfAssigned = $this->assignedLead($this->otherTeamLeader, assignedBy: $this->otherTeamLeader);
        $fromSomebodyElse = $this->assignedLead($this->otherTeamLeader, assignedBy: $this->manager);
        $fromTheAdmin = $this->assignedLead($this->otherTeamLeader, assignedBy: null);
        $leadFromCaller = $this->leadHandedTo($this->otherTeamLeader, $this->caller);
        $customerFromCaller = $this->customerHandedTo($this->otherTeamLeader, $this->caller);

        $this->assertSame([], $dependencies->moveDependencyCounts($this->otherTeamLeader));

        app(EmployeeTransferService::class)->transfer($this->otherTeamLeader, $this->otherManager->id, today(), null, $this->admin);

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->otherTeamLeader->fresh()));

        foreach ([$selfAssigned, $fromSomebodyElse, $fromTheAdmin, $leadFromCaller] as $held) {
            $this->assertSame($this->otherTeamLeader->id, (int) $held->fresh()->employee_id);
        }

        $this->assertSame($this->otherTeamLeader->id, (int) $customerFromCaller->fresh()->assign_to);
    }

    public function test_a_manager_hands_over_only_their_team_leaders_who_keep_their_callers(): void
    {
        $otherCluster = $this->employee(Employee::DESIGNATION_CLUSTER);
        $ownCustomer = $this->customer($this->manager);
        $standIn = $this->employee(Employee::DESIGNATION_MANAGER);

        $this->assertSame(
            [EmployeeDependencyService::DIRECT_REPORTS => 2],
            app(EmployeeDependencyService::class)->moveDependencyCounts($this->manager),
            'Only the two Team Leaders hold the move up, not the open customer.',
        );

        app(EmployeeDependencyService::class)->moveDirectReports($this->manager, $standIn->id, today(), $this->admin);
        app(EmployeeTransferService::class)->transfer($this->manager, $otherCluster->id, today(), null, $this->admin);

        $this->assertSame($otherCluster->id, HierarchyHelper::directBossId($this->manager->fresh()));
        $this->assertSame($standIn->id, HierarchyHelper::directBossId($this->movingTeamLeader->fresh()));
        $this->assertSame($this->movingTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()), 'The callers stay with their Team Leader.');
        $this->assertSame($this->manager->id, (int) $ownCustomer->fresh()->assign_to);
    }

    public function test_the_transfer_service_refuses_while_anybody_still_reports_to_them(): void
    {
        try {
            app(EmployeeTransferService::class)->transfer($this->movingTeamLeader, $this->otherManager->id, today(), null, $this->admin);
            $this->fail('A Team Leader with a caller was moved.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('still has 1 direct report', $exception->errors()['reports_to'][0]);
        }

        $this->assertSame($this->manager->id, HierarchyHelper::directBossId($this->movingTeamLeader->fresh()));
    }

    public function test_the_edit_form_refuses_a_straight_move_while_somebody_still_reports_to_them(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->movingTeamLeader->id])
            ->fillForm(['reports_to' => $this->otherManager->id])
            ->call('save')
            ->assertHasFormErrors(['reports_to']);

        $this->assertSame($this->manager->id, HierarchyHelper::directBossId($this->movingTeamLeader->fresh()));
    }

    public function test_the_edit_form_moves_a_caller_with_open_work_straight_away(): void
    {
        $lead = $this->lead($this->caller);

        Livewire::test(EditEmployee::class, ['record' => $this->caller->id])
            ->fillForm(['reports_to' => $this->otherTeamLeader->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
        $this->assertSame($this->caller->id, (int) $lead->fresh()->employee_id);
    }

    public function test_a_flexible_reassignment_hands_over_the_team_then_comes_back_to_be_confirmed(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $ownLead = $this->lead($this->movingTeamLeader);

        $page = Livewire::test(ListEmployees::class)
            ->mountAction('transferCluster')
            ->setActionData([
                'transfer_type' => 'flexible_reassignment',
                'assignments' => [['employee_id' => $this->movingTeamLeader->id, 'target_id' => $this->otherManager->id, 'team' => EmployeeTransferService::TEAM_NONE]],
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        // Not moved yet: the pop-up switched to handing over their caller.
        $this->assertSame($this->manager->id, HierarchyHelper::directBossId($this->movingTeamLeader->fresh()));
        $this->assertSame('clearTransferDependencies', $page->instance()->mountedActions[0]['name'] ?? null);

        $page->assertMountedActionModalSee(["{$this->movingTeamLeader->emp_name} has a team that stays behind", $this->caller->emp_name])
            ->assertMountedActionModalDontSee('open lead')
            ->setActionData(['direct_reports_target' => $this->otherTeamLeader->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));

        // Back on the transfer with the same row, ready to confirm.
        $this->assertSame('transferCluster', $page->instance()->mountedActions[0]['name'] ?? null);

        $page->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->movingTeamLeader->fresh()));
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
        $this->assertSame($this->movingTeamLeader->id, (int) $ownLead->fresh()->employee_id);

        $undoRepeaterFake();
    }

    public function test_a_team_leader_moved_together_with_their_callers_needs_no_hand_over(): void
    {
        $log = app(EmployeeTransferService::class)->reassign([
            ['employee_id' => $this->movingTeamLeader->id, 'target_id' => $this->otherManager->id],
            ['employee_id' => $this->caller->id, 'target_id' => $this->otherTeamLeader->id],
        ], $this->admin->id);

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->movingTeamLeader->fresh()));
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
        $this->assertSame(
            0,
            EmployeeReportingHistory::query()->where('employee_id', $this->caller->id)->where('new_manager_id', $this->otherManager->id)->count(),
            'The caller left before their Team Leader moved, so they never passed through the new Manager.',
        );
        $this->assertNotNull($log->id);
    }

    public function test_a_flexible_reassignment_moves_a_caller_with_open_work_straight_away(): void
    {
        $customer = $this->customer($this->caller);
        $lead = $this->lead($this->caller);

        app(EmployeeTransferService::class)->reassign([
            ['employee_id' => $this->caller->id, 'target_id' => $this->otherTeamLeader->id],
        ], $this->admin->id);

        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
        $this->assertSame($this->caller->id, (int) $customer->fresh()->assign_to);
        $this->assertSame($this->caller->id, (int) $lead->fresh()->employee_id);
    }

    public function test_a_flexible_reassignment_is_refused_while_anybody_in_it_still_has_people_reporting_to_them(): void
    {
        $this->expectException(ValidationException::class);

        app(EmployeeTransferService::class)->reassign([
            ['employee_id' => $this->movingTeamLeader->id, 'target_id' => $this->otherManager->id],
        ], $this->admin->id);
    }

    public function test_only_leaving_needs_their_own_work_handed_over(): void
    {
        $this->lead($this->caller);
        $this->lead($this->otherTeamLeader);

        $this->assertSame([EmployeeDependencyService::LEADS => 1], app(EmployeeDependencyService::class)->dependencyCounts($this->caller));
        $this->assertSame([EmployeeDependencyService::LEADS => 1], app(EmployeeDependencyService::class)->dependencyCounts($this->otherTeamLeader));
        $this->assertSame([], app(EmployeeDependencyService::class)->moveDependencyCounts($this->caller));
        $this->assertSame([], app(EmployeeDependencyService::class)->moveDependencyCounts($this->otherTeamLeader));
    }

    public function test_a_lead_records_who_created_it(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => $this->otherTeamLeader->id]));
        $keyedInByTheTeamLeader = Lead::query()->create($this->leadAttributes($this->otherTeamLeader));

        $this->actingAs($this->admin);
        $keyedInByTheAdmin = Lead::query()->create($this->leadAttributes($this->otherTeamLeader));

        $this->assertSame($this->otherTeamLeader->id, (int) $keyedInByTheTeamLeader->created_by);
        $this->assertNull($keyedInByTheAdmin->created_by, 'An Admin without an employee record keyed it in for them.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(int $designation, array $attributes = []): Employee
    {
        $position = Designation::query()->firstOrCreate(['name' => 'Executive']);

        return Employee::factory()->create([
            'designation' => $designation,
            'position' => $position->name,
            'category' => '3000000',
            'doj' => '2025-01-01',
            'reporting_date' => '2025-01-01',
            'exit_status' => 'no',
            ...$attributes,
        ]);
    }

    private function customer(Employee $owner): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $owner->id,
            'employee_id' => $owner->id,
            'journey_status' => 'sfl',
            'disbursal_finalized' => false,
            'disbursal_status' => null,
        ]);
    }

    /**
     * A customer $creator created (customers.employee_id) that $holder now
     * owns (customers.assign_to).
     */
    private function customerHandedTo(Employee $holder, Employee $creator): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $holder->id,
            'employee_id' => $creator->id,
            'journey_status' => 'sfl',
            'disbursal_finalized' => false,
            'disbursal_status' => null,
        ]);
    }

    private function disbursedCustomer(Employee $owner, string $disbursalDate): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $owner->id,
            'employee_id' => $owner->id,
            'sanctioned_loan_amount' => 500000,
            'disbursal_date' => $disbursalDate,
        ]);
    }

    /**
     * An open lead $owner created and still holds.
     */
    private function lead(Employee $owner): Lead
    {
        return $this->leadHandedTo($owner, $owner);
    }

    /**
     * An open lead $creator created that $holder now holds.
     */
    private function leadHandedTo(Employee $holder, ?Employee $creator): Lead
    {
        return Lead::query()->create([
            ...$this->leadAttributes($holder),
            'created_by' => $creator?->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function leadAttributes(Employee $holder): array
    {
        return [
            'employee_id' => $holder->id,
            'customer_name' => fake()->unique()->name(),
            'mobile_no' => '98765'.random_int(10000, 99999),
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'Followed up.',
        ];
    }

    private function assignedLead(Employee $holder, ?Employee $assignedBy): CustomerAssignment
    {
        $batch = CustomerAssignmentBatch::query()->create([
            'assigned_by' => $assignedBy?->id,
            'employee_id' => $holder->id,
            'customer_count' => 1,
        ]);

        return CustomerAssignment::query()->create([
            'batch_id' => $batch->id,
            'customer_id' => Customer::factory()->create()->id,
            'employee_id' => $holder->id,
            'assigned_by' => $assignedBy?->id,
        ]);
    }

    private function delegation(Employee $delegating, Employee $acting): CustomerJourneyDelegation
    {
        return CustomerJourneyDelegation::query()->create([
            'delegating_manager_id' => $delegating->id,
            'acting_manager_id' => $acting->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->addWeek(),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'On leave.',
            'status' => CustomerJourneyDelegation::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
    }
}
