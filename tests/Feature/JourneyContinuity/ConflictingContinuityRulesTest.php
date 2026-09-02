<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyModule;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyDelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Section 27 / Rule 25 — overlapping continuity rules for the same
 * original employee must never be silently resolved by picking one; the
 * system must reject the conflicting rule outright.
 */
class ConflictingContinuityRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_overlapping_rule_for_the_same_original_employee_is_rejected(): void
    {
        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $original = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $backup1 = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $backup2 = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);

        $creator = User::factory()->create(['employee_id' => $original->id]);

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $original->id,
            'acting_manager_id' => $backup1->id,
            'start_at' => now(),
            'end_at' => now()->addDays(5),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Rahul → Amit, 01 Sep → 05 Sep.',
        ], $creator);

        $this->expectException(ValidationException::class);

        // Rahul → Suresh, overlapping window — must be rejected, not
        // silently prioritized.
        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $original->id,
            'acting_manager_id' => $backup2->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(7),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Rahul → Suresh, 03 Sep → 07 Sep.',
        ], $creator);
    }

    public function test_a_non_overlapping_rule_for_the_same_original_employee_is_allowed(): void
    {
        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $original = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $backup1 = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
        $backup2 = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);

        $creator = User::factory()->create(['employee_id' => $original->id]);

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $original->id,
            'acting_manager_id' => $backup1->id,
            'start_at' => now(),
            'end_at' => now()->addDays(5),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'First window.',
        ], $creator);

        $delegation = app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $original->id,
            'acting_manager_id' => $backup2->id,
            'start_at' => now()->addDays(6),
            'end_at' => now()->addDays(10),
            'modules' => [JourneyModule::Approval->value],
            'reason' => 'Second, non-overlapping window.',
        ], $creator);

        $this->assertNotNull($delegation->id);
    }
}
