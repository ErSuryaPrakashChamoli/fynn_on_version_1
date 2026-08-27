<?php

namespace App\Services\Journey;

use App\Enums\ContinuityCoverageType;
use App\Enums\ContinuityScopeType;
use App\Enums\JourneyAccessType;
use App\Enums\JourneyModule;
use App\Models\CustomerJourneyDelegation;
use App\Models\Employee;
use App\Models\User;
use App\Support\HierarchyHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates/approves/cancels Team Continuity / Backup Access rules — any
 * hierarchy level, not just Managers. Mirrors the transaction/locking/audit
 * idiom of HierarchyReassignmentService: this table is the authoritative
 * record, Spatie activity logging is best-effort.
 */
class CustomerJourneyDelegationService
{
    /**
     * @param  array{
     *     delegating_manager_id:int|string,
     *     acting_manager_id:int|string,
     *     start_at:string,
     *     end_at:string,
     *     modules:array<int,string>,
     *     coverage_type?:string,
     *     scope_type?:string,
     *     access_type?:string,
     *     is_admin_override?:bool,
     *     reason:string,
     * }  $data
     */
    public function create(array $data, User $creator): CustomerJourneyDelegation
    {
        return DB::transaction(function () use ($data, $creator): CustomerJourneyDelegation {
            $originalId = (int) ($data['delegating_manager_id'] ?? 0);
            $backupId = (int) ($data['acting_manager_id'] ?? 0);
            $startAt = Carbon::parse($data['start_at'] ?? null);
            $endAt = Carbon::parse($data['end_at'] ?? null);
            $modules = collect($data['modules'] ?? [])
                ->map(fn ($module): string => $module instanceof JourneyModule ? $module->value : (string) $module)
                ->filter(fn (string $module): bool => in_array($module, array_column(JourneyModule::cases(), 'value'), true))
                ->unique()
                ->values();
            $reason = trim((string) ($data['reason'] ?? ''));
            $coverageType = ContinuityCoverageType::tryFrom((string) ($data['coverage_type'] ?? '')) ?? ContinuityCoverageType::ExistingAndNew;
            $scopeType = ContinuityScopeType::tryFrom((string) ($data['scope_type'] ?? '')) ?? ContinuityScopeType::HierarchyBranch;
            $isAdminOverride = (bool) ($data['is_admin_override'] ?? false);

            // is_admin_override implies its access type definitionally —
            // derived here (the actual authorization boundary), not left to
            // whichever caller happens to also pass the right access_type.
            $accessType = $isAdminOverride
                ? JourneyAccessType::AdminOrganisationWideHandover
                : (JourneyAccessType::tryFrom((string) ($data['access_type'] ?? '')) ?? JourneyAccessType::TemporaryDelegation);

            if ($originalId <= 0 || $backupId <= 0) {
                $this->validationError('acting_manager_id', 'Both the original and backup employee are required.');
            }

            if ($originalId === $backupId) {
                $this->validationError('acting_manager_id', 'You cannot assign an employee as their own backup.');
            }

            if ($modules->isEmpty()) {
                $this->validationError('modules', 'Select at least one module to cover.');
            }

            if ($reason === '') {
                $this->validationError('reason', 'A reason is required.');
            }

            if (! $endAt->isValid() || ! $startAt->isValid() || $endAt->lessThanOrEqualTo($startAt)) {
                $this->validationError('end_at', 'End date/time must be after the start date/time.');
            }

            // RULE 19 / section 19: the organisation-wide bypass is
            // Admin-only, hard-enforced here regardless of what the caller
            // (Filament form, direct service call, anything) claims —
            // never trust a boolean coming from the request alone.
            if ($isAdminOverride && ! $creator->hasRole('Admin')) {
                $this->validationError('is_admin_override', 'Only Admin can perform an organisation-wide handover.');
            }

            /** @var Employee|null $original */
            $original = Employee::query()
                ->whereKey($originalId)
                ->where('exit_status', '!=', 'yes')
                ->lockForUpdate()
                ->first();

            /** @var Employee|null $backup */
            $backup = Employee::query()
                ->whereKey($backupId)
                ->where('exit_status', '!=', 'yes')
                ->lockForUpdate()
                ->first();

            if (! $original) {
                $this->validationError('delegating_manager_id', 'The original employee does not exist or is inactive.');
            }

            if (! $backup) {
                $this->validationError('acting_manager_id', 'The backup employee does not exist or is inactive.');
            }

            $this->assertCreatorAuthorized($creator, $original, $backup, $isAdminOverride);

            $overlapExists = CustomerJourneyDelegation::query()
                ->where('delegating_manager_id', $originalId)
                ->whereIn('status', [CustomerJourneyDelegation::STATUS_PENDING, CustomerJourneyDelegation::STATUS_ACTIVE])
                ->where('start_at', '<', $endAt)
                ->where('end_at', '>', $startAt)
                ->exists();

            if ($overlapExists) {
                // Section 27 / Rule 25: never silently pick a winner between
                // two overlapping rules for the same original employee.
                $this->validationError('start_at', 'An overlapping continuity rule already exists for this employee and window. Cancel or adjust the existing rule first.');
            }

            $requiresApproval = (bool) config('journey_sla.delegation_requires_approval', false)
                && $accessType === JourneyAccessType::TemporaryDelegation;

            /** @var CustomerJourneyDelegation $delegation */
            $delegation = CustomerJourneyDelegation::query()->create([
                'delegating_manager_id' => $originalId,
                'acting_manager_id' => $backupId,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'modules' => $modules->all(),
                'coverage_type' => $coverageType,
                'scope_type' => $scopeType,
                'access_type' => $accessType,
                'is_admin_override' => $isAdminOverride,
                'reason' => $reason,
                'status' => $requiresApproval ? CustomerJourneyDelegation::STATUS_PENDING : CustomerJourneyDelegation::STATUS_ACTIVE,
                'requires_approval' => $requiresApproval,
                'created_by' => $creator->id,
            ]);

            $this->logActivity($delegation, 'Team Continuity backup access created', [
                'delegating_manager_id' => $originalId,
                'acting_manager_id' => $backupId,
                'modules' => $modules->all(),
                'coverage_type' => $coverageType->value,
                'access_type' => $accessType->value,
                'is_admin_override' => $isAdminOverride,
            ]);

            return $delegation;
        }, 3);
    }

