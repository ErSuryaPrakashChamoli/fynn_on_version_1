<?php

namespace Tests\Feature\JourneyContinuity;

use App\Models\Customer;
use App\Models\CustomerStageHistory;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerReassignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Scenario 8 — customers can be permanently reassigned when a Manager
 * exits, without destroying historical ownership/action records.
 */
class ManagerExitReassignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_reassignment_moves_customers_but_preserves_prior_history(): void
    {
        $outgoingManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $targetManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $outgoingManager->id,
        ]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $teamLeader->id,
            'manager_id' => $outgoingManager->id,
        ]);

        $customer = Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'underwriting',
        ]);

        // Pre-existing history that must survive the reassignment untouched.
        $priorHistory = CustomerStageHistory::create([
            'customer_id' => $customer->id,
            'stage_name' => 'Sfl Stage',
            'status_value' => 'Moved to Underwriting',
            'user_id' => User::factory()->create()->id,
        ]);

        $performer = User::factory()->create();

        $results = app(CustomerReassignmentService::class)->reassignAllForOutgoingManager(
            $outgoingManager,
            $targetManager,
            $performer->id,
            'Manager exited the company.',
        );

        $this->assertCount(1, $results);

        $customer->refresh();

        $this->assertSame($targetManager->id, $customer->assign_to);

        $this->assertDatabaseHas('customer_reassignments', [
            'customer_id' => $customer->id,
            'previous_owner_id' => $caller->id,
            'new_owner_id' => $targetManager->id,
            'reassigned_by' => $performer->id,
        ]);

        // Prior stage history is untouched — historical ownership at the
        // time of that action is preserved exactly as it was.
        $this->assertDatabaseHas('customer_stage_histories', [
            'id' => $priorHistory->id,
            'customer_id' => $customer->id,
            'stage_name' => 'Sfl Stage',
            'status_value' => 'Moved to Underwriting',
        ]);
    }

    public function test_single_customer_reassignment_requires_a_reason(): void
    {
        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $newOwner = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $manager->id,
        ]);

        $customer = Customer::factory()->create(['assign_to' => $caller->id]);
        $performer = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(CustomerReassignmentService::class)->reassign($customer, $newOwner->id, $performer->id, '');
    }
}
