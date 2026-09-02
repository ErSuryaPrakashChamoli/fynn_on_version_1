<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyAccessType;
use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Models\CustomerJourneyAudit;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyDelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sections 16-19, 32 — the organisation-wide bypass is Admin-only,
 * hard-enforced server-side (never trusted from a request flag alone), and
 * produces enhanced audit information when used.
 */
class AdminOrganisationWideHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_set_the_admin_override_flag_even_with_a_valid_hierarchy(): void
    {
        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $peerManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);

        $clusterManagerUser = User::factory()->create(['employee_id' => $clusterManager->id]);

        $this->expectException(ValidationException::class);

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $manager->id,
            'acting_manager_id' => $peerManager->id,
            'start_at' => now(),
            'end_at' => now()->addDay(),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Attempting to claim org-wide authority.',
            'is_admin_override' => true,
        ], $clusterManagerUser);
    }

    public function test_admin_handover_across_clusters_records_enhanced_audit_information(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $clusterA = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER, 'emp_name' => 'Cluster A Head']);
        $clusterB = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER, 'emp_name' => 'Cluster B Head']);

        $rahul = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterA->id,
            'emp_name' => 'Rahul Sharma',
        ]);
        $amit = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterB->id,
            'emp_name' => 'Amit Verma',
        ]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $rahul->id,
            'cluster_id' => $clusterA->id,
        ]);

        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $rahul->id,
            'acting_manager_id' => $amit->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDays(5),
            'modules' => [JourneyModule::Approval->value],
            'coverage_type' => 'existing_and_new',
            'reason' => 'Manager Unavailable',
            'is_admin_override' => true,
        ], $admin);

        $amitUser = User::factory()->create(['employee_id' => $amit->id]);
        $this->actingAs($amitUser);

        $customer->update(['journey_status' => 'approved']);

        $audit = CustomerJourneyAudit::query()->where('customer_id', $customer->id)->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertSame(JourneyAccessType::AdminOrganisationWideHandover, $audit->access_type);
        $this->assertTrue($audit->is_admin_override);
        $this->assertSame('Rahul Sharma', $audit->original_hierarchy['name']);
        $this->assertSame('Amit Verma', $audit->backup_hierarchy['name']);

        // Rule 12: permanent ownership/hierarchy never changes.
        $this->assertSame($caller->id, $customer->fresh()->assign_to);
        $this->assertSame($clusterA->id, $rahul->fresh()->cluster_id);
        $this->assertSame($clusterB->id, $amit->fresh()->cluster_id);
    }
}
