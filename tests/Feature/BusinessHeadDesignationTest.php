<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeReportingHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BusinessHeadDesignationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_head_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('employees', 'business_head_id'));
        $this->assertTrue(Schema::hasColumn('employee_reporting_history', 'old_business_head_id'));
        $this->assertTrue(Schema::hasColumn('employee_reporting_history', 'new_business_head_id'));
    }

    public function test_business_head_is_a_labelled_designation(): void
    {
        $this->assertSame('Business Head', Employee::designationOptions()[Employee::DESIGNATION_BUSINESS_HEAD]);
        $this->assertNotSame(
            Employee::designationColorClass(null),
            Employee::designationColorClass(Employee::DESIGNATION_BUSINESS_HEAD)
        );
    }

    public function test_business_head_code_does_not_clash_with_an_existing_designation(): void
    {
        $this->assertNotContains(Employee::DESIGNATION_BUSINESS_HEAD, [
            Employee::DESIGNATION_ADMIN,
            Employee::DESIGNATION_MANAGER,
            Employee::DESIGNATION_TEAM_LEADER,
            Employee::DESIGNATION_CLUSTER,
            Employee::DESIGNATION_CALLER,
        ]);
    }

    public function test_designations_rank_from_caller_up_to_business_head(): void
    {
        $ranks = array_map(Employee::designationRank(...), [
            Employee::DESIGNATION_CALLER,
            Employee::DESIGNATION_TEAM_LEADER,
            Employee::DESIGNATION_MANAGER,
            Employee::DESIGNATION_CLUSTER,
            Employee::DESIGNATION_BUSINESS_HEAD,
        ]);

        $sorted = $ranks;
        sort($sorted);

        $this->assertSame($sorted, $ranks);
        $this->assertCount(5, array_unique($ranks));
    }

    public function test_admin_and_unknown_designations_have_no_rank(): void
    {
        $this->assertSame(0, Employee::designationRank(Employee::DESIGNATION_ADMIN));
        $this->assertSame(0, Employee::designationRank(null));
        $this->assertSame(0, Employee::designationRank(999));
    }

    public function test_cluster_manager_can_belong_to_a_business_head(): void
    {
        $businessHead = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_BUSINESS_HEAD,
        ]);

        $cluster = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CLUSTER,
            'business_head_id' => $businessHead->id,
        ]);

        $this->assertTrue($cluster->businessHead->is($businessHead));
    }

    public function test_joining_history_records_the_business_head(): void
    {
        $businessHead = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_BUSINESS_HEAD,
        ]);

        $cluster = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CLUSTER,
            'business_head_id' => $businessHead->id,
        ]);

        $history = EmployeeReportingHistory::query()
            ->where('employee_id', $cluster->id)
            ->where('change_type', 'joining')
            ->sole();

        $this->assertNull($history->old_business_head_id);
        $this->assertSame($businessHead->id, $history->new_business_head_id);
        $this->assertTrue($history->newBusinessHead->is($businessHead));
    }

    public function test_direct_boss_is_the_nearest_filled_level(): void
    {
        $businessHead = Employee::factory()->create(['designation' => Employee::DESIGNATION_BUSINESS_HEAD]);
        $cluster = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER, 'business_head_id' => $businessHead->id]);
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER, 'cluster_id' => $cluster->id, 'business_head_id' => $businessHead->id]);
        $teamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER, 'manager_id' => $manager->id, 'cluster_id' => $cluster->id, 'business_head_id' => $businessHead->id]);
        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'superviser_id' => $teamLeader->id, 'manager_id' => $manager->id, 'cluster_id' => $cluster->id, 'business_head_id' => $businessHead->id]);

        $this->assertSame($teamLeader->id, $caller->directBossId());
        $this->assertSame($manager->id, $teamLeader->directBossId());
        $this->assertSame($cluster->id, $manager->directBossId());
        $this->assertSame($businessHead->id, $cluster->directBossId());
        $this->assertNull($businessHead->directBossId());
    }

    public function test_direct_boss_skips_levels_left_empty(): void
    {
        $businessHead = Employee::factory()->create(['designation' => Employee::DESIGNATION_BUSINESS_HEAD]);
        $cluster = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER, 'business_head_id' => $businessHead->id]);

        $skipLevelTeamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => null,
            'cluster_id' => $cluster->id,
            'business_head_id' => $businessHead->id,
        ]);

        $callerUnderCluster = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => null,
            'manager_id' => null,
            'cluster_id' => $cluster->id,
            'business_head_id' => $businessHead->id,
        ]);

        $managerUnderBusinessHead = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => null,
            'business_head_id' => $businessHead->id,
        ]);

        $this->assertSame($cluster->id, $skipLevelTeamLeader->directBossId());
        $this->assertSame($cluster->id, $callerUnderCluster->directBossId());
        $this->assertSame($businessHead->id, $managerUnderBusinessHead->directBossId());
    }

    public function test_existing_employees_without_a_business_head_are_unchanged(): void
    {
        $cluster = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER, 'cluster_id' => $cluster->id]);

        $this->assertNull($cluster->fresh()->business_head_id);
        $this->assertNull($cluster->directBossId());
        $this->assertSame($cluster->id, $manager->directBossId());
    }
}
