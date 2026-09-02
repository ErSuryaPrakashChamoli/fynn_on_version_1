<?php

namespace App\Services\Journey;

use App\Enums\JourneyAccessType;
use App\Models\Customer;
use App\Models\CustomerReassignment;
use App\Models\Employee;
use App\Support\HierarchyHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The only sanctioned path (besides the Admin-only form field) that ever
 * mutates Customer::assign_to going forward. Every mutation writes a
 * CustomerReassignment history row first — prior CustomerStageHistory,
 * CustomerJourneyAudit, and Spatie activity-log rows referencing the old
 * owner are never touched, preserving historical ownership records.
 */
class CustomerReassignmentService
{
    public function reassign(Customer $customer, int $newOwnerEmployeeId, int $performedByUserId, string $reason): CustomerReassignment
    {
        return DB::transaction(function () use ($customer, $newOwnerEmployeeId, $performedByUserId, $reason): CustomerReassignment {
            /** @var Customer $customer */
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $reason = trim($reason);

            if ($reason === '') {
                $this->validationError('reason', 'A reason is required to reassign a customer.');
            }

            /** @var Employee|null $newOwner */
            $newOwner = Employee::query()
                ->whereKey($newOwnerEmployeeId)
                ->whereIn('designation', [Employee::DESIGNATION_MANAGER, Employee::DESIGNATION_TEAM_LEADER])
                ->where('exit_status', '!=', 'yes')
                ->lockForUpdate()
                ->first();

            if (! $newOwner) {
                $this->validationError('new_owner_id', 'The selected new owner does not exist or is not an active Manager/Team Leader.');
            }

            if ((int) $customer->assign_to === $newOwner->id) {
                $this->validationError('new_owner_id', 'This customer is already assigned to that employee.');
            }

            $previousOwnerId = $customer->assign_to;

            $customer->forceFill(['assign_to' => $newOwner->id])->save();

            /** @var CustomerReassignment $history */
            $history = CustomerReassignment::query()->create([
                'customer_id' => $customer->id,
                'previous_owner_id' => $previousOwnerId,
                'new_owner_id' => $newOwner->id,
                'reassigned_by' => $performedByUserId,
                'reason' => $reason,
                'reassigned_at' => now(),
            ]);

            app(CustomerJourneyAccessService::class)->recordAudit(
                customer: $customer,
                action: 'Customer permanently reassigned',
                accessType: JourneyAccessType::PermanentReassignment,
                performedByUserId: $performedByUserId,
                actingEmployeeId: $newOwner->id,
                reason: $reason,
            );

            $this->logActivity($history, $customer, $previousOwnerId, $newOwner->id, $reason);

            return $history;
        }, 3);
    }

    /**
     * Manager-exit helper: bulk reassign every customer currently owned
     * (directly or via a Team Leader beneath them) by an outgoing Manager
     * to a target Manager. Returns the created CustomerReassignment rows.
     *
     * @return Collection<int, CustomerReassignment>
     */
    public function reassignAllForOutgoingManager(Employee $outgoingManager, Employee $targetManager, int $performedByUserId, string $reason): Collection
    {
        if ($outgoingManager->id === $targetManager->id) {
            $this->validationError('target_manager_id', 'Source and target Manager must be different.');
        }

        $customerIds = Customer::query()
            ->whereIn('assign_to', HierarchyHelper::subordinateIds($outgoingManager))
            ->pluck('id');

        return $customerIds->map(
            fn (int $customerId): CustomerReassignment => $this->reassign(
                Customer::query()->findOrFail($customerId),
                $targetManager->id,
                $performedByUserId,
                $reason,
            )
        );
    }

    private function validationError(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }

    private function logActivity(CustomerReassignment $history, Customer $customer, ?int $previousOwnerId, int $newOwnerId, string $reason): void
    {
        if (! function_exists('activity')) {
            return;
        }

        try {
            activity('journey')
                ->causedBy(auth()->user())
                ->performedOn($history)
                ->withProperties([
                    'customer_id' => $customer->id,
                    'previous_owner_id' => $previousOwnerId,
                    'new_owner_id' => $newOwnerId,
                    'reason' => $reason,
                ])
                ->log('Customer reassigned to a new owner');
        } catch (Throwable) {
            // The dedicated customer_reassignments table remains authoritative.
        }
    }
}
