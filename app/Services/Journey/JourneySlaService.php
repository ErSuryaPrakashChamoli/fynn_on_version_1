<?php

namespace App\Services\Journey;

use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Models\CustomerSlaBreach;
use App\Models\CustomerStageHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * SLA breach detection for the Manager stage. Notification-only: creating
 * or escalating a breach here never grants anyone access — a Cluster
 * Manager must still explicitly use Emergency Takeover. Thresholds are
 * configurable per module in config/journey_sla.php rather than hard-coded.
 */
class JourneySlaService
{
    /**
     * @return array{reminders:int, escalations:int, resolved:int}
     */
    public function checkBreaches(): array
    {
        $reminders = 0;
        $escalations = 0;

        $activeCustomers = self::activeCustomersQuery()->get();

        $reminderMinutes = config('journey_sla.reminder_minutes', []);
        $escalationMinutes = config('journey_sla.escalation_minutes', []);

        foreach ($activeCustomers as $customer) {
            $module = JourneyModule::forCustomer($customer);
            $stageEnteredAt = self::stageEnteredAt($customer);
            $minutesInStage = $stageEnteredAt->diffInMinutes(now());

            $reminderThreshold = (int) ($reminderMinutes[$module->value] ?? 60);
            $escalationThreshold = (int) ($escalationMinutes[$module->value] ?? 120);

            if ($minutesInStage < $reminderThreshold) {
                continue;
            }

            /** @var CustomerSlaBreach|null $breach */
            $breach = CustomerSlaBreach::query()
                ->where('customer_id', $customer->id)
                ->where('module', $module->value)
                ->where('status', CustomerSlaBreach::STATUS_OPEN)
                ->first();

            if (! $breach) {
                $breach = CustomerSlaBreach::query()->create([
                    'customer_id' => $customer->id,
                    'module' => $module->value,
                    'stage_entered_at' => $stageEnteredAt,
                    'reminder_sent_at' => now(),
                    'status' => CustomerSlaBreach::STATUS_OPEN,
                ]);

                $reminders++;

                Log::warning('Customer Journey SLA reminder', [
                    'customer_id' => $customer->id,
                    'module' => $module->value,
                    'minutes_in_stage' => $minutesInStage,
                ]);
            }

            if (! $breach->escalated_at && $minutesInStage >= $escalationThreshold) {
                $naturalManager = app(CustomerJourneyAccessService::class)->naturalManagerFor($customer);
                $clusterManagerId = $naturalManager?->cluster_id;

                $breach->forceFill([
                    'escalated_at' => now(),
                    'escalated_to_employee_id' => $clusterManagerId,
                ])->save();

                $escalations++;

                Log::critical('Customer Journey SLA escalated to Cluster Manager', [
                    'customer_id' => $customer->id,
                    'module' => $module->value,
                    'minutes_in_stage' => $minutesInStage,
                    'escalated_to_employee_id' => $clusterManagerId,
                ]);
            }
        }

        $resolved = $this->resolveStaleBreaches();

        return [
            'reminders' => $reminders,
            'escalations' => $escalations,
            'resolved' => $resolved,
        ];
    }

    /**
     * A customer that has moved past the module a breach was raised for no
     * longer needs that breach open.
     */
    private function resolveStaleBreaches(): int
    {
        $resolved = 0;

        CustomerSlaBreach::query()
            ->where('status', CustomerSlaBreach::STATUS_OPEN)
            ->with('customer')
            ->get()
            ->each(function (CustomerSlaBreach $breach) use (&$resolved): void {
                $customer = $breach->customer;

                if (! $customer || JourneyModule::forCustomer($customer)->value !== $breach->module) {
                    $breach->forceFill([
                        'status' => CustomerSlaBreach::STATUS_RESOLVED,
                        'resolved_at' => now(),
                    ])->save();

                    $resolved++;
                }
            });

        return $resolved;
    }

    /**
     * Customers still in-flight (not yet disbursed/dropped) — i.e. the
     * population the SLA detector and the Pending Manager Cases queue both
     * operate over. Shared here so both agree on the same definition of
     * "still active" and don't drift apart over time.
     */
    public static function activeCustomersQuery(): Builder
    {
        return Customer::query()
            ->where('disbursal_finalized', false)
            ->where(function ($query) {
                // whereNotIn alone silently excludes NULL rows in SQL — most
                // customers have not reached disbursal yet, so disbursal_status
                // is null and must still count as "active".
                $query->whereNull('disbursal_status')
                    ->orWhereNotIn('disbursal_status', ['disbursed', 'dropped']);
            });
    }

    /**
     * When did this customer enter its current journey_status? Shared with
     * PendingManagerCasesTable so the "waiting since" column and the SLA
     * breach detector agree on the same clock.
     */
    public static function stageEnteredAt(Customer $customer): Carbon
    {
        $latestStage = CustomerStageHistory::query()
            ->where('customer_id', $customer->id)
            ->latest('created_at')
            ->first();

        return $latestStage?->created_at ?? $customer->created_at ?? now();
    }
}
