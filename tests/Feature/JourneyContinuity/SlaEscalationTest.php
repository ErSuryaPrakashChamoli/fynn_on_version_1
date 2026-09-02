<?php

namespace Tests\Feature\JourneyContinuity;

use App\Models\Customer;
use App\Models\CustomerSlaBreach;
use App\Models\Employee;
use App\Services\Journey\JourneySlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scenario 9 — an unprocessed Manager-stage customer is detected, reminded,
 * and escalated to the Cluster Manager. This never grants access by
 * itself — it only raises a breach record a Cluster Manager can act on via
 * Emergency Takeover.
 */
class SlaEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_stuck_past_the_escalation_threshold_is_reminded_and_escalated(): void
    {
        config()->set('journey_sla.reminder_minutes.document_verification', 30);
        config()->set('journey_sla.escalation_minutes.document_verification', 60);

        $clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $clusterManager->id,
        ]);
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
            'journey_status' => 'sfl',
            'documents_submitted' => false,
        ]);

        // No CustomerStageHistory row exists yet, so stage-entered-at falls
        // back to created_at — push it safely past the escalation threshold.
        $customer->forceFill(['created_at' => now()->subMinutes(90)])->saveQuietly();

        $result = app(JourneySlaService::class)->checkBreaches();

        $this->assertSame(1, $result['reminders']);
        $this->assertSame(1, $result['escalations']);

        $breach = CustomerSlaBreach::query()->where('customer_id', $customer->id)->first();

        $this->assertNotNull($breach);
        $this->assertSame(CustomerSlaBreach::STATUS_OPEN, $breach->status);
        $this->assertNotNull($breach->reminder_sent_at);
        $this->assertNotNull($breach->escalated_at);
        $this->assertSame($clusterManager->id, $breach->escalated_to_employee_id);
    }

    public function test_a_customer_within_sla_raises_no_breach(): void
    {
        config()->set('journey_sla.reminder_minutes.document_verification', 30);

        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $manager->id,
        ]);

        Customer::factory()->create([
            'assign_to' => $caller->id,
            'journey_status' => 'sfl',
            'documents_submitted' => false,
        ]);

        $result = app(JourneySlaService::class)->checkBreaches();

        $this->assertSame(0, $result['reminders']);
        $this->assertSame(0, $result['escalations']);
        $this->assertSame(0, CustomerSlaBreach::query()->count());
    }
}
