<?php

namespace Tests\Feature\JourneyContinuity;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\JourneyTakeover;
use App\Models\User;
use App\Services\Journey\CustomerReassignmentService;
use App\Services\Journey\JourneyTakeoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Scenario 10 — the new mutating services lock the affected rows for the
 * duration of their transaction (mirroring HierarchyReassignmentService),
 * so two conflicting actions on the same customer serialize instead of
 * racing. A genuine multi-process race can't be exercised inside a single
 * PHPUnit process, so this proves the state-validation half of that
 * guarantee: a second, now-stale action is rejected rather than silently
 * double-applied.
 */
class JourneyConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_takeover_attempt_by_the_same_employee_on_the_same_customer_is_rejected(): void
    {
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $manager->id,
        ]);

        $customer = Customer::factory()->create(['assign_to' => $caller->id]);
        $performer = User::factory()->create();

        app(JourneyTakeoverService::class)->takeOver([
            'customer_id' => $customer->id,
            'takeover_type' => 'emergency',
            'reason' => 'First takeover.',
        ], $clusterManager->id, $performer->id);

        $this->expectException(ValidationException::class);

        // Same acting employee, same customer, while the first takeover is
        // still active — must not create a second concurrent grant.
        app(JourneyTakeoverService::class)->takeOver([
            'customer_id' => $customer->id,
            'takeover_type' => 'emergency',
            'reason' => 'Second attempt.',
        ], $clusterManager->id, $performer->id);
    }

    public function test_ending_an_already_ended_takeover_is_rejected(): void
    {
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $manager->id,
        ]);

        $customer = Customer::factory()->create(['assign_to' => $caller->id]);
        $performer = User::factory()->create();

        $takeover = app(JourneyTakeoverService::class)->takeOver([
            'customer_id' => $customer->id,
            'takeover_type' => 'emergency',
            'reason' => 'Only takeover.',
        ], $clusterManager->id, $performer->id);

        app(JourneyTakeoverService::class)->end($takeover, $performer->id);

        $this->assertSame(JourneyTakeover::STATUS_ENDED, $takeover->fresh()->status);

        $this->expectException(ValidationException::class);

        app(JourneyTakeoverService::class)->end($takeover, $performer->id);
    }

    public function test_reassigning_to_the_same_owner_twice_is_rejected_on_the_second_call(): void
    {
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $newOwner = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $manager->id,
        ]);

        $customer = Customer::factory()->create(['assign_to' => $caller->id]);
        $performer = User::factory()->create();

        app(CustomerReassignmentService::class)->reassign($customer, $newOwner->id, $performer->id, 'First reassignment.');

        $this->assertSame($newOwner->id, $customer->fresh()->assign_to);

        $this->expectException(ValidationException::class);

        // The customer is already assigned to $newOwner — a repeat call
        // must not silently no-op or double-write history.
        app(CustomerReassignmentService::class)->reassign($customer->fresh(), $newOwner->id, $performer->id, 'Repeat attempt.');
    }
}
