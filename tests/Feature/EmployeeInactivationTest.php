<?php

namespace Tests\Feature;

use App\Enums\InactivityRequestStatus;
use App\Enums\JourneyModule;
use App\Filament\Resources\EmployeeInactivityRequests\Pages\ListEmployeeInactivityRequests;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerAssignmentBatch;
use App\Models\CustomerJourneyDelegation;
use App\Models\CustomerReassignment;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeInactivityRequest;
use App\Models\EmployeeReportingHistory;
use App\Models\Lead;
use App\Models\User;
use App\Services\EmployeeDependencyService;
use App\Services\EmployeeInactivationService;
use App\Support\HierarchyHelper;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An employee comes off the rolls only once nothing still depends on them,
 * and always from a recorded effective (exit) date. Whatever depends on
 * them is shown in the "Mark Inactive" pop-up and cleared from there.
 */
class EmployeeInactivationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $leavingTeamLeader;

    private Employee $otherTeamLeader;

    private Employee $caller;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->manager = $this->employee(Employee::DESIGNATION_MANAGER);
        $this->leavingTeamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, ['manager_id' => $this->manager->id]);
        $this->otherTeamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, ['manager_id' => $this->manager->id]);
        $this->caller = $this->employee(Employee::DESIGNATION_CALLER, [
            'superviser_id' => $this->leavingTeamLeader->id,
            'manager_id' => $this->manager->id,
        ]);

        $this->admin = User::factory()->create()->assignRole(Role::findOrCreate('Admin'));
        $this->actingAs($this->admin);
    }

    public function test_an_employee_with_nothing_attached_is_marked_inactive_from_the_effective_date(): void
    {
        $effectiveDate = today()->subDays(3);

        Livewire::test(EditEmployee::class, ['record' => $this->caller->id])
            ->mountAction('inactivate')
            ->assertMountedActionModalSee(["Mark {$this->caller->emp_name} inactive", 'Effective date'])
            ->setActionData(['exit_date' => $effectiveDate->toDateString(), 'remarks' => 'Resigned.'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified("{$this->caller->emp_name} marked inactive");

        $caller = $this->caller->fresh();

        $this->assertSame('yes', $caller->exit_status);
        $this->assertSame($effectiveDate->toDateString(), Carbon::parse($caller->exit_date)->toDateString());

        $exit = EmployeeReportingHistory::query()->where('employee_id', $caller->id)->where('change_type', 'exit')->sole();
        $this->assertSame($effectiveDate->toDateString(), Carbon::parse($exit->effective_date)->toDateString());
        $this->assertSame('Resigned.', $exit->remarks);
        $this->assertSame(
            0,
            EmployeeReportingHistory::query()->where('employee_id', $caller->id)->where('change_type', '!=', 'exit')->whereNull('effective_to')->count(),
            'The reporting line they had must be closed on the exit date.',
        );
    }

    public function test_everything_still_attached_is_listed_and_moved_before_the_effective_date_is_asked_for(): void
    {
        $openCustomer = $this->customer($this->leavingTeamLeader);
        $disbursedCustomer = $this->customer($this->leavingTeamLeader, ['disbursal_status' => 'disbursed', 'disbursal_finalized' => true]);
        $openAssignedLead = $this->assignedLead($this->leavingTeamLeader);
        $convertedAssignedLead = $this->assignedLead($this->leavingTeamLeader, ['converted_at' => now()->subDay()]);
        $openLead = $this->lead($this->leavingTeamLeader, 'Call Back');
        $closedLead = $this->lead($this->leavingTeamLeader, 'Not Interested');
        $delegation = $this->delegation($this->leavingTeamLeader, $this->otherTeamLeader);

        $page = Livewire::test(EditEmployee::class, ['record' => $this->leavingTeamLeader->id])
            ->mountAction('inactivate')
            ->assertMountedActionModalSee([
                "{$this->leavingTeamLeader->emp_name} still has work attached",
                '1 direct report, 1 open customer, 1 open assigned lead, 1 open lead and 1 live delegation',
                $this->caller->emp_name,
                $openCustomer->customer_name,
                $openLead->customer_name,
                'Hand over & continue',
            ])
            ->assertMountedActionModalDontSee(['Effective date', $disbursedCustomer->customer_name, $closedLead->customer_name])
            ->setActionData([
                'direct_reports_target' => $this->otherTeamLeader->id,
                'handover_date' => today()->subDays(2)->toDateString(),
                'customers_target' => $this->otherTeamLeader->id,
                'assigned_leads_target' => $this->otherTeamLeader->id,
                'leads_target' => $this->otherTeamLeader->id,
                'cancel_delegations' => true,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        // Live work moved to somebody on the rolls…
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
        $this->assertSame($this->otherTeamLeader->id, (int) $openCustomer->fresh()->assign_to);
        $this->assertTrue(CustomerReassignment::query()->where('customer_id', $openCustomer->id)->exists());
        $this->assertSame($this->otherTeamLeader->id, (int) $openAssignedLead->fresh()->employee_id);
        $this->assertSame($this->otherTeamLeader->id, (int) $openLead->fresh()->employee_id);
        $this->assertSame(CustomerJourneyDelegation::STATUS_CANCELLED, $delegation->fresh()->status);

        // …finished work stays with them, so past figures do not change.
        $this->assertSame($this->leavingTeamLeader->id, (int) $disbursedCustomer->fresh()->assign_to);
        $this->assertSame($this->leavingTeamLeader->id, (int) $convertedAssignedLead->fresh()->employee_id);
        $this->assertSame($this->leavingTeamLeader->id, (int) $closedLead->fresh()->employee_id);

        $this->assertSame('no', $this->leavingTeamLeader->fresh()->exit_status, 'Clearing the work must not take them off the rolls by itself.');

        // The same pop-up is back on the effective date, offering the hand-over date.
        $this->assertSame('inactivate', $page->instance()->mountedActions[0]['name'] ?? null);

        $page->assertMountedActionModalSee(["Mark {$this->leavingTeamLeader->emp_name} inactive", 'Effective date'])
            ->assertActionDataSet(['exit_date' => today()->subDays(2)->toDateString()])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('yes', $this->leavingTeamLeader->fresh()->exit_status);
        $this->assertSame(today()->subDays(2)->toDateString(), Carbon::parse($this->leavingTeamLeader->fresh()->exit_date)->toDateString());
    }

    public function test_a_leaving_employees_work_can_go_to_anybody_on_the_rolls_whatever_their_position(): void
    {
        $leavingCaller = $this->employee(Employee::DESIGNATION_CALLER, ['superviser_id' => $this->otherTeamLeader->id, 'manager_id' => $this->manager->id]);
        $cluster = $this->employee(Employee::DESIGNATION_CLUSTER);
        $bankSupport = $this->employee(Employee::DESIGNATION_OTHER_BANK_SUPPORT);
        $alreadyLeft = $this->employee(Employee::DESIGNATION_TEAM_LEADER, ['exit_status' => 'yes', 'exit_date' => today()->subMonth()->toDateString()]);
        $openCustomer = $this->customer($leavingCaller);
        $openAssignedLead = $this->assignedLead($leavingCaller);
        $openLead = $this->lead($leavingCaller, 'Call Back');

        $offered = collect(app(EmployeeDependencyService::class)->newOwnerOptions($leavingCaller))
            ->flatMap(fn (array $group): array => array_keys($group))
            ->all();

        foreach ([$cluster, $this->manager, $this->otherTeamLeader, $this->caller, $bankSupport] as $onTheRolls) {
            $this->assertContains($onTheRolls->id, $offered, "{$onTheRolls->emp_name} is on the rolls and must be offered.");
        }

        $this->assertNotContains($leavingCaller->id, $offered);
        $this->assertNotContains($alreadyLeft->id, $offered);

        Livewire::test(EditEmployee::class, ['record' => $leavingCaller->id])
            ->mountAction('inactivate')
            ->setActionData([
                'customers_target' => $this->caller->id,
                'assigned_leads_target' => $this->manager->id,
                'leads_target' => $cluster->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($this->caller->id, (int) $openCustomer->fresh()->assign_to, 'A fellow caller may take the customers.');
        $this->assertSame($this->manager->id, (int) $openAssignedLead->fresh()->employee_id);
        $this->assertSame($cluster->id, (int) $openLead->fresh()->employee_id);
    }

    public function test_a_hand_over_to_somebody_off_the_rolls_is_refused(): void
    {
        $alreadyLeft = $this->employee(Employee::DESIGNATION_TEAM_LEADER, ['exit_status' => 'yes', 'exit_date' => today()->subMonth()->toDateString()]);
        $openLead = $this->lead($this->leavingTeamLeader, 'Call Back');

        $this->expectException(ValidationException::class);

        try {
            app(EmployeeDependencyService::class)->moveOpenLeads($this->leavingTeamLeader, $alreadyLeft->id);
        } finally {
            $this->assertSame($this->leavingTeamLeader->id, (int) $openLead->fresh()->employee_id);
        }
    }

    public function test_what_is_left_after_a_partial_move_is_listed_again(): void
    {
        $openLead = $this->lead($this->leavingTeamLeader, 'Call Back');

        $page = Livewire::test(EditEmployee::class, ['record' => $this->leavingTeamLeader->id])
            ->mountAction('inactivate')
            ->setActionData(['leads_target' => $this->otherTeamLeader->id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame($this->otherTeamLeader->id, (int) $openLead->fresh()->employee_id);

        $page->assertMountedActionModalSee(['Still attached: 1 direct report.', $this->caller->emp_name])
            ->assertMountedActionModalDontSee('Effective date');

        $this->assertSame('no', $this->leavingTeamLeader->fresh()->exit_status);
    }

    public function test_choosing_nowhere_to_move_anything_changes_nothing(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->leavingTeamLeader->id])
            ->mountAction('inactivate')
            ->callMountedAction()
            ->assertNotified('Nothing was handed over');

        $this->assertSame($this->leavingTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
        $this->assertSame('no', $this->leavingTeamLeader->fresh()->exit_status);
    }

    public function test_the_service_refuses_to_take_off_the_rolls_anyone_with_work_still_attached(): void
    {
        try {
            app(EmployeeInactivationService::class)->inactivate($this->leavingTeamLeader, today(), null, $this->admin);
            $this->fail('An employee with a direct report was made inactive.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('still has 1 direct report', $exception->errors()['dependencies'][0]);
        }

        $this->assertSame('no', $this->leavingTeamLeader->fresh()->exit_status);
    }

    public function test_the_effective_date_cannot_be_in_the_future_or_before_they_joined(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->caller->id])
            ->callAction('inactivate', ['exit_date' => today()->addDay()->toDateString()])
            ->assertHasActionErrors(['exit_date']);

        Livewire::test(EditEmployee::class, ['record' => $this->caller->id])
            ->callAction('inactivate', ['exit_date' => '2024-12-31'])
            ->assertHasActionErrors(['exit_date']);

        $this->assertSame('no', $this->caller->fresh()->exit_status);

        $this->expectException(ValidationException::class);
        app(EmployeeInactivationService::class)->inactivate($this->caller, Carbon::parse('2024-12-31'), null, $this->admin);
    }

    public function test_the_edit_form_cannot_switch_somebody_on_the_rolls_to_inactive(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->caller->id])
            ->assertActionVisible('inactivate')
            ->assertFormFieldIsDisabled('exit_status')
            ->fillForm(['exit_status' => 'yes', 'exit_date' => today()->toDateString()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('no', $this->caller->fresh()->exit_status);
        $this->assertNull($this->caller->fresh()->exit_date);
    }

    public function test_an_exited_employee_can_still_be_brought_back_from_the_edit_form(): void
    {
        app(EmployeeInactivationService::class)->inactivate($this->caller, today()->subDay(), null, $this->admin);

        Livewire::test(EditEmployee::class, ['record' => $this->caller->id])
            ->assertActionHidden('inactivate')
            ->assertFormFieldIsEnabled('exit_status')
            ->fillForm(['exit_status' => 'no'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('no', $this->caller->fresh()->exit_status);
        $this->assertNull($this->caller->fresh()->exit_date);
    }

    public function test_an_inactivity_ticket_cannot_be_approved_while_work_is_still_attached(): void
    {
        $ticket = $this->ticket($this->leavingTeamLeader);

        Livewire::test(ListEmployeeInactivityRequests::class)
            ->mountAction(TestAction::make('approve')->table($ticket))
            ->assertMountedActionModalSee([
                "{$this->leavingTeamLeader->emp_name} still has work attached",
                '1 direct report',
                'Clear on employee page',
            ])
            ->assertMountedActionModalDontSee('Effective date')
            ->callMountedAction();

        $this->assertSame(InactivityRequestStatus::Pending, $ticket->fresh()->status);
        $this->assertSame('no', $this->leavingTeamLeader->fresh()->exit_status);
    }

    public function test_approving_an_inactivity_ticket_records_the_chosen_effective_date(): void
    {
        $ticket = $this->ticket($this->caller);
        $effectiveDate = today()->subDays(4);

        Livewire::test(ListEmployeeInactivityRequests::class)
            ->callAction(TestAction::make('approve')->table($ticket), [
                'exit_date' => $effectiveDate->toDateString(),
                'review_note' => 'Confirmed with HR.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Ticket approved');

        $this->assertSame(InactivityRequestStatus::Approved, $ticket->fresh()->status);
        $this->assertSame('yes', $this->caller->fresh()->exit_status);
        $this->assertSame($effectiveDate->toDateString(), Carbon::parse($this->caller->fresh()->exit_date)->toDateString());
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(Employee $owner, array $attributes = []): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $owner->id,
            'employee_id' => $this->caller->id,
            'journey_status' => 'sfl',
            'disbursal_finalized' => false,
            'disbursal_status' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assignedLead(Employee $owner, array $attributes = []): CustomerAssignment
    {
        $batch = CustomerAssignmentBatch::query()->create([
            'assigned_by' => null,
            'employee_id' => $owner->id,
            'customer_count' => 1,
        ]);

        return CustomerAssignment::query()->create([
            'batch_id' => $batch->id,
            'customer_id' => Customer::factory()->create()->id,
            'employee_id' => $owner->id,
            'assigned_by' => null,
            ...$attributes,
        ]);
    }

    private function lead(Employee $owner, string $status): Lead
    {
        return Lead::query()->create([
            'employee_id' => $owner->id,
            'customer_name' => fake()->name(),
            'mobile_no' => '98765'.random_int(10000, 99999),
            'follow_up_type' => 'Call',
            'status' => $status,
            'remarks' => 'Followed up.',
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

    private function ticket(Employee $employee): EmployeeInactivityRequest
    {
        return EmployeeInactivityRequest::query()->create([
            'employee_id' => $employee->id,
            'month' => today()->startOfMonth(),
            'requested_by' => $this->admin->id,
            'reason' => 'Stopped turning up.',
            'status' => InactivityRequestStatus::Pending,
        ]);
    }
}
