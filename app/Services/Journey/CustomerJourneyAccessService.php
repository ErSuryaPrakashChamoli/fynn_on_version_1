<?php

namespace App\Services\Journey;

use App\Enums\ContinuityScopeType;
use App\Enums\JourneyAccessType;
use App\Enums\JourneyModule;
use App\Models\Customer;
use App\Models\CustomerJourneyAudit;
use App\Models\CustomerJourneyDelegation;
use App\Models\Employee;
use App\Models\JourneyTakeover;
use App\Models\User;
use App\Support\HierarchyHelper;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Single authority for "can this user perform this Manager-stage action on
 * this customer right now, and under what access type". Extends today's
 * normal-access rule (unchanged) with two additional, time-boxed grant
 * paths: an active Team Continuity backup rule, or an active emergency
 * takeover. Ownership (Customer::assign_to) is never read as anything
 * other than a snapshot here — this service never mutates it.
 *
 * Note on "existing vs new" customers (Engine 1 vs Engine 2 in the spec):
 * because every check here is re-evaluated live against the current
 * hierarchy and the current time window (never against a snapshot taken
 * at customer-creation time), a single continuity rule automatically
 * covers a brand-new customer the moment it's created — there is no
 * separate "routing" pass to run. coverage_type only filters WHICH
 * customers (by created_at vs the rule's start_at) a rule applies to; it
 * is not a second enforcement engine.
 */
class CustomerJourneyAccessService
{
    public function decide(User $user, Customer $customer, JourneyModule $module): JourneyAccessDecision
    {
        $originalOwnerId = $customer->assign_to;

        if ($user->hasRole('Admin')) {
            return new JourneyAccessDecision(
                allowed: true,
                accessType: JourneyAccessType::Normal,
                originalOwnerId: $originalOwnerId,
                actingEmployeeId: null,
            );
        }

        $employee = $user->employee;

        if (! $employee) {
            return JourneyAccessDecision::denied('No employee profile linked to this user.');
        }

        if ($this->hasNormalAccess($employee, $customer)) {
            return new JourneyAccessDecision(
                allowed: true,
                accessType: JourneyAccessType::Normal,
                originalOwnerId: $originalOwnerId,
                actingEmployeeId: $employee->id,
            );
        }

        $delegation = $this->activeDelegationFor($employee, $customer, $module);

        if ($delegation) {
            return new JourneyAccessDecision(
                allowed: true,
                accessType: $delegation->access_type,
                originalOwnerId: $originalOwnerId,
                actingEmployeeId: $employee->id,
                delegationId: $delegation->id,
            );
        }

        $takeover = $this->activeTakeoverFor($employee, $customer, $module);

        if ($takeover) {
            return new JourneyAccessDecision(
                allowed: true,
                accessType: JourneyAccessType::EmergencyTakeover,
                originalOwnerId: $originalOwnerId,
                actingEmployeeId: $employee->id,
                takeoverId: $takeover->id,
            );
        }

        return JourneyAccessDecision::denied('No normal, delegated, or takeover access to this customer.');
    }

    /**
     * Exactly today's rule (CustomerResource::getEloquentQuery()/canEdit()):
     * visible via the hierarchy tree under assign_to, and not a Caller.
     */
    public function hasNormalAccess(Employee $employee, Customer $customer): bool
    {
        if ($employee->designation === Employee::DESIGNATION_CALLER) {
            return false;
        }

        if (! $customer->assign_to) {
            return false;
        }

        return HierarchyHelper::subordinateIds($employee)->contains((int) $customer->assign_to);
    }

    public function activeDelegationFor(Employee $employee, Customer $customer, JourneyModule $module): ?CustomerJourneyDelegation
    {
        $chainIds = $this->responsibleEmployeeChain($customer);

        if ($chainIds->isEmpty()) {
            return null;
        }

        return CustomerJourneyDelegation::query()
            ->where('acting_manager_id', $employee->id)
            ->whereIn('delegating_manager_id', $chainIds)
            ->activeAt(now())
            ->get()
            ->first(function (CustomerJourneyDelegation $delegation) use ($module, $customer): bool {
                return in_array($module->value, $delegation->modules ?? [], true)
                    && $delegation->coversRecordCreatedAt($customer->created_at)
                    && $this->scopeCoversCustomer($delegation, $customer);
            });
    }

    public function activeTakeoverFor(Employee $employee, Customer $customer, JourneyModule $module): ?JourneyTakeover
    {
        return JourneyTakeover::query()
            ->where('customer_id', $customer->id)
            ->activeForEmployee($employee->id)
            ->get()
            ->first(fn (JourneyTakeover $takeover): bool => $takeover->grantsModule($module->value));
    }

    /**
     * Every employee id "responsible" for a customer under today's
     * hierarchy — the assign_to employee itself plus its full upward chain
     * (team leader, manager, cluster manager). Generalizes naturalManagerFor()
     * beyond Manager-only so a continuity rule created for a Caller, Team
     * Leader, or Cluster Manager can also match. Order is closest-first.
     */
    public function responsibleEmployeeChain(Customer $customer): Collection
    {
        if (! $customer->assign_to) {
            return collect();
        }

        /** @var Employee|null $owner */
        $owner = Employee::find($customer->assign_to);

        if (! $owner) {
            return collect();
        }

        $chain = collect([$owner->id]);

        foreach ([$owner, $owner->superviser, $owner->superviser?->manager] as $link) {
            if (! $link) {
                continue;
            }

            $chain->push($link->superviser_id);
            $chain->push($link->manager_id);
            $chain->push($link->cluster_id);
        }

        return $chain->filter()->unique()->values();
    }

    /**
     * The employee who would actually pick up a Manager-stage action on
     * this customer right now: the natural Manager, UNLESS an active
     * continuity rule currently covers them (any module, any coverage
     * type applicable to this customer), in which case it's their backup.
     * Used by Pending Manager Cases' "Operational Manager" column.
     */
    public function resolveOperationalManager(Customer $customer): ?Employee
    {
        $naturalManager = $this->naturalManagerFor($customer);

        if (! $naturalManager) {
            return null;
        }

        $chainIds = $this->responsibleEmployeeChain($customer);

        $delegation = CustomerJourneyDelegation::query()
            ->whereIn('delegating_manager_id', $chainIds)
            ->activeAt(now())
            ->get()
            ->first(fn (CustomerJourneyDelegation $delegation): bool => $delegation->coversRecordCreatedAt($customer->created_at)
                && $this->scopeCoversCustomer($delegation, $customer));

        return $delegation ? $delegation->actingManager : $naturalManager;
    }

    /**
     * The Manager who is the tree-ancestor of the customer's current
     * assign_to employee under today's hierarchy — i.e. "the assigned
     * Manager" this feature exists to provide continuity for. Kept for
     * Manager-specific callers (SLA escalation, Pending Manager Cases);
     * see responsibleEmployeeChain() for the any-level version used by
     * continuity matching itself.
     */
    public function naturalManagerFor(Customer $customer): ?Employee
    {
        if (! $customer->assign_to) {
            return null;
        }

        /** @var Employee|null $owner */
        $owner = Employee::find($customer->assign_to);

        if (! $owner) {
            return null;
        }

        return match ($owner->designation) {
            Employee::DESIGNATION_CALLER => $owner->superviser?->manager,
            Employee::DESIGNATION_TEAM_LEADER => $owner->manager,
            Employee::DESIGNATION_MANAGER => $owner,
            default => null,
        };
    }

    /**
     * Customer ids visible to $employee purely through active continuity
     * rules where they are the backup (any coverage/scope type) — used to
     * scope "My Customers" and the Assigned Leads queue.
     */
    public function visibleCustomerIdsForDelegatee(Employee $employee): Collection
    {
        $delegations = CustomerJourneyDelegation::query()
            ->where('acting_manager_id', $employee->id)
            ->activeAt(now())
            ->get();

        return $delegations
            ->flatMap(fn (CustomerJourneyDelegation $delegation): Collection => $this->customerIdsCoveredBy($delegation))
            ->unique()
            ->values();
    }

    /**
     * All distinct customer ids currently covered by ANY active continuity
     * rule (regardless of which backup holds it) — used by the Customer
     * Journey Continuity dashboard's "Customers Currently Delegated" stat.
     */
    public function activeDelegatedCustomerIds(): Collection
    {
        $delegations = CustomerJourneyDelegation::query()
            ->activeAt(now())
            ->get();

        return $delegations
            ->flatMap(fn (CustomerJourneyDelegation $delegation): Collection => $this->customerIdsCoveredBy($delegation))
            ->unique()
            ->values();
    }

    /**
     * The employee ids whose new-and-existing work a continuity rule
     * actually reaches, respecting scope_type (an employee's own records
     * only vs their whole branch) and coverage_type (created_at vs the
     * rule's own start_at).
     */
    private function customerIdsCoveredBy(CustomerJourneyDelegation $delegation): Collection
    {
        $original = Employee::find($delegation->delegating_manager_id);

        if (! $original) {
            return collect();
        }

        $employeeIds = $delegation->scope_type === ContinuityScopeType::Individual
            ? collect([$original->id])
            : HierarchyHelper::subordinateIds($original);

        $query = Customer::query()->whereIn('assign_to', $employeeIds);

        if (! $delegation->coverage_type->coversExisting()) {
            $query->where('created_at', '>=', $delegation->start_at);
        } elseif (! $delegation->coverage_type->coversNew()) {
            $query->where('created_at', '<', $delegation->start_at);
        }

        return $query->pluck('id');
    }

    private function scopeCoversCustomer(CustomerJourneyDelegation $delegation, Customer $customer): bool
    {
        if ($delegation->scope_type !== ContinuityScopeType::Individual) {
            return true;
        }

        return (int) $customer->assign_to === (int) $delegation->delegating_manager_id;
    }

    /**
     * Every employee id whose direct-assignment work (e.g.
     * CustomerAssignment.employee_id in the Assigned Leads queue) $employee
     * currently covers as a backup — i.e. the Backup Routing Engine's
     * integration point for records that are assigned to a single employee
     * directly rather than reached via Customer::assign_to + hierarchy.
     * Scope-aware: an "individual" rule covers only the original employee's
     * own id, a "hierarchy_branch" rule covers their whole subordinate tree.
     */
    public function coveredEmployeeIdsForBackup(Employee $backup): Collection
    {
        return CustomerJourneyDelegation::query()
            ->where('acting_manager_id', $backup->id)
            ->activeAt(now())
            ->get()
            ->flatMap(function (CustomerJourneyDelegation $delegation): Collection {
                $original = Employee::find($delegation->delegating_manager_id);

                if (! $original) {
                    return collect();
                }

                return $delegation->scope_type === ContinuityScopeType::Individual
                    ? collect([$original->id])
                    : HierarchyHelper::subordinateIds($original);
            })
            ->unique()
            ->values();
    }

    public function visibleCustomerIdsForTakeover(Employee $employee): Collection
    {
        return JourneyTakeover::query()
            ->activeForEmployee($employee->id)
            ->pluck('customer_id')
            ->unique()
            ->values();
    }

    /**
     * Writes one immutable audit row. Best-effort: a failure here must never
     * block the underlying journey action, matching the existing
     * HierarchyReassignmentService convention for activity() logging.
     */
    public function recordAudit(
        Customer $customer,
        string $action,
        JourneyAccessType $accessType,
        ?int $performedByUserId,
        ?int $actingEmployeeId,
        ?JourneyModule $module = null,
        ?int $delegationId = null,
        ?int $takeoverId = null,
        ?string $reason = null,
        bool $isAdminOverride = false,
    ): ?CustomerJourneyAudit {
        try {
            $caseType = null;
            $originalHierarchy = null;
            $backupHierarchy = null;

            if ($delegationId) {
                $delegation = CustomerJourneyDelegation::find($delegationId);

                if ($delegation) {
                    $caseType = $customer->created_at?->greaterThanOrEqualTo($delegation->start_at)
                        ? 'new_customer'
                        : 'existing_customer';

                    $originalHierarchy = $this->hierarchySnapshot($delegation->delegatingManager);
                    $backupHierarchy = $this->hierarchySnapshot($delegation->actingManager);
                    $isAdminOverride = $isAdminOverride || $delegation->is_admin_override;
                }
            }

            return CustomerJourneyAudit::query()->create([
                'customer_id' => $customer->id,
                'journey_stage' => (string) ($customer->journey_status ?? $customer->disbursal_status ?? 'unknown'),
                'module' => $module?->value,
                'action' => $action,
                'original_owner_id' => $customer->assign_to,
                'acting_employee_id' => $actingEmployeeId,
                'access_type' => $accessType,
                'case_type' => $caseType,
                'is_admin_override' => $isAdminOverride,
                'original_hierarchy' => $originalHierarchy,
                'backup_hierarchy' => $backupHierarchy,
                'delegation_id' => $delegationId,
                'takeover_id' => $takeoverId,
                'reason' => $reason,
                'performed_by' => $performedByUserId,
                'performed_at' => now(),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{name: ?string, designation: ?string, cluster: ?string}|null
     */
    private function hierarchySnapshot(?Employee $employee): ?array
    {
        if (! $employee) {
            return null;
        }

        return [
            'name' => $employee->emp_name,
            'designation' => Employee::designationOptions()[$employee->designation] ?? null,
            'cluster' => $employee->cluster?->emp_name,
        ];
    }
}