    /**
     * Section 12/13/15: normal users may only create continuity rules
     * within their permitted hierarchy — the original employee must be
     * someone they have oversight of (a superior, or themselves), and the
     * backup must be within the ORIGINAL employee's own branch (their
     * up-chain of superiors + their subordinate tree), which naturally
     * allows a superior-or-junior backup while blocking an arbitrary
     * cross-cluster pick. Admin (with is_admin_override) bypasses both.
     */
    private function assertCreatorAuthorized(User $creator, Employee $original, Employee $backup, bool $isAdminOverride): void
    {
        if ($creator->hasRole('Admin')) {
            // Admin already has unconditional access everywhere else in
            // this app; is_admin_override is for audit labeling (section
            // 32) and to hard-block non-admins from setting it, not the
            // only path to Admin's existing omnipotence.
            return;
        }

        if ($isAdminOverride) {
            // Already blocked above for non-admins, but never fall through
            // to a weaker check if that guard is ever changed.
            $this->validationError('is_admin_override', 'Only Admin can perform an organisation-wide handover.');
        }

        $creatorEmployee = $creator->employee;

        if (! $creatorEmployee) {
            $this->validationError('delegating_manager_id', 'Your account has no employee profile linked, so you cannot create continuity rules.');
        }

        // Business Head sits above the Employee hierarchy tree in this app
        // (a Spatie role, not an Employee::designation level) — it has no
        // superviser_id/manager_id/cluster_id slot to structurally test
        // "superior of $original" against, so that half of the check is
        // skipped for it specifically. The backup-must-stay-within-the-
        // original's-own-branch restriction below still applies regardless
        // — Business Head is senior enough to nominate across its own
        // reporting lines, not an org-wide bypass like Admin.
        if (! $creator->hasRole('Business Head')) {
            $creatorIsOriginal = $creatorEmployee->id === $original->id;
            $creatorIsSuperiorOfOriginal = HierarchyHelper::subordinateIds($creatorEmployee)->contains($original->id);

            if (! $creatorIsOriginal && ! $creatorIsSuperiorOfOriginal) {
                $this->validationError('delegating_manager_id', 'You can only create continuity coverage for yourself or an employee within your own hierarchy.');
            }
        }

        $backupWithinOriginalBranch = HierarchyHelper::employeeHierarchyIds($original)->contains($backup->id);

        if (! $backupWithinOriginalBranch) {
            $this->validationError('acting_manager_id', 'The backup employee must be within the original employee\'s own hierarchy branch. Ask Admin for an organisation-wide handover if no suitable backup exists there.');
        }
    }

    public function approve(CustomerJourneyDelegation $delegation, int $approvedByUserId): CustomerJourneyDelegation
    {
        return DB::transaction(function () use ($delegation, $approvedByUserId): CustomerJourneyDelegation {
            /** @var CustomerJourneyDelegation $delegation */
            $delegation = CustomerJourneyDelegation::query()->whereKey($delegation->id)->lockForUpdate()->firstOrFail();

            if ($delegation->status !== CustomerJourneyDelegation::STATUS_PENDING) {
                $this->validationError('status', 'Only a pending delegation can be approved.');
            }

            $delegation->forceFill([
                'status' => CustomerJourneyDelegation::STATUS_ACTIVE,
                'approved_by' => $approvedByUserId,
                'approved_at' => now(),
            ])->save();

            $this->logActivity($delegation, 'Customer Journey delegation approved', [
                'approved_by' => $approvedByUserId,
            ]);

            return $delegation;
        }, 3);
    }

    public function cancel(CustomerJourneyDelegation $delegation, int $cancelledByUserId, ?string $reason = null): CustomerJourneyDelegation
    {
        return DB::transaction(function () use ($delegation, $cancelledByUserId, $reason): CustomerJourneyDelegation {
            /** @var CustomerJourneyDelegation $delegation */
            $delegation = CustomerJourneyDelegation::query()->whereKey($delegation->id)->lockForUpdate()->firstOrFail();

            if (in_array($delegation->status, [CustomerJourneyDelegation::STATUS_CANCELLED, CustomerJourneyDelegation::STATUS_REJECTED], true)) {
                $this->validationError('status', 'This delegation has already been cancelled or rejected.');
            }

            $delegation->forceFill([
                'status' => CustomerJourneyDelegation::STATUS_CANCELLED,
                'cancelled_by' => $cancelledByUserId,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->logActivity($delegation, 'Customer Journey delegation cancelled', [
                'cancelled_by' => $cancelledByUserId,
                'reason' => $reason,
            ]);

            return $delegation;
        }, 3);
    }

    private function validationError(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }

    private function logActivity(CustomerJourneyDelegation $delegation, string $description, array $properties): void
    {
        if (! function_exists('activity')) {
            return;
        }

        try {
            activity('journey')
                ->causedBy(auth()->user())
                ->performedOn($delegation)
                ->withProperties($properties)
                ->log($description);
        } catch (Throwable) {
            // The dedicated customer_journey_delegations table remains authoritative.
        }
    }
}
