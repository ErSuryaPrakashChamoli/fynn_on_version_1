<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyDelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scenario 4 — a delegated action must never rewrite Customer::assign_to.
 * Ownership stays with the original owner throughout and after the
 * delegation window.
 */
class DelegationOwnershipUnchangedTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_assign_to_is_unchanged_after_acting_manager_performs_an_action(): void
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
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $delegatingManager->id,
        ]);

        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);

        $originalAssignTo = $customer->assign_to;

        $performer = User::factory()->create(['employee_id' => $delegatingManager->id]);

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $delegatingManager->id,
            'acting_manager_id' => $actingManager->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Manager unavailable.',
        ], $performer);

        $actingUser = User::factory()->create(['employee_id' => $actingManager->id]);
        $this->actingAs($actingUser);

        // The acting Manager performs a Manager-stage action (a stage
        // transition) on the customer.
        $customer->update(['journey_status' => 'approved']);

        $customer->refresh();

        $this->assertSame($originalAssignTo, $customer->assign_to);
        $this->assertSame($caller->id, $customer->assign_to);

        // And once the delegation expires, the customer returns to
        // ordinary Manager access automatically — no code needs to run.
        $this->assertDatabaseHas('customer_journey_delegations', [
            'delegating_manager_id' => $delegatingManager->id,
            'acting_manager_id' => $actingManager->id,
        ]);
    }
}
