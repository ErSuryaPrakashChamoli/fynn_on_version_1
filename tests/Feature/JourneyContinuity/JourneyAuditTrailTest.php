<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyAccessType;
use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Models\CustomerJourneyAudit;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyDelegationService;
use App\Services\Journey\JourneyTakeoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scenario 5 — every delegated or takeover action must write an immutable
 * CustomerJourneyAudit row recording the original owner and the acting
 * employee, with the correct access type.
 */
class JourneyAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_delegated_stage_change_writes_an_audit_row_with_correct_access_type(): void
    {
        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $delegatingManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $actingManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $delegatingManager->id,
        ]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $delegatingManager->id,
        ]);

        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);

        $performer = User::factory()->create(['employee_id' => $delegatingManager->id]);

        $delegation = app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $delegatingManager->id,
            'acting_manager_id' => $actingManager->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Manager unavailable.',
        ], $performer);

        $actingUser = User::factory()->create(['employee_id' => $actingManager->id]);
        $this->actingAs($actingUser);

        $customer->update(['journey_status' => 'approved']);

        $audit = CustomerJourneyAudit::query()->where('customer_id', $customer->id)->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertSame(JourneyAccessType::TemporaryDelegation, $audit->access_type);
        $this->assertSame($caller->id, $audit->original_owner_id);
        $this->assertSame($actingManager->id, $audit->acting_employee_id);
        $this->assertSame($delegation->id, $audit->delegation_id);
    }

    public function test_takeover_action_writes_an_audit_row_with_correct_access_type(): void
    {
        $originalManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $originalManager->id,
        ]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $originalManager->id,
        ]);

        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);

        $adminUser = User::factory()->create();

        $takeover = app(JourneyTakeoverService::class)->takeOver([
            'customer_id' => $customer->id,
            'takeover_type' => 'manager_unavailable',
            'reason' => 'Manager on emergency leave.',
        ], $clusterManager->id, $adminUser->id);

        $this->assertSame($originalManager->id, $takeover->original_manager_id);

        $clusterUser = User::factory()->create(['employee_id' => $clusterManager->id]);
        $this->actingAs($clusterUser);

        $customer->update(['journey_status' => 'approved']);

        $audit = CustomerJourneyAudit::query()->where('customer_id', $customer->id)->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertSame(JourneyAccessType::EmergencyTakeover, $audit->access_type);
        $this->assertSame($caller->id, $audit->original_owner_id);
        $this->assertSame($clusterManager->id, $audit->acting_employee_id);
        $this->assertSame($takeover->id, $audit->takeover_id);
    }
}
