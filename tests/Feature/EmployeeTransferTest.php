<?php

namespace Tests\Feature;

use App\Enums\JourneyModule;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Customer;
use App\Models\CustomerJourneyDelegation;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use App\Models\Lead;
use App\Models\User;
use App\Services\EmployeeTransferService;
use App\Support\HierarchyHelper;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An employee moved to a new boss — "Transfer Employee", the Flexible
 * Reassignment, or the Edit form's Reports To — moves alone: their team,
 * open customers, leads and delegations are handed over first, on the
 * spot, and only then does the move happen.
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

    public function test_a_team_leader_hands_over_their_team_and_work_then_moves_alone(): void
    {
        $customer = $this->customer($this->movingTeamLeader);
        $lead = $this->lead($this->movingTeamLeader);
        $delegation = $this->delegation($this->movingTeamLeader, $this->otherTeamLeader);
        $handoverDate = today()->subDay()->toDateString();

        $page = Livewire::test(EditEmployee::class, ['record' => $this->movingTeamLeader->id])
            ->mountAction('transferEmployee')
            ->assertMountedActionModalSee([
                "{$this->movingTeamLeader->emp_name} still has work attached",
                '1 direct report, 1 open customer, 1 open lead and 1 live delegation',
                $this->caller->emp_name,
                'Hand over & continue',
            ])
            ->assertMountedActionModalDontSee('New Boss')
            ->setActionData([
                'direct_reports_target' => $this->otherTeamLeader->id,
                'handover_date' => $handoverDate,
                'customers_target' => $this->otherTeamLeader->id,
                'leads_target' => $this->otherTeamLeader->id,
                'cancel_delegations' => true,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
        $this->assertSame($this->otherTeamLeader->id, (int) $customer->fresh()->assign_to);
        $this->assertSame($this->otherTeamLeader->id, (int) $lead->fresh()->employee_id);
        $this->assertSame(CustomerJourneyDelegation::STATUS_CANCELLED, $delegation->fresh()->status);
        $this->assertSame($this->manager->id, HierarchyHelper::directBossId($this->movingTeamLeader->fresh()), 'Handing over must not move them by itself.');

        // The same pop-up is back on the transfer itself, dated like the hand-over.
        $this->assertSame('transferEmployee', $page->instance()->mountedActions[0]['name'] ?? null);

        $page->assertMountedActionModalSee(["Transfer {$this->movingTeamLeader->emp_name}", 'New Boss'])
            ->assertActionDataSet(['effective_date' => $handoverDate])
            ->setActionData(['reports_to' => $this->otherManager->id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Employee transferred successfully.');

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->movingTeamLeader->fresh()));
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()), 'The team stays where it was handed over.');
        $this->assertSame($this->manager->id, (int) $this->caller->fresh()->manager_id);
    }

    public function test_the_transfer_service_refuses_while_anything_is_attached(): void
    {
        try {
            app(EmployeeTransferService::class)->transfer($this->movingTeamLeader, $this->otherManager->id, today(), null, $this->admin);
            $this->fail('A Team Leader with a caller was moved.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('still has 1 direct report', $exception->errors()['reports_to'][0]);
        }

        $this->assertSame($this->manager->id, HierarchyHelper::directBossId($this->movingTeamLeader->fresh()));
    }

    public function test_the_edit_form_refuses_a_straight_move_while_work_is_attached(): void
    {
        $this->lead($this->caller);

        Livewire::test(EditEmployee::class, ['record' => $this->caller->id])
            ->fillForm(['reports_to' => $this->otherTeamLeader->id])
            ->call('save')
            ->assertHasFormErrors(['reports_to']);

        $this->assertSame($this->movingTeamLeader->id, HierarchyHelper::directBossId($this->caller->fresh()));
    }

    public function test_a_flexible_reassignment_hands_over_what_is_attached_then_comes_back_to_be_confirmed(): void
    {
        $undoRepeaterFake = Repeater::fake();

        $page = Livewire::test(ListEmployees::class)
            ->mountAction('transferCluster')
            ->setActionData([
                'transfer_type' => 'flexible_reassignment',
                'assignments' => [['employee_id' => $this->movingTeamLeader->id, 'target_id' => $this->otherManager->id]],
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        // Not moved yet: the pop-up switched to handing over their caller.
        $this->assertSame($this->manager->id, HierarchyHelper::directBossId($this->movingTeamLeader->fresh()));
        $this->assertSame('clearTransferDependencies', $page->instance()->mountedActions[0]['name'] ?? null);

        $page->assertMountedActionModalSee(["{$this->movingTeamLeader->emp_name} still has work attached", $this->caller->emp_name])
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

    public function test_a_flexible_reassignment_is_refused_while_anybody_in_it_still_has_work_attached(): void
    {
        $this->lead($this->caller);

        $this->expectException(ValidationException::class);

        app(EmployeeTransferService::class)->reassign([
            ['employee_id' => $this->caller->id, 'target_id' => $this->otherTeamLeader->id],
        ], $this->admin->id);
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
