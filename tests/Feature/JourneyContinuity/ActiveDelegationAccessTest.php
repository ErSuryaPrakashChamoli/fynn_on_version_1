<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyAccessType;
use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Services\Journey\CustomerJourneyDelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scenario 2 — an acting Manager with an active delegation can act only on
 * the delegated module(s), for customers under the delegating Manager.
 */
class ActiveDelegationAccessTest extends TestCase
{
    use RefreshDatabase;

    private Employee $delegatingManager;

    private Employee $actingManager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $this->delegatingManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $this->actingManager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);

        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->delegatingManager->id,
        ]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $this->delegatingManager->id,
        ]);

        $this->customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);
    }

    public function test_acting_manager_can_act_on_a_delegated_module(): void
    {
        $performer = User::factory()->create(['employee_id' => $this->delegatingManager->id]);

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $this->delegatingManager->id,
            'acting_manager_id' => $this->actingManager->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Manager unavailable this week.',
        ], $performer);

        $actingUser = User::factory()->create(['employee_id' => $this->actingManager->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($actingUser, $this->customer, JourneyModule::Approval);

        $this->assertTrue($decision->allowed);
        $this->assertSame(JourneyAccessType::TemporaryDelegation, $decision->accessType);
        $this->assertNotNull($decision->delegationId);
    }

    public function test_acting_manager_cannot_act_on_a_module_not_delegated(): void
    {
        $performer = User::factory()->create(['employee_id' => $this->delegatingManager->id]);

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $this->delegatingManager->id,
            'acting_manager_id' => $this->actingManager->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'modules' => [JourneyModule::DocumentVerification->value],
            'reason' => 'Manager unavailable this week.',
        ], $performer);

        $actingUser = User::factory()->create(['employee_id' => $this->actingManager->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($actingUser, $this->customer, JourneyModule::Approval);

        $this->assertFalse($decision->allowed);
    }

    public function test_a_third_manager_with_no_delegation_is_unaffected(): void
    {
        $performer = User::factory()->create(['employee_id' => $this->delegatingManager->id]);

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $this->delegatingManager->id,
            'acting_manager_id' => $this->actingManager->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Manager unavailable this week.',
        ], $performer);

        $thirdManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $thirdUser = User::factory()->create(['employee_id' => $thirdManager->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($thirdUser, $this->customer, JourneyModule::Approval);

        $this->assertFalse($decision->allowed);
    }
}
