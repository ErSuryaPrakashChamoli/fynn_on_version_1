<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use App\Services\HierarchyReassignmentService;
use App\Support\HierarchyHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * HierarchyReassignmentService is a separate class from HierarchyHelper
 * (used by achievement/target calculation), but it writes the same
 * denormalized superviser_id/manager_id/cluster_id columns HierarchyHelper
 * reads. This verifies a reassignment actually moves an employee's
 * achievement/target scope, and that a reassignment does NOT reset the
 * transferred employee's reporting_date. It previously did, which fed
 * directly into the "new joiner" worked-days rule in
 * AchievementCalculatorService::getHierarchyCallerTargetForPeriod() and
 * wrongly zeroed a long-tenured employee's target for the transfer month.
 * Confirmed as unwanted and fixed: a hierarchy transfer moves an existing,
 * active employee — it must never be treated as a new joining.
 */
class HierarchyReassignmentTargetImpactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze "today" well past the 10th so the reassignment's
        // reporting_date reset lands on a deterministic worked-days count.
        $this->travelTo(now()->startOfMonth()->addDays(19));
    }

    public function test_reassigning_a_caller_moves_their_achievement_from_the_old_manager_to_the_new_one(): void
    {
        $oldCluster = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $oldManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $oldCluster->id,
        ]);
        $oldTeamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $oldManager->id,
            'cluster_id' => $oldCluster->id,
        ]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $oldTeamLeader->id,
            'manager_id' => $oldManager->id,
            'cluster_id' => $oldCluster->id,
            'category' => '2500000',
            'reporting_date' => now()->subYear(),
            'exit_status' => 'no',
        ]);

        Customer::factory()->create([
            'employee_id' => $caller->id,
            'sanctioned_loan_amount' => 1000000,
            'cashback' => 0,
            'subvention' => 0,
            'docking' => '0',
        ]);

        $newCluster = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $newManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $newCluster->id,
        ]);
        $newTeamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $newManager->id,
            'cluster_id' => $newCluster->id,
        ]);

        $calculator = new AchievementCalculatorService;

        // Before: achievement belongs to the old manager only.
        $this->assertSame(1000000.0, $calculator->getCountAchievement($oldManager));
        $this->assertSame(0.0, $calculator->getCountAchievement($newManager));
        $this->assertTrue(HierarchyHelper::subordinateIds($oldManager)->contains($caller->id));
        $this->assertFalse(HierarchyHelper::subordinateIds($newManager)->contains($caller->id));

        $performedBy = User::factory()->create()->id;

        app(HierarchyReassignmentService::class)->reassign(
            assignments: [
                ['employee_id' => $caller->id, 'target_id' => $newTeamLeader->id],
            ],
            performedBy: $performedBy,
        );

        $caller->refresh();

        // After: achievement follows the employee to the new manager.
        $this->assertSame(0.0, $calculator->getCountAchievement($oldManager));
        $this->assertSame(1000000.0, $calculator->getCountAchievement($newManager));
        $this->assertFalse(HierarchyHelper::subordinateIds($oldManager)->contains($caller->id));
        $this->assertTrue(HierarchyHelper::subordinateIds($newManager)->contains($caller->id));

        // Denormalized columns were cascaded consistently, not just superviser_id.
        $this->assertSame($newTeamLeader->id, $caller->superviser_id);
        $this->assertSame($newManager->id, $caller->manager_id);
        $this->assertSame($newCluster->id, $caller->cluster_id);
    }

    public function test_reassignment_does_not_reset_reporting_date_and_target_treats_the_employee_as_existing(): void
    {
        $oldTeamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER]);
        $cluster = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $cluster->id,
        ]);
        $newTeamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'cluster_id' => $cluster->id,
        ]);

        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $oldTeamLeader->id,
            'category' => '2500000',
            'reporting_date' => now()->subYear(), // long-tenured before the transfer
            'exit_status' => 'no',
        ]);

        $calculator = new AchievementCalculatorService;

        // Before the transfer: a long-tenured active caller gets the full
        // category target.
        $this->assertSame(2500000.0, $calculator->getHierarchyCallerTarget($caller));

        $performedBy = User::factory()->create()->id;

        app(HierarchyReassignmentService::class)->reassign(
            assignments: [
                ['employee_id' => $caller->id, 'target_id' => $newTeamLeader->id],
            ],
            performedBy: $performedBy,
        );

        $caller->refresh();

        // reporting_date must be untouched by the transfer — the caller is
        // an existing, active employee, not a new joiner, so the
        // current-month hierarchy target still evaluates to the full
        // category target rather than being wrongly zeroed out.
        $this->assertNotEquals(now()->toDateString(), Carbon::parse($caller->reporting_date)->toDateString());
        $this->assertSame(2500000.0, $calculator->getHierarchyCallerTarget($caller));

        // The caller's OWN target is unaffected either way — it is always
        // category-based regardless of joining/reassignment date.
        $this->assertSame(2500000.0, $calculator->getTarget($caller));
    }
}
