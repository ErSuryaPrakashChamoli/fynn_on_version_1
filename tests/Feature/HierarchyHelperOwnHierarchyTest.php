<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Support\HierarchyHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HierarchyHelperOwnHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private Employee $cluster;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller;

    private Employee $otherCluster;

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

        $this->otherCluster = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CLUSTER,
        ]);

        $this->otherManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $this->otherCluster->id,
        ]);

        $this->otherTeamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->otherManager->id,
            'cluster_id' => $this->otherCluster->id,
        ]);

        $this->otherCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->otherTeamLeader->id,
            'manager_id' => $this->otherManager->id,
            'cluster_id' => $this->otherCluster->id,
        ]);
    }

    private function userFor(Employee $employee): User
    {
        $user = User::factory()->create();
        $user->employee_id = $employee->id;
        $user->save();

        return $user;
    }

    public function test_admin_sees_every_employee(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $ids = HierarchyHelper::ownHierarchyIds($admin);

        $this->assertTrue($ids->contains($this->cluster->id));
        $this->assertTrue($ids->contains($this->otherCaller->id));
        $this->assertCount(8, $ids);
    }

    public function test_manager_sees_own_team_and_upward_chain_only(): void
    {
        $user = $this->userFor($this->manager);

        $ids = HierarchyHelper::ownHierarchyIds($user);

        $this->assertEqualsCanonicalizing(
            [$this->manager->id, $this->teamLeader->id, $this->caller->id, $this->cluster->id],
            $ids->all()
        );
    }

    public function test_team_leader_sees_own_team_and_upward_chain_only(): void
    {
        $user = $this->userFor($this->teamLeader);

        $ids = HierarchyHelper::ownHierarchyIds($user);

        $this->assertEqualsCanonicalizing(
            [$this->teamLeader->id, $this->caller->id, $this->manager->id, $this->cluster->id],
            $ids->all()
        );
    }

    public function test_caller_sees_only_self_and_upward_chain(): void
    {
        $user = $this->userFor($this->caller);

        $ids = HierarchyHelper::ownHierarchyIds($user);

        $this->assertEqualsCanonicalizing(
            [$this->caller->id, $this->teamLeader->id, $this->manager->id, $this->cluster->id],
            $ids->all()
        );
    }

    public function test_cluster_manager_sees_own_downward_team_with_no_upward_chain(): void
    {
        $user = $this->userFor($this->cluster);

        $ids = HierarchyHelper::ownHierarchyIds($user);

        $this->assertEqualsCanonicalizing(
            [$this->cluster->id, $this->manager->id, $this->teamLeader->id, $this->caller->id],
            $ids->all()
        );
    }

    public function test_no_role_sees_another_teams_hierarchy(): void
    {
        $user = $this->userFor($this->manager);

        $ids = HierarchyHelper::ownHierarchyIds($user);

        $this->assertFalse($ids->contains($this->otherManager->id));
        $this->assertFalse($ids->contains($this->otherTeamLeader->id));
        $this->assertFalse($ids->contains($this->otherCaller->id));
        $this->assertFalse($ids->contains($this->otherCluster->id));
    }
}
