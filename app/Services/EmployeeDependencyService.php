<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerJourneyDelegation;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use App\Services\Journey\CustomerJourneyDelegationService;
use App\Services\Journey\CustomerReassignmentService;
use App\Services\Journey\JourneySlaService;
use App\Support\EmployeeOptions;
use App\Support\ReportingTree;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What is still attached to an employee, and handing it over to somebody
 * else.
 *
 * Attached means live work: people reporting straight to them, customers
 * they own that are still moving through the journey, assigned leads and
 * leads still open, and Team Continuity delegations still pending or
 * running. Each is handed over through the service that already owns that
 * move, so the usual history rows are written.
 *
 * Two scopes (user decision 2026-11-16):
 * - SCOPE_LEAVE: leaving (EmployeeInactivationService) or changing position
 *   (EmployeePositionChangeService) — everything is handed over first.
 * - SCOPE_MOVE: moving to a new boss (EmployeeTransferService). Their team
 *   never moves with them, so direct reports are handed over first. A caller
 *   takes all of their own work along. A Team Leader or anybody above takes
 *   along only the customers, leads and assigned leads THEY created
 *   (customers.employee_id, leads.created_by, customer_assignments.
 *   assigned_by); whatever somebody else created and gave them is handed
 *   over. Delegations run on until they end.
 *
 * Finished work — disbursed or dropped customers, converted or closed
 * leads — stays with them on purpose: achievement and lead reports credit
 * whoever held it, and moving it would rewrite past months.
 *
 * $exceptReportIds: direct reports that are moving elsewhere in the same
 * batch (a flexible reassignment) and so are not left behind.
 */
class EmployeeDependencyService
{
    public const DIRECT_REPORTS = 'direct_reports';

    public const CUSTOMERS = 'customers';

    public const ASSIGNED_LEADS = 'assigned_leads';

    public const LEADS = 'leads';

    public const DELEGATIONS = 'delegations';

    /**
     * Leaving or changing position: everything is handed over.
     */
    public const SCOPE_LEAVE = 'leave';

    /**
     * Moving to a new boss: the team, and work somebody else created.
     */
    public const SCOPE_MOVE = 'move';

    /**
     * How one of each dependency reads in a sentence.
     *
     * @var array<string, string>
     */
    public const NOUNS = [
        self::DIRECT_REPORTS => 'direct report',
        self::CUSTOMERS => 'open customer',
        self::ASSIGNED_LEADS => 'open assigned lead',
        self::LEADS => 'open lead',
        self::DELEGATIONS => 'live delegation',
    ];

    public function __construct(
        private ReportingLineService $reportingLines,
        private CustomerReassignmentService $customerReassignments,
        private CustomerAssignmentService $customerAssignments,
        private CustomerJourneyDelegationService $delegations,
    ) {}

    /**
     * What is still attached to $employee, by kind. Kinds with nothing left
     * are left out, so an empty array means nothing is attached.
     *
     * @param  list<int>  $exceptReportIds
     * @return array<string, int>
     */
    public function dependencyCounts(Employee $employee, array $exceptReportIds = [], string $scope = self::SCOPE_LEAVE): array
    {
        return array_filter([
            self::DIRECT_REPORTS => $this->directReports($employee, $exceptReportIds)->count(),
            self::CUSTOMERS => $this->openCustomers($employee, $scope)->count(),
            self::ASSIGNED_LEADS => $this->openAssignedLeads($employee, $scope)->count(),
            self::LEADS => $this->openLeads($employee, $scope)->count(),
            self::DELEGATIONS => $scope === self::SCOPE_MOVE ? 0 : $this->liveDelegations($employee)->count(),
        ]);
    }

    /**
     * What still keeps $employee from moving to a new boss (SCOPE_MOVE).
     *
     * @param  list<int>  $exceptReportIds
     * @return array<string, int>
     */
    public function moveDependencyCounts(Employee $employee, array $exceptReportIds = []): array
    {
        return $this->dependencyCounts($employee, $exceptReportIds, self::SCOPE_MOVE);
    }

    /**
     * Whether a move takes all of $employee's own work along (a caller),
     * rather than only what they created themselves.
     */
    public function takesAllWorkAlong(Employee $employee): bool
    {
        return (int) $employee->designation === Employee::DESIGNATION_CALLER;
    }

    /**
     * "2 direct reports and 3 open customers" — on a move, work is
     * qualified as "not created by them".
     *
     * @param  array<string, int>  $counts
     */
    public function describe(array $counts, string $scope = self::SCOPE_LEAVE): string
    {
        return collect($counts)
            ->map(fn (int $count, string $kind): string => $count.' '.Str::plural(self::NOUNS[$kind], $count)
                .($scope === self::SCOPE_MOVE && $kind !== self::DIRECT_REPORTS ? ' not created by them' : ''))
            ->values()
            ->join(', ', ' and ');
    }

