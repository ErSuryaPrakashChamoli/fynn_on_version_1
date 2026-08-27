<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Services\Journey\CustomerJourneyDelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section 14 — continuity must not be hard-coded to Managers only. This
 * proves a Caller and a Cluster Manager can each be the "original employee"
 * of a continuity rule, with an eligible backup elsewhere in the same
 * cluster branch gaining access.
 */
class AnyHierarchyLevelBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_caller_can_be_covered_by_a_backup_within_the_same_branch(): void
    {
        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'cluster_id' => $clusterManager->id,
        ]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'cluster_id' => $clusterManager->id,
        ]);
        $backupCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
            'cluster_id' => $clusterManager->id,
        ]);

        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'sfl',
            'documents_submitted' => false,
        ]);

        // The Team Leader is a superior of the Caller, so may create
        // coverage on the Caller's behalf.
        $creator = User::factory()->create(['employee_id' => $teamLeader->id]);

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $caller->id,
            'acting_manager_id' => $backupCaller->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'modules' => [JourneyModule::DocumentVerification->value],
            'reason' => 'Caller on leave.',
        ], $creator);

        $backupUser = User::factory()->create(['employee_id' => $backupCaller->id]);

        // Note: hasNormalAccess() excludes Callers by design (unchanged
        // existing rule) — this is testing that the DELEGATION path itself
        // resolves correctly for a Caller-level original employee, which is
        // what the module-matching logic must support even though a Caller
        // backup would still be blocked by the unrelated Caller-exclusion
        // rule for actually editing the Customer resource.
        $delegation = app(CustomerJourneyAccessService::class)
            ->activeDelegationFor($backupCaller, $customer, JourneyModule::DocumentVerification);

        $this->assertNotNull($delegation);
        $this->assertSame($caller->id, $delegation->delegating_manager_id);
    }

    public function test_a_cluster_manager_can_be_covered_by_a_peer_cluster_manager_when_admin_assigns_it(): void
    {
        $clusterA = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $clusterB = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);

        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterA->id,
        ]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $manager->id,
            'cluster_id' => $clusterA->id,
        ]);

        $customer = Customer::factory()->create([
            'assign_to' => $manager->id,
            'journey_status' => 'underwriting',
        ]);

        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $clusterA->id,
            'acting_manager_id' => $clusterB->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Cluster Manager unavailable — emergency.',
            'is_admin_override' => true,
        ], $admin);

        $backupUser = User::factory()->create(['employee_id' => $clusterB->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($backupUser, $customer, JourneyModule::Approval);

        $this->assertTrue($decision->allowed);
    }
}
