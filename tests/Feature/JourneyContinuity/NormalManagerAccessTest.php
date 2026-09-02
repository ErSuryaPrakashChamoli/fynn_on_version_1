<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyAccessType;
use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scenario 1 — baseline: the existing hierarchy-based access rule must keep
 * working exactly as before this feature, with zero delegations/takeovers
 * in play.
 */
class NormalManagerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_has_normal_access_to_a_customer_under_their_hierarchy(): void
    {
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $manager->id,
        ]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
        ]);

        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);

        $user = User::factory()->create(['employee_id' => $manager->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($user, $customer, JourneyModule::Approval);

        $this->assertTrue($decision->allowed);
        $this->assertSame(JourneyAccessType::Normal, $decision->accessType);
    }

    public function test_caller_never_has_normal_manager_stage_access(): void
    {
        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);
        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);

        $user = User::factory()->create(['employee_id' => $caller->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($user, $customer, JourneyModule::Approval);

        $this->assertFalse($decision->allowed);
    }

    public function test_unrelated_manager_outside_the_hierarchy_is_denied(): void
    {
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);

        $user = User::factory()->create(['employee_id' => $manager->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($user, $customer, JourneyModule::Approval);

        $this->assertFalse($decision->allowed);
    }
}