    /**
     * @param  list<int>  $exceptReportIds
     *
     * @throws ValidationException naming what is still attached
     */
    public function assertNothingAttached(Employee $employee, string $field, array $exceptReportIds = [], string $scope = self::SCOPE_LEAVE): void
    {
        $dependencies = $this->dependencyCounts($employee, $exceptReportIds, $scope);

        if ($dependencies !== []) {
            throw ValidationException::withMessages([
                $field => "{$employee->emp_name} still has {$this->describe($dependencies, $scope)}. Hand them over to somebody else first.",
            ]);
        }
    }

    /**
     * Employees on the rolls who report straight to $employee.
     *
     * @param  list<int>  $exceptReportIds
     * @return Builder<Employee>
     */
    public function directReports(Employee $employee, array $exceptReportIds = []): Builder
    {
        $tree = ReportingTree::load();

        $reportIds = array_filter(
            $tree->childIds($employee->id),
            fn (int $reportId): bool => ! $tree->isExited($reportId) && ! in_array($reportId, $exceptReportIds, true),
        );

        return Employee::query()->whereIn('id', $reportIds);
    }

    /**
     * Customers $employee owns (customers.assign_to) that are still moving
     * through the journey. On a move, only those somebody else created
     * (customers.employee_id is the creator).
     *
     * @return Builder<Customer>
     */
    public function openCustomers(Employee $employee, string $scope = self::SCOPE_LEAVE): Builder
    {
        return $this->leftBehindOnMove(
            JourneySlaService::activeCustomersQuery()->where('assign_to', $employee->id),
            $employee,
            $scope,
            'customers.employee_id',
        );
    }

    /**
     * Assigned leads $employee holds that are neither converted nor closed.
     * On a move, only those somebody else assigned to them.
     *
     * @return Builder<CustomerAssignment>
     */
    public function openAssignedLeads(Employee $employee, string $scope = self::SCOPE_LEAVE): Builder
    {
        // NOT IN a positive match, rather than "latest status != closed",
        // so a lead nobody has followed up yet (no status at all) counts.
        return $this->leftBehindOnMove(
            CustomerAssignment::query()
                ->where('customer_assignments.employee_id', $employee->id)
                ->whereNull('customer_assignments.converted_at')
                ->whereNotIn('customer_assignments.id', CustomerAssignment::query()
                    ->select('customer_assignments.id')
                    ->whereLatestFollowUpStatus(CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES)),
            $employee,
            $scope,
            'customer_assignments.assigned_by',
        );
    }

    /**
     * Leads $employee holds that are neither converted nor closed. On a
     * move, only those somebody else created (leads.created_by).
     *
     * @return Builder<Lead>
     */
    public function openLeads(Employee $employee, string $scope = self::SCOPE_LEAVE): Builder
    {
        return $this->leftBehindOnMove(
            Lead::query()
                ->where('leads.employee_id', $employee->id)
                ->where('leads.is_converted', false)
                ->where(function (Builder $query): void {
                    $query->whereNull('leads.status')
                        ->orWhereNotIn('leads.status', CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES);
                }),
            $employee,
            $scope,
            'leads.created_by',
        );
    }

    /**
     * Team Continuity delegations to or from $employee that are pending or
     * still running.
     *
     * @return Builder<CustomerJourneyDelegation>
     */
    public function liveDelegations(Employee $employee): Builder
    {
        return CustomerJourneyDelegation::query()
            ->whereIn('status', [CustomerJourneyDelegation::STATUS_PENDING, CustomerJourneyDelegation::STATUS_ACTIVE])
            ->where('end_at', '>=', now())
            ->where(function (Builder $query) use ($employee): void {
                $query->where('delegating_manager_id', $employee->id)
                    ->orWhere('acting_manager_id', $employee->id);
            });
    }

    /**
     * Who $employee's direct reports may all be moved to: anyone on the
     * rolls more senior than the most senior of them, grouped by level.
     *
     * @param  list<int>  $exceptReportIds
     * @return array<string, array<int, string>>
     */
    public function newBossOptions(Employee $employee, array $exceptReportIds = []): array
    {
        $mostSeniorReport = $this->directReports($employee, $exceptReportIds)
            ->get(['id', 'designation'])
            ->sortByDesc(fn (Employee $report): int => Employee::designationRank($report->designation))
            ->first();

        return $mostSeniorReport
            ? $this->reportingLines->bossOptions($mostSeniorReport->designation, $employee->id)
            : [];
    }

    /**
     * Managers and Team Leaders on the rolls who may own $employee's open
     * customers (CustomerReassignmentService accepts no one else).
     *
     * @return array<int, string>
     */
    public function newCustomerOwnerOptions(Employee $employee): array
    {
        return $this->activeEmployeeOptions($employee, [Employee::DESIGNATION_MANAGER, Employee::DESIGNATION_TEAM_LEADER]);
    }

