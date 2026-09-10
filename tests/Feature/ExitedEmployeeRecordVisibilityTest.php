<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Support\HierarchyHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Marking somebody inactive only sets employees.exit_status — it never
 * reassigns their customers, leads or follow-ups. The hierarchy above
 * them must therefore keep seeing that book, or a resignation quietly
 * hides live cases from everyone except the Admin.
 *
 * The trap this covers: the customer query walked the tree with
 * HierarchyHelper::subordinateIds(), which skips an exited Team Leader —
 * taking the still-working callers underneath them out of the Manager's
 * and Cluster Manager's sight as well.
 */
class ExitedEmployeeRecordVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Employee $clusterManager;

    private Employee $manager;

    private Employee $exitedTeamLeader;

    private Employee $exitedCaller;

    private Employee $workingCaller;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin');

        $this->clusterManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CLUSTER,
            'exit_status' => 'no',
        ]);

        $this->manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $this->clusterManager->id,
            'exit_status' => 'no',
        ]);

        $this->exitedTeamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->clusterManager->id,
            'exit_status' => 'yes',
            'exit_date' => now()->subDay()->toDateString(),
        ]);

        $this->exitedCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->exitedTeamLeader->id,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->clusterManager->id,
            'exit_status' => 'yes',
            'exit_date' => now()->subDay()->toDateString(),
        ]);

        $this->workingCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->exitedTeamLeader->id,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->clusterManager->id,
            'exit_status' => 'no',
        ]);
    }

    private function customerAssignedTo(Employee $employee): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $employee->id,
            'employee_id' => $employee->id,
        ]);
    }

    private function actingAsEmployee(Employee $employee): User
    {
        $user = User::factory()->create(['employee_id' => $employee->id]);

        $this->actingAs($user);

        return $user;
    }

    public function test_a_manager_still_sees_the_book_of_a_caller_under_an_exited_team_leader(): void
    {
        $customer = $this->customerAssignedTo($this->workingCaller);

        $this->actingAsEmployee($this->manager);

        $this->assertTrue(
            CustomerResource::getEloquentQuery()->pluck('id')->contains($customer->id)
        );
    }

    public function test_a_manager_still_sees_an_exited_callers_customers(): void
    {
        $customer = $this->customerAssignedTo($this->exitedCaller);

        $this->actingAsEmployee($this->manager);

        $this->assertTrue(
            CustomerResource::getEloquentQuery()->pluck('id')->contains($customer->id)
        );
    }

    public function test_a_manager_still_sees_the_cases_assigned_to_the_exited_team_leader_themselves(): void
    {
        $customer = $this->customerAssignedTo($this->exitedTeamLeader);

        $this->actingAsEmployee($this->manager);

        $this->assertTrue(
            CustomerResource::getEloquentQuery()->pluck('id')->contains($customer->id)
        );
    }

    public function test_a_cluster_manager_still_sees_the_whole_branch_under_an_exited_team_leader(): void
    {
        $customer = $this->customerAssignedTo($this->workingCaller);
        $teamLeaderCustomer = $this->customerAssignedTo($this->exitedTeamLeader);

        $this->actingAsEmployee($this->clusterManager);

        $visible = CustomerResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($visible->contains($customer->id));
        $this->assertTrue($visible->contains($teamLeaderCustomer->id));
    }

    public function test_another_branch_is_still_invisible(): void
    {
        $stranger = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'exit_status' => 'no',
        ]);

        $customer = $this->customerAssignedTo($stranger);

        $this->actingAsEmployee($this->manager);

        $this->assertFalse(
            CustomerResource::getEloquentQuery()->pluck('id')->contains($customer->id)
        );
    }

    public function test_edit_access_matches_what_the_listing_shows(): void
    {
        $customer = $this->customerAssignedTo($this->workingCaller);

        $this->actingAsEmployee($this->manager);

        $this->assertTrue(
            app(CustomerJourneyAccessService::class)->hasNormalAccess($this->manager, $customer)
        );
    }

    public function test_follow_ups_under_an_exited_team_leader_stay_with_the_manager(): void
    {
        $customer = $this->customerAssignedTo($this->workingCaller);

        $followUp = FollowUp::create([
            'customer_id' => $customer->id,
            'employee_id' => $this->workingCaller->id,
            'follow_up_type' => 'call',
            'remarks' => 'Call back next week.',
            'next_follow_up_date' => now()->addWeek(),
        ]);

        $this->actingAsEmployee($this->manager);

        $this->assertTrue(
            FollowUpResource::getEloquentQuery()->pluck('id')->contains($followUp->id)
        );
    }

    /**
     * The two tree walks are deliberately not the same, and this is the
     * guard against somebody "tidying" them back into one: the visibility
     * walk carries an exited level, the maths walk stops at it.
     */
    public function test_the_visibility_walk_and_the_maths_walk_differ_on_purpose(): void
    {
        $this->assertTrue(
            HierarchyHelper::visibleSubordinateIds($this->manager)->contains($this->workingCaller->id)
        );

        $this->assertFalse(
            HierarchyHelper::subordinateIds($this->manager)->contains($this->workingCaller->id)
        );
    }

    /**
     * Visibility and target maths are deliberately different questions:
     * a Manager keeps SEEING an exited Team Leader's team, while their
     * own target still stops at that exited level.
     */
    public function test_target_maths_still_stops_at_the_exited_team_leader(): void
    {
        $activeTeamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->manager->id,
            'exit_status' => 'no',
        ]);

        Employee::factory()->count(3)->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $activeTeamLeader->id,
            'manager_id' => $this->manager->id,
            'category' => '2500000',
            'reporting_date' => null,
        ]);

        $this->workingCaller->forceFill([
            'category' => '2500000',
            'reporting_date' => null,
        ])->save();

        // Only the three callers under the ACTIVE team leader count.
        $this->assertSame(
            7500000.0,
            (new AchievementCalculatorService)->getTarget($this->manager)
        );
    }
}
