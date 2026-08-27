<?php

namespace App\Services\Journey;

use App\Enums\JourneyAccessType;
use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Models\JourneyTakeover;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Emergency takeover of a single customer's Manager-stage actions. Grants
 * access to the initiating authorized user only for the given customer
 * (and optionally a subset of modules) — never mutates Customer::assign_to.
 * Who is allowed to call this is enforced by the caller via the
 * 'perform-journey-action'-adjacent role check (Admin/Cluster Manager/
 * Business Head), not re-derived here; this service re-validates shape and
 * state so a crafted request still can't bypass real constraints.
 */
class JourneyTakeoverService
{
    /**
     * @param  array{customer_id:int|string, takeover_type:string, reason:string, modules?:array<int,string>|null}  $data
     */
    public function takeOver(array $data, int $takeoverByEmployeeId, int $createdByUserId): JourneyTakeover
    {
        return DB::transaction(function () use ($data, $takeoverByEmployeeId, $createdByUserId): JourneyTakeover {
            $customerId = (int) ($data['customer_id'] ?? 0);
            $takeoverType = (string) ($data['takeover_type'] ?? '');
            $reason = trim((string) ($data['reason'] ?? ''));
            $modules = isset($data['modules']) && $data['modules'] !== null
                ? collect($data['modules'])
                    ->map(fn ($module): string => $module instanceof JourneyModule ? $module->value : (string) $module)
                    ->filter(fn (string $module): bool => in_array($module, array_column(JourneyModule::cases(), 'value'), true))
                    ->values()
                    ->all()
                : null;

            if ($customerId <= 0) {
                $this->validationError('customer_id', 'A customer must be selected.');
            }

            if (! in_array($takeoverType, JourneyTakeover::TYPES, true)) {
                $this->validationError('takeover_type', 'Select a valid takeover reason.');
            }

            if ($reason === '') {
                $this->validationError('reason', 'A reason is required for every emergency takeover.');
            }

            /** @var Customer|null $customer */
            $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->first();

            if (! $customer) {
                $this->validationError('customer_id', 'The selected customer does not exist.');
            }

            $alreadyActive = JourneyTakeover::query()
                ->where('customer_id', $customerId)
                ->where('takeover_by_id', $takeoverByEmployeeId)
                ->where('status', JourneyTakeover::STATUS_ACTIVE)
                ->exists();

            if ($alreadyActive) {
                $this->validationError('customer_id', 'You already have an active takeover on this customer.');
            }

            $accessService = app(CustomerJourneyAccessService::class);
            $originalManager = $accessService->naturalManagerFor($customer);

            /** @var JourneyTakeover $takeover */
            $takeover = JourneyTakeover::query()->create([
                'customer_id' => $customerId,
                'original_manager_id' => $originalManager?->id,
                'takeover_by_id' => $takeoverByEmployeeId,
                'takeover_type' => $takeoverType,
                'reason' => $reason,
                'modules' => $modules,
                'status' => JourneyTakeover::STATUS_ACTIVE,
                'started_at' => now(),
                'created_by' => $createdByUserId,
            ]);

            $accessService->recordAudit(
                customer: $customer,
                action: 'Emergency takeover started',
                accessType: JourneyAccessType::EmergencyTakeover,
                performedByUserId: $createdByUserId,
                actingEmployeeId: $takeoverByEmployeeId,
                takeoverId: $takeover->id,
                reason: $reason,
            );

            $this->logActivity($takeover, 'Emergency journey takeover started', [
                'customer_id' => $customerId,
                'takeover_type' => $takeoverType,
                'modules' => $modules,
            ]);

            return $takeover;
        }, 3);
    }

    public function end(JourneyTakeover $takeover, int $endedByUserId): JourneyTakeover
    {
        return DB::transaction(function () use ($takeover, $endedByUserId): JourneyTakeover {
            /** @var JourneyTakeover $takeover */
            $takeover = JourneyTakeover::query()->whereKey($takeover->id)->lockForUpdate()->firstOrFail();

            if ($takeover->status !== JourneyTakeover::STATUS_ACTIVE) {
                $this->validationError('status', 'This takeover is not currently active.');
            }

            $takeover->forceFill([
                'status' => JourneyTakeover::STATUS_ENDED,
                'ended_at' => now(),
                'ended_by' => $endedByUserId,
            ])->save();

            $this->logActivity($takeover, 'Emergency journey takeover ended', [
                'ended_by' => $endedByUserId,
            ]);

            return $takeover;
        }, 3);
    }

    private function validationError(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }

    private function logActivity(JourneyTakeover $takeover, string $description, array $properties): void
    {
        if (! function_exists('activity')) {
            return;
        }

        try {
            activity('journey')
                ->causedBy(auth()->user())
                ->performedOn($takeover)
                ->withProperties($properties)
                ->log($description);
        } catch (Throwable) {
            // The dedicated journey_takeovers table remains authoritative.
        }
    }
}