    /**
     * Callers and Team Leaders on the rolls who may take $employee's open
     * leads — the same people leads are assigned to in the first place.
     *
     * @return array<int, string>
     */
    public function newLeadOwnerOptions(Employee $employee): array
    {
        return $this->activeEmployeeOptions($employee, [Employee::DESIGNATION_CALLER, Employee::DESIGNATION_TEAM_LEADER]);
    }

    /**
     * Move every direct report of $employee to $newBossId (their teams
     * follow them), dated $effectiveDate in the reporting history.
     *
     * @param  list<int>  $exceptReportIds
     */
    public function moveDirectReports(Employee $employee, int $newBossId, CarbonInterface $effectiveDate, ?User $performedBy, array $exceptReportIds = []): int
    {
        if ($effectiveDate->copy()->startOfDay()->isAfter(today())) {
            throw ValidationException::withMessages(['handover_date' => 'The hand-over date cannot be in the future.']);
        }

        return DB::transaction(function () use ($employee, $newBossId, $effectiveDate, $performedBy, $exceptReportIds): int {
            $reports = $this->directReports($employee, $exceptReportIds)->get();

            foreach ($reports as $report) {
                $this->reportingLines->reportTo(
                    $report,
                    $newBossId,
                    $effectiveDate,
                    ReportingLineService::CHANGE_TRANSFER,
                    "Handed over from {$employee->emp_name}.",
                    $performedBy?->id,
                );
            }

            return $reports->count();
        });
    }

    public function moveOpenCustomers(Employee $employee, int $newOwnerId, User $performedBy, string $scope = self::SCOPE_LEAVE): int
    {
        $this->assertOffered($newOwnerId, $this->newCustomerOwnerOptions($employee), 'customers_target');

        return DB::transaction(function () use ($employee, $newOwnerId, $performedBy, $scope): int {
            $customers = $this->openCustomers($employee, $scope)->get();

            foreach ($customers as $customer) {
                $this->customerReassignments->reassign(
                    $customer,
                    $newOwnerId,
                    $performedBy->id,
                    "Handed over from {$employee->emp_name}.",
                );
            }

            return $customers->count();
        });
    }

    public function moveOpenAssignedLeads(Employee $employee, int $newOwnerId, ?User $performedBy, string $scope = self::SCOPE_LEAVE): int
    {
        $this->assertOffered($newOwnerId, $this->newLeadOwnerOptions($employee), 'assigned_leads_target');

        return $this->customerAssignments->reassign(
            $this->openAssignedLeads($employee, $scope)->get(),
            $newOwnerId,
            $performedBy?->employee?->id,
            "Handed over from {$employee->emp_name}.",
        )['reassigned'];
    }

    public function moveOpenLeads(Employee $employee, int $newOwnerId, string $scope = self::SCOPE_LEAVE): int
    {
        $this->assertOffered($newOwnerId, $this->newLeadOwnerOptions($employee), 'leads_target');

        return DB::transaction(function () use ($employee, $newOwnerId, $scope): int {
            $leads = $this->openLeads($employee, $scope)->get();

            $leads->each(fn (Lead $lead) => $lead->update(['employee_id' => $newOwnerId]));

            return $leads->count();
        });
    }

    public function cancelLiveDelegations(Employee $employee, User $performedBy): int
    {
        return DB::transaction(function () use ($employee, $performedBy): int {
            $delegations = $this->liveDelegations($employee)->get();

            foreach ($delegations as $delegation) {
                $this->delegations->cancel($delegation, $performedBy->id, "Cancelled while handing over {$employee->emp_name}'s work.");
            }

            return $delegations->count();
        });
    }

    /**
     * On a move, narrows $query to the work that stays behind: nothing for a
     * caller (they take it all), otherwise whatever $creatorColumn says
     * somebody else created.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function leftBehindOnMove(Builder $query, Employee $employee, string $scope, string $creatorColumn): Builder
    {
        if ($scope !== self::SCOPE_MOVE) {
            return $query;
        }

        if ($this->takesAllWorkAlong($employee)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($creatorColumn, $employee): void {
            $query->whereNull($creatorColumn)->orWhere($creatorColumn, '!=', $employee->id);
        });
    }

    /**
     * @param  list<int>  $designations
     * @return array<int, string>
     */
    private function activeEmployeeOptions(Employee $except, array $designations): array
    {
        return Employee::query()
            ->whereIn('designation', $designations)
            ->where('exit_status', '!=', 'yes')
            ->whereKeyNot($except->id)
            ->orderBy('emp_name')
            ->get(['id', 'emp_name', 'emp_id'])
            ->mapWithKeys(fn (Employee $option): array => [$option->id => EmployeeOptions::label($option)])
            ->all();
    }

    /**
     * @param  array<int, string>  $options
     */
    private function assertOffered(int $employeeId, array $options, string $field): void
    {
        if (! array_key_exists($employeeId, $options)) {
            throw ValidationException::withMessages([$field => 'Choose somebody on the rolls from the list.']);
        }
    }
}
