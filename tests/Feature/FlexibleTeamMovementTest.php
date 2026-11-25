<?php

namespace Tests\Feature;

use App\Filament\Pages\EmployeeLookup;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Customer;
use App\Models\CustomerReassignment;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\HierarchyTransferLog;
use App\Models\User;
use App\Services\EmployeeTransferService;
use App\Support\HierarchyHelper;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Moving people with as much or as little of their team as the admin
 * wants (user decision 2026-11-24): "Transfer Employee" takes the whole
 * team, part of it or nobody along; "Move Team" moves all or part of
 * anybody's team while they stay; the Flexible Reassignment lets each row
 * take its team along; and all of it is offered on Employee Lookup and the
 * Employees list as well as the Employee page.
 */
class FlexibleTeamMovementTest extends TestCase
{
    use RefreshDatabase;

    private Employee $cluster;

    private Employee $manager;

    private Employee $otherManager;

    private Employee $teamLeader;

    private Employee $otherTeamLeader;

    private Employee $firstCaller;

    private Employee $secondCaller;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->cluster = $this->employee(Employee::DESIGNATION_CLUSTER);
        $this->manager = $this->employee(Employee::DESIGNATION_MANAGER, ['cluster_id' => $this->cluster->id]);
        $this->otherManager = $this->employee(Employee::DESIGNATION_MANAGER, ['cluster_id' => $this->cluster->id]);
        $this->teamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, ['manager_id' => $this->manager->id, 'cluster_id' => $this->cluster->id]);
        $this->otherTeamLeader = $this->employee(Employee::DESIGNATION_TEAM_LEADER, ['manager_id' => $this->otherManager->id, 'cluster_id' => $this->cluster->id]);
        $this->firstCaller = $this->callerUnder($this->teamLeader);
        $this->secondCaller = $this->callerUnder($this->teamLeader);

        $this->admin = User::factory()->create()->assignRole(Role::findOrCreate('Admin'));
        $this->actingAs($this->admin);
    }

    public function test_a_team_leader_moves_with_their_whole_team(): void
    {
        $callerCustomer = $this->customer($this->firstCaller);

        Livewire::test(EditEmployee::class, ['record' => $this->teamLeader->id])
            ->mountAction('transferEmployee')
            ->assertMountedActionModalSee(['2 people report straight to', 'Whole team moves with them', 'Part of the team moves', 'Move alone'])
            ->setActionData([
                'reports_to' => $this->otherManager->id,
                'team' => EmployeeTransferService::TEAM_WHOLE,
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Employee transferred successfully.');

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->teamLeader->fresh()));

        foreach ([$this->firstCaller, $this->secondCaller] as $caller) {
            $this->assertSame($this->teamLeader->id, HierarchyHelper::directBossId($caller->fresh()), 'The team stays with its Team Leader.');
            $this->assertSame($this->otherManager->id, (int) $caller->fresh()->manager_id, 'and now sits under the new Manager.');
        }

        $this->assertSame($this->firstCaller->id, (int) $callerCustomer->fresh()->assign_to);
        $this->assertSame(0, CustomerReassignment::query()->count(), 'Nobody\'s work is handed over.');
    }

    public function test_part_of_the_team_moves_and_the_rest_stay_with_the_old_boss_by_default(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->teamLeader->id])
            ->mountAction('transferEmployee')
            ->assertActionDataSet(['staying_team_boss' => $this->manager->id])
            ->setActionData([
                'reports_to' => $this->otherManager->id,
                'team' => EmployeeTransferService::TEAM_PART,
                'coming_along' => [$this->firstCaller->id],
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Employee transferred successfully.');

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->teamLeader->fresh()));
        $this->assertSame($this->teamLeader->id, HierarchyHelper::directBossId($this->firstCaller->fresh()));
        $this->assertSame($this->otherManager->id, (int) $this->firstCaller->fresh()->manager_id);
        $this->assertSame($this->manager->id, HierarchyHelper::directBossId($this->secondCaller->fresh()), 'Whoever was not ticked stays with the old Manager.');
    }

    public function test_moving_alone_hands_the_whole_team_to_the_boss_chosen_for_them(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->teamLeader->id])
            ->mountAction('transferEmployee')
            ->setActionData([
                'reports_to' => $this->otherManager->id,
                'team' => EmployeeTransferService::TEAM_NONE,
                'staying_team_boss' => $this->otherTeamLeader->id,
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->teamLeader->fresh()));
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->firstCaller->fresh()));
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->secondCaller->fresh()));
    }

    public function test_somebody_with_a_team_must_say_what_happens_to_it(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->teamLeader->id])
            ->mountAction('transferEmployee')
            ->setActionData([
                'reports_to' => $this->otherManager->id,
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['team' => 'required']);

        $this->assertSame($this->manager->id, HierarchyHelper::directBossId($this->teamLeader->fresh()));
    }

    public function test_part_of_the_team_needs_somebody_ticked(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->teamLeader->id])
            ->mountAction('transferEmployee')
            ->setActionData([
                'reports_to' => $this->otherManager->id,
                'team' => EmployeeTransferService::TEAM_PART,
                'coming_along' => [],
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['coming_along' => 'required']);
    }

    public function test_those_staying_behind_cannot_report_to_somebody_who_is_moving_away(): void
    {
        $directCaller = $this->employee(Employee::DESIGNATION_CALLER, ['manager_id' => $this->manager->id, 'cluster_id' => $this->cluster->id]);
        $otherCluster = $this->employee(Employee::DESIGNATION_CLUSTER);
        $transfers = app(EmployeeTransferService::class);

        $this->assertArrayNotHasKey(
            $this->teamLeader->id,
            collect($transfers->stayingBehindBossOptions($this->manager, [$this->teamLeader->id]))->collapse()->all(),
            'A Team Leader moving with the Manager is not offered as the boss of those staying behind.',
        );

        try {
            $transfers->transfer($this->manager, $otherCluster->id, today(), null, $this->admin, [$this->teamLeader->id], $this->teamLeader->id);
            $this->fail('The caller staying behind was given a boss who is moving away.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('staying_team_boss', $exception->errors());
        }

        $this->assertSame($this->manager->id, HierarchyHelper::directBossId($directCaller->fresh()));
        $this->assertSame($this->cluster->id, HierarchyHelper::directBossId($this->manager->fresh()));
    }

    public function test_move_team_moves_a_team_leaders_whole_team_while_they_stay(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->teamLeader->id])
            ->mountAction('moveTeam')
            ->assertMountedActionModalSee(["Move {$this->teamLeader->emp_name}'s team", 'Whole team (2)'])
            ->setActionData([
                'scope' => EmployeeTransferService::TEAM_WHOLE,
                'target_id' => $this->otherTeamLeader->id,
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Team moved');

        $this->assertSame($this->manager->id, HierarchyHelper::directBossId($this->teamLeader->fresh()), 'The Team Leader stays put.');
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->firstCaller->fresh()));
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->secondCaller->fresh()));
        $this->assertSame('whole_team', HierarchyTransferLog::query()->latest('id')->value('transfer_type'));
    }

    public function test_move_team_moves_only_the_people_ticked(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->teamLeader->id])
            ->mountAction('moveTeam')
            ->setActionData([
                'scope' => EmployeeTransferService::TEAM_PART,
                'member_ids' => [$this->secondCaller->id],
                'target_id' => $this->otherTeamLeader->id,
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($this->teamLeader->id, HierarchyHelper::directBossId($this->firstCaller->fresh()));
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->secondCaller->fresh()));
        $this->assertSame('part_team', HierarchyTransferLog::query()->latest('id')->value('transfer_type'));
    }

    public function test_move_team_refuses_a_new_boss_from_inside_the_team(): void
    {
        $directCaller = $this->employee(Employee::DESIGNATION_CALLER, ['manager_id' => $this->manager->id, 'cluster_id' => $this->cluster->id]);

        $this->expectException(ValidationException::class);

        try {
            app(EmployeeTransferService::class)->moveTeam($this->manager, [$directCaller->id], $this->teamLeader->id, today(), null, $this->admin->id);
        } finally {
            $this->assertSame($this->manager->id, HierarchyHelper::directBossId($directCaller->fresh()));
        }
    }

    public function test_a_managers_whole_team_moves_from_the_employees_list(): void
    {
        Livewire::test(ListEmployees::class)
            ->mountAction('transferCluster')
            ->setActionData([
                'transfer_type' => ListEmployees::MODE_MOVE_TEAM,
                'team_boss_id' => $this->manager->id,
                'team_scope' => EmployeeTransferService::TEAM_WHOLE,
                'team_target_id' => $this->otherManager->id,
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Hierarchy transfer completed');

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->teamLeader->fresh()));
        $this->assertSame($this->teamLeader->id, HierarchyHelper::directBossId($this->firstCaller->fresh()));
        $this->assertSame($this->otherManager->id, (int) $this->firstCaller->fresh()->manager_id);
        $this->assertSame($this->cluster->id, HierarchyHelper::directBossId($this->manager->fresh()));
    }

    public function test_a_flexible_reassignment_row_can_take_its_team_along_without_a_hand_over(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(ListEmployees::class)
            ->mountAction('transferCluster')
            ->setActionData([
                'transfer_type' => ListEmployees::MODE_FLEXIBLE,
                'assignments' => [
                    // Splits the team: one caller goes elsewhere, the other follows the Team Leader.
                    ['employee_id' => $this->teamLeader->id, 'target_id' => $this->otherManager->id, 'team' => EmployeeTransferService::TEAM_WHOLE],
                    ['employee_id' => $this->secondCaller->id, 'target_id' => $this->otherTeamLeader->id],
                ],
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Hierarchy transfer completed');

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->teamLeader->fresh()));
        $this->assertSame($this->teamLeader->id, HierarchyHelper::directBossId($this->firstCaller->fresh()));
        $this->assertSame($this->otherManager->id, (int) $this->firstCaller->fresh()->manager_id);
        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->secondCaller->fresh()));

        $undoRepeaterFake();
    }

    public function test_the_admin_moves_an_employee_with_their_team_from_employee_lookup(): void
    {
        Livewire::withQueryParams(['employee' => $this->teamLeader->id])
            ->test(EmployeeLookup::class)
            ->assertActionVisible('transferEmployee')
            ->assertActionVisible('moveTeam')
            ->assertActionVisible('changePosition')
            ->assertActionVisible('inactivate')
            ->assertActionVisible('editEmployeeRecord')
            ->mountAction('transferEmployee')
            ->setActionData([
                'reports_to' => $this->otherManager->id,
                'team' => EmployeeTransferService::TEAM_WHOLE,
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Employee transferred successfully.')
            ->assertRedirect();

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->teamLeader->fresh()));
        $this->assertSame($this->otherManager->id, (int) $this->firstCaller->fresh()->manager_id);
    }

    public function test_the_admin_moves_a_team_from_employee_lookup(): void
    {
        Livewire::withQueryParams(['employee' => $this->teamLeader->id])
            ->test(EmployeeLookup::class)
            ->mountAction('moveTeam')
            ->setActionData([
                'scope' => EmployeeTransferService::TEAM_WHOLE,
                'target_id' => $this->otherTeamLeader->id,
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->firstCaller->fresh()));
    }

    public function test_a_caller_on_employee_lookup_gets_no_team_choice_and_no_move_team(): void
    {
        Livewire::withQueryParams(['employee' => $this->firstCaller->id])
            ->test(EmployeeLookup::class)
            ->assertActionVisible('transferEmployee')
            ->assertActionHidden('moveTeam')
            ->mountAction('transferEmployee')
            ->assertMountedActionModalDontSee('Whole team moves with them')
            ->setActionData([
                'reports_to' => $this->otherTeamLeader->id,
                'effective_date' => today()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($this->otherTeamLeader->id, HierarchyHelper::directBossId($this->firstCaller->fresh()));
    }

    public function test_only_the_admin_gets_the_manage_menu_on_employee_lookup(): void
    {
        $managerLogin = User::factory()->create(['employee_id' => $this->manager->id])->assignRole(Role::findOrCreate('Manager'));
        $this->actingAs($managerLogin);

        Livewire::withQueryParams(['employee' => $this->teamLeader->id])
            ->test(EmployeeLookup::class)
            ->assertSuccessful()
            ->assertActionDoesNotExist('transferEmployee')
            ->assertActionDoesNotExist('moveTeam')
            ->assertActionDoesNotExist('changePosition')
            ->assertActionDoesNotExist('inactivate');
    }

    public function test_the_employees_list_moves_somebody_with_their_team_from_the_row(): void
    {
        Livewire::test(ListEmployees::class)
            ->assertActionVisible(TestAction::make('transferEmployee')->table($this->teamLeader))
            ->assertActionVisible(TestAction::make('changePosition')->table($this->teamLeader))
            ->callAction(TestAction::make('transferEmployee')->table($this->teamLeader), [
                'reports_to' => $this->otherManager->id,
                'team' => EmployeeTransferService::TEAM_WHOLE,
                'effective_date' => today()->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($this->otherManager->id, HierarchyHelper::directBossId($this->teamLeader->fresh()));
        $this->assertSame($this->teamLeader->id, HierarchyHelper::directBossId($this->secondCaller->fresh()));
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

    private function callerUnder(Employee $teamLeader): Employee
    {
        return $this->employee(Employee::DESIGNATION_CALLER, [
            'superviser_id' => $teamLeader->id,
            'manager_id' => $teamLeader->manager_id,
            'cluster_id' => $teamLeader->cluster_id,
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
}
