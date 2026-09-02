<?php

namespace Tests\Feature;

use App\Filament\Resources\LeadAssignmentReports\LeadAssignmentReportResource;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerAssignmentBatch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeadAssignmentReportResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    private Employee $cluster;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller;

    private Employee $otherManager;

    private Employee $otherTeamLeader;

    private Employee $otherCaller;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->cluster = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CLUSTER,
        ]);

        $this->manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $this->cluster->id,
        ]);

        $this->teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
        ]);

        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
        ]);

        $otherCluster = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CLUSTER,
        ]);

        $this->otherManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $otherCluster->id,
        ]);

        $this->otherTeamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->otherManager->id,
            'cluster_id' => $otherCluster->id,
        ]);

        $this->otherCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->otherTeamLeader->id,
            'manager_id' => $this->otherManager->id,
            'cluster_id' => $otherCluster->id,
        ]);

        foreach ([$this->caller, $this->otherCaller] as $employee) {
            $this->giveAssignment($employee);
        }
    }

    private function giveAssignment(Employee $employee): void
    {
        $batch = CustomerAssignmentBatch::create(['employee_id' => $employee->id]);
        $customer = Customer::factory()->create();

        CustomerAssignment::create([
            'batch_id' => $batch->id,
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
        ]);
    }

    private function userFor(Employee $employee): User
    {
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $employee->update(['email' => $user->email]);

        return $user;
    }

    public function test_admin_can_view_any_and_sees_every_employee_report(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->assertTrue(LeadAssignmentReportResource::canViewAny());

        $ids = LeadAssignmentReportResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($ids->contains($this->caller->id));
        $this->assertTrue($ids->contains($this->otherCaller->id));
    }

    public function test_team_leader_can_view_but_only_sees_own_team(): void
    {
        $this->actingAs($this->userFor($this->teamLeader));

        $this->assertTrue(LeadAssignmentReportResource::canViewAny());

        $ids = LeadAssignmentReportResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($ids->contains($this->caller->id));
        $this->assertFalse($ids->contains($this->otherCaller->id));
    }

    public function test_manager_can_view_but_only_sees_own_team(): void
    {
        $this->actingAs($this->userFor($this->manager));

        $this->assertTrue(LeadAssignmentReportResource::canViewAny());

        $ids = LeadAssignmentReportResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($ids->contains($this->caller->id));
        $this->assertFalse($ids->contains($this->otherCaller->id));
    }

    public function test_cluster_manager_can_view_but_only_sees_own_cluster(): void
    {
        $this->actingAs($this->userFor($this->cluster));

        $this->assertTrue(LeadAssignmentReportResource::canViewAny());

        $ids = LeadAssignmentReportResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($ids->contains($this->caller->id));
        $this->assertFalse($ids->contains($this->otherCaller->id));
    }

    public function test_caller_cannot_view_the_report(): void
    {
        $this->actingAs($this->userFor($this->caller));

        $this->assertFalse(LeadAssignmentReportResource::canViewAny());
    }
}
