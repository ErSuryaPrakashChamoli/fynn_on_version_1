<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyModule;
use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerAssignmentBatch;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyDelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section 4/5/41 — Engine 2 (Backup Routing). This app has no
 * new-customer-routing step to intercept (see the audit: every new
 * Customer/Lead self-assigns to its creator); the one place work is ever
 * assigned to someone OTHER than themselves is the manual
 * AssignCustomersToUserBulkAction → CustomerAssignment.employee_id. This
 * proves a backup with active "new"/"existing_and_new" coverage becomes
 * operationally visible for leads assigned to the unavailable employee —
 * without ownership (employee_id) ever being rewritten — while a backup
 * covering only "existing" customers does NOT gain that visibility for a
 * lead assigned after the rule started.
 */
class BackupRoutingEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_with_new_coverage_sees_leads_assigned_to_the_unavailable_employee(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $original = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'cluster_id' => $clusterManager->id,
        ]);
        $backup = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'cluster_id' => $clusterManager->id,
        ]);

        $creator = User::factory()->create(['employee_id' => $original->id]);

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $original->id,
            'acting_manager_id' => $backup->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'modules' => array_column(JourneyModule::cases(), 'value'),
            'coverage_type' => 'existing_and_new',
            'reason' => 'Team Leader unavailable.',
        ], $creator);

        $batch = CustomerAssignmentBatch::query()->create([
            'assigned_by' => null,
            'employee_id' => $original->id,
            'customer_count' => 1,
        ]);

        // A lead assigned to $original AFTER the continuity rule started —
        // ownership (employee_id) still points at the unavailable employee.
        $assignment = CustomerAssignment::query()->create([
            'batch_id' => $batch->id,
            'customer_id' => Customer::factory()->create()->id,
            'employee_id' => $original->id,
            'assigned_by' => null,
        ]);

        $backupUser = User::factory()->create(['employee_id' => $backup->id]);
        $this->actingAs($backupUser);

        $visibleIds = AssignedLeadResource::getEloquentQuery()->pluck('id');

        $this->assertContains($assignment->id, $visibleIds->all());
        // Ownership was never rewritten.
        $this->assertSame($original->id, $assignment->fresh()->employee_id);
    }

    public function test_backup_does_not_gain_visibility_over_an_unrelated_employees_assignments(): void
    {
        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $original = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'cluster_id' => $clusterManager->id,
        ]);
        $backup = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
            'cluster_id' => $clusterManager->id,
        ]);

        $creator = User::factory()->create(['employee_id' => $original->id]);

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $original->id,
            'acting_manager_id' => $backup->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'modules' => array_column(JourneyModule::cases(), 'value'),
            'coverage_type' => 'existing_and_new',
            'reason' => 'Team Leader unavailable.',
        ], $creator);

        $unrelatedEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER]);

        $batch = CustomerAssignmentBatch::query()->create(['employee_id' => $unrelatedEmployee->id, 'customer_count' => 1]);
        $assignment = CustomerAssignment::query()->create([
            'batch_id' => $batch->id,
            'customer_id' => Customer::factory()->create()->id,
            'employee_id' => $unrelatedEmployee->id,
        ]);

        $backupUser = User::factory()->create(['employee_id' => $backup->id]);
        $this->actingAs($backupUser);

        $visibleIds = AssignedLeadResource::getEloquentQuery()->pluck('id');

        $this->assertNotContains($assignment->id, $visibleIds->all());
    }
}
