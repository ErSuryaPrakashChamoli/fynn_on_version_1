<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyAccessType;
use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JourneyTakeover;
use App\Models\User;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Services\Journey\JourneyTakeoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scenario 6 — an authorized Cluster Manager/Admin can take over a
 * customer's journey without ownership ever changing.
 */
class EmergencyTakeoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_cluster_manager_can_take_over_a_customer_and_gains_access(): void
    {
        $originalManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $originalManager->id,
        ]);

        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);

        $performer = User::factory()->create();

        $takeover = app(JourneyTakeoverService::class)->takeOver([
            'customer_id' => $customer->id,
            'takeover_type' => 'manager_resigned',
            'reason' => 'Manager resigned effective immediately.',
        ], $clusterManager->id, $performer->id);

        $this->assertSame(JourneyTakeover::STATUS_ACTIVE, $takeover->status);
        $this->assertSame($customer->id, $takeover->customer_id);

        // Ownership is untouched by the takeover itself.
        $this->assertSame($caller->id, $customer->fresh()->assign_to);

        $clusterUser = User::factory()->create(['employee_id' => $clusterManager->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($clusterUser, $customer, JourneyModule::Approval);

        $this->assertTrue($decision->allowed);
        $this->assertSame(JourneyAccessType::EmergencyTakeover, $decision->accessType);
        $this->assertSame($takeover->id, $decision->takeoverId);
    }

    public function test_admin_can_take_over_a_customer(): void
    {
        $originalManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $admin = Employee::factory()->create(['designation' => Employee::DESIGNATION_ADMIN]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $originalManager->id,
        ]);

        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);

        $performer = User::factory()->create();

        $takeover = app(JourneyTakeoverService::class)->takeOver([
            'customer_id' => $customer->id,
            'takeover_type' => 'emergency',
            'reason' => 'Urgent SLA breach.',
        ], $admin->id, $performer->id);

        $this->assertDatabaseHas('journey_takeovers', [
            'id' => $takeover->id,
            'customer_id' => $customer->id,
            'takeover_by_id' => $admin->id,
            'status' => JourneyTakeover::STATUS_ACTIVE,
        ]);
    }
}
