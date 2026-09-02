<?php

namespace Tests\Feature\JourneyContinuity;

use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\Journey\CustomerJourneyAccessService;
use App\Services\Journey\CustomerJourneyDelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Sections 6-8, 41-42 — Existing / New / Existing+New coverage must be
 * separately identifiable and enforced (via each rule's start_at vs the
 * customer's created_at), even though both are resolved by the same live
 * access check rather than two separate engines.
 */
class CoverageTypeTest extends TestCase
{
    use RefreshDatabase;

    private Employee $clusterManager;

    private Employee $original;

    private Employee $backup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $this->original = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $this->clusterManager->id,
        ]);
        $this->backup = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $this->clusterManager->id,
        ]);
    }

    public function test_existing_only_coverage_does_not_apply_to_a_customer_created_after_the_rule_started(): void
    {
        $ruleStart = now()->subDay();

        $this->createRule('existing', $ruleStart);

        // Created AFTER the rule's start_at — a "new" case, not covered by
        // an existing-only rule.
        $customer = Customer::factory()->create([
            'assign_to' => $this->original->id,
            'journey_status' => 'underwriting',
        ]);

        $backupUser = User::factory()->create(['employee_id' => $this->backup->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($backupUser, $customer, JourneyModule::Approval);

        $this->assertFalse($decision->allowed);
    }

    public function test_existing_only_coverage_applies_to_a_customer_created_before_the_rule_started(): void
    {
        $ruleStart = now()->subDay();

        $customer = Customer::factory()->create([
            'assign_to' => $this->original->id,
            'journey_status' => 'underwriting',
        ]);
        $customer->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $this->createRule('existing', $ruleStart);

        $backupUser = User::factory()->create(['employee_id' => $this->backup->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($backupUser, $customer->fresh(), JourneyModule::Approval);

        $this->assertTrue($decision->allowed);
    }

    public function test_new_only_coverage_does_not_apply_to_a_customer_created_before_the_rule_started(): void
    {
        $ruleStart = now()->subHour();

        $customer = Customer::factory()->create([
            'assign_to' => $this->original->id,
            'journey_status' => 'underwriting',
        ]);
        $customer->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $this->createRule('new', $ruleStart);

        $backupUser = User::factory()->create(['employee_id' => $this->backup->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($backupUser, $customer->fresh(), JourneyModule::Approval);

        $this->assertFalse($decision->allowed);
    }

    public function test_new_only_coverage_applies_to_a_customer_created_during_the_rule_window(): void
    {
        $ruleStart = now()->subHour();

        $this->createRule('new', $ruleStart);

        // Created after the rule started — this is the "new customer
        // created while the responsible employee is unavailable" edge case
        // from section 41, resolved automatically without a separate
        // routing pass.
        $customer = Customer::factory()->create([
            'assign_to' => $this->original->id,
            'journey_status' => 'underwriting',
        ]);

        $backupUser = User::factory()->create(['employee_id' => $this->backup->id]);

        $decision = app(CustomerJourneyAccessService::class)
            ->decide($backupUser, $customer, JourneyModule::Approval);

        $this->assertTrue($decision->allowed);
    }

    public function test_existing_and_new_coverage_applies_to_both(): void
    {
        $ruleStart = now()->subDay();

        $oldCustomer = Customer::factory()->create([
            'assign_to' => $this->original->id,
            'journey_status' => 'underwriting',
        ]);
        $oldCustomer->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $this->createRule('existing_and_new', $ruleStart);

        $newCustomer = Customer::factory()->create([
            'assign_to' => $this->original->id,
            'journey_status' => 'underwriting',
        ]);

        $backupUser = User::factory()->create(['employee_id' => $this->backup->id]);
        $accessService = app(CustomerJourneyAccessService::class);

        $this->assertTrue($accessService->decide($backupUser, $oldCustomer->fresh(), JourneyModule::Approval)->allowed);
        $this->assertTrue($accessService->decide($backupUser, $newCustomer, JourneyModule::Approval)->allowed);
    }

    private function createRule(string $coverageType, Carbon $startAt): void
    {
        $creator = User::factory()->create(['employee_id' => $this->original->id]);

        app(CustomerJourneyDelegationService::class)->create([
            'delegating_manager_id' => $this->original->id,
            'acting_manager_id' => $this->backup->id,
            'start_at' => $startAt,
            'end_at' => now()->addDays(5),
            'modules' => [JourneyModule::Approval->value],
            'coverage_type' => $coverageType,
            'reason' => 'Planned absence.',
        ], $creator);
    }
}
