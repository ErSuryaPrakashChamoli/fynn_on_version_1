<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyModule;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyDelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sections 12/13/15 — a normal user must not be able to assign continuity
 * access outside their permitted hierarchy, server-side, regardless of
 * what the UI would have offered as options.
 */
class HierarchyRestrictedBackupSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_cannot_nominate_a_backup_from_an_unrelated_cluster(): void
    {
        $clusterA = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $clusterB = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);

        $rahul = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterA->id,
        ]);
        $amit = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterB->id,
        ]);

        $rahulUser = User::factory()->create(['employee_id' => $rahul->id]);

        $this->expectException(ValidationException::class);

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $rahul->id,
            'acting_manager_id' => $amit->id,
            'start_at' => now(),
            'end_at' => now()->addDay(),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Manager unavailable.',
        ], $rahulUser);
    }

    public function test_a_manager_cannot_create_coverage_for_an_employee_outside_their_own_hierarchy(): void
    {
        $clusterA = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $clusterB = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);

        $requestingManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterA->id,
        ]);
        $unrelatedManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterB->id,
        ]);
        $unrelatedBackup = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterB->id,
        ]);

        $requestingUser = User::factory()->create(['employee_id' => $requestingManager->id]);

        $this->expectException(ValidationException::class);

        // requestingManager tries to create coverage FOR unrelatedManager
        // (someone outside their own branch) — must be rejected even
        // though the backup would otherwise be eligible within THAT
        // manager's own cluster.
        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $unrelatedManager->id,
            'acting_manager_id' => $unrelatedBackup->id,
            'start_at' => now(),
            'end_at' => now()->addDay(),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Attempting cross-hierarchy assignment.',
        ], $requestingUser);
    }

    public function test_admin_can_assign_a_backup_from_any_cluster(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $clusterA = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $clusterB = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);

        $rahul = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterA->id,
        ]);
        $amit = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterB->id,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $delegation = app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $rahul->id,
            'acting_manager_id' => $amit->id,
            'start_at' => now(),
            'end_at' => now()->addDay(),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Emergency — no local backup available.',
            'is_admin_override' => true,
        ], $admin);

        $this->assertSame($rahul->id, $delegation->delegating_manager_id);
        $this->assertSame($amit->id, $delegation->acting_manager_id);
        $this->assertTrue($delegation->is_admin_override);

        // Rule 12/17: the org hierarchy itself must never change.
        $this->assertSame($clusterA->id, $rahul->fresh()->cluster_id);
        $this->assertSame($clusterB->id, $amit->fresh()->cluster_id);
    }
}
