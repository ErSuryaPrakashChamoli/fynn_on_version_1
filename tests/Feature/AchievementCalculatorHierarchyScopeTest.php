<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\AchievementCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies Phase 13 of the audit: each hierarchy level's achievement
 * reflects exactly the customers belonging to that level's own
 * subordinate tree — never a sibling branch, and never more/less than the
 * true rollup.
 */
class AchievementCalculatorHierarchyScopeTest extends TestCase
{
    use RefreshDatabase;

    private Employee $cluster;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller1;

    private Employee $caller2;

    private Employee $otherClusterCaller;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->caller1 = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
        ]);

        $this->caller2 = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->cluster->id,
        ]);

        // An entirely separate branch, whose numbers must never leak in.
        $otherCluster = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CLUSTER,
        ]);
        $otherManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $otherCluster->id,
        ]);
        $otherTeamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $otherManager->id,
            'cluster_id' => $otherCluster->id,
        ]);
        $this->otherClusterCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $otherTeamLeader->id,
            'manager_id' => $otherManager->id,
            'cluster_id' => $otherCluster->id,
        ]);

        $this->customer($this->caller1, 1000000);
        $this->customer($this->caller2, 2000000);
        $this->customer($this->otherClusterCaller, 5000000);
    }

    private function customer(Employee $caller, float $loanAmount): Customer
    {
        return Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_loan_amount' => $loanAmount,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
            'disbursal_date' => now(),
        ]);
    }

    public function test_caller_sees_only_their_own_customers(): void
    {
        $achievement = (new AchievementCalculatorService)->getCountAchievement($this->caller1);

        $this->assertSame(1000000.0, $achievement);
    }

    public function test_team_leader_sees_both_callers_but_not_the_other_branch(): void
    {
        $achievement = (new AchievementCalculatorService)->getCountAchievement($this->teamLeader);

        $this->assertSame(3000000.0, $achievement);
    }

    public function test_manager_sees_the_whole_team_but_not_the_other_branch(): void
    {
        $achievement = (new AchievementCalculatorService)->getCountAchievement($this->manager);

        $this->assertSame(3000000.0, $achievement);
    }

    public function test_cluster_manager_sees_the_whole_cluster_but_not_the_other_branch(): void
    {
        $achievement = (new AchievementCalculatorService)->getCountAchievement($this->cluster);

        $this->assertSame(3000000.0, $achievement);
    }

    public function test_admin_company_view_sees_every_branch(): void
    {
        $performance = (new AchievementCalculatorService)->getPerformance(null);

        $this->assertSame(8000000.0, $performance['count_achievement']);
    }
}
