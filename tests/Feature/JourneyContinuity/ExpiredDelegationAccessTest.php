<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Models\CustomerJourneyDelegation;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scenario 3 — access must be revoked the instant end_at passes, regardless
 * of whether a background job has flipped the status column yet. The
 * access check re-verifies the time window itself every time.
 */
class ExpiredDelegationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_is_denied_once_end_at_has_passed_even_if_status_column_still_says_active(): void
    {
        $delegatingManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $actingManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $delegatingManager->id,
        ]);

        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);

        // Status column still says "active" — simulating a sync job that
        // hasn't run yet — but the window itself has already elapsed.
        CustomerJourneyDelegation::query()->create([
            'delegating_manager_id' => $delegatingManager->id,
            'acting_manager_id' => $actingManager->id,
            'start_at' => now()->subDays(3),
            'end_at' => now()->subDay(),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Manager on leave last week.',
            'status' => CustomerJourneyDelegation::STATUS_ACTIVE,
            'created_by' => User::factory()->create()->id,
        ]);

        $actingUser = User::factory()->create(['employee_id' => $actingManager->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($actingUser, $customer, JourneyModule::Approval);

        $this->assertFalse($decision->allowed);
    }

    public function test_access_is_denied_before_start_at_even_when_status_is_active(): void
    {
        $delegatingManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $actingManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $delegatingManager->id,
        ]);

        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);

        CustomerJourneyDelegation::query()->create([
            'delegating_manager_id' => $delegatingManager->id,
            'acting_manager_id' => $actingManager->id,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(3),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Upcoming planned leave.',
            'status' => CustomerJourneyDelegation::STATUS_ACTIVE,
            'created_by' => User::factory()->create()->id,
        ]);

        $actingUser = User::factory()->create(['employee_id' => $actingManager->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($actingUser, $customer, JourneyModule::Approval);

        $this->assertFalse($decision->allowed);
    }
}
