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
 * else. Nothing may be attached when an employee leaves
 * (EmployeeInactivationService) or moves to a new boss
 * (EmployeeTransferService) — they go with nothing attached.
 *
 * Attached means live work: people reporting straight to them, customers
 * they own that are still moving through the journey, assigned leads and
 * leads still open, and Team Continuity delegations still pending or
 * running. Each is handed over through the service that already owns that
 * move, so the usual history rows are written.
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
    public function dependencyCounts(Employee $employee, array $exceptReportIds = []): array
    {
        return array_filter([
            self::DIRECT_REPORTS => $this->directReports($employee, $exceptReportIds)->count(),
            self::CUSTOMERS => $this->openCustomers($employee)->count(),
            self::ASSIGNED_LEADS => $this->openAssignedLeads($employee)->count(),
            self::LEADS => $this->openLeads($employee)->count(),
            self::DELEGATIONS => $this->liveDelegations($employee)->count(),
        ]);
    }

    /**
     * "2 direct reports and 3 open customers".
     *
     * @param  array<string, int>  $counts
     */
    public function describe(array $counts): string
    {
        return collect($counts)
            ->map(fn (int $count, string $kind): string => $count.' '.Str::plural(self::NOUNS[$kind], $count))
            ->values()
            ->join(', ', ' and ');
    }

    /**
     * @param  list<int>  $exceptReportIds
     *
     * @throws ValidationException naming what is still attached
     */
    public function assertNothingAttached(Employee $employee, string $field, array $exceptReportIds = []): void
    {
        $dependencies = $this->dependencyCounts($employee, $exceptReportIds);

        if ($dependencies !== []) {
            throw ValidationException::withMessages([
                $field => "{$employee->emp_name} still has {$this->describe($dependencies)}. Hand them over to somebody else first.",
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
     * through the journey.
     *
     * @return Builder<Customer>
     */
    public function openCustomers(Employee $employee): Builder
    {
        return JourneySlaService::activeCustomersQuery()->where('assign_to', $employee->id);
    }

    /**
     * Assigned leads $employee holds that are neither converted nor closed.
     *
     * @return Builder<CustomerAssignment>
     */
    public function openAssignedLeads(Employee $employee): Builder
    {
        // NOT IN a positive match, rather than "latest status != closed",
        // so a lead nobody has followed up yet (no status at all) counts.
        return CustomerAssignment::query()
            ->where('customer_assignments.employee_id', $employee->id)
            ->whereNull('customer_assignments.converted_at')
            ->whereNotIn('customer_assignments.id', CustomerAssignment::query()
                ->select('customer_assignments.id')
                ->whereLatestFollowUpStatus(CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES));
    }

    /**
     * Leads $employee holds that are neither converted nor closed.
     *
     * @return Builder<Lead>
     */
    public function openLeads(Employee $employee): Builder
    {
        return Lead::query()
            ->where('employee_id', $employee->id)
            ->where('is_converted', false)
            ->where(function (Builder $query): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES);
            });
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

    public function moveOpenCustomers(Employee $employee, int $newOwnerId, User $performedBy): int
    {
        $this->assertOffered($newOwnerId, $this->newCustomerOwnerOptions($employee), 'customers_target');

        return DB::transaction(function () use ($employee, $newOwnerId, $performedBy): int {
            $customers = $this->openCustomers($employee)->get();

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

    public function moveOpenAssignedLeads(Employee $employee, int $newOwnerId, ?User $performedBy): int
    {
        $this->assertOffered($newOwnerId, $this->newLeadOwnerOptions($employee), 'assigned_leads_target');

        return $this->customerAssignments->reassign(
            $this->openAssignedLeads($employee)->get(),
            $newOwnerId,
            $performedBy?->employee?->id,
            "Handed over from {$employee->emp_name}.",
        )['reassigned'];
    }

    public function moveOpenLeads(Employee $employee, int $newOwnerId): int
    {
        $this->assertOffered($newOwnerId, $this->newLeadOwnerOptions($employee), 'leads_target');

        return DB::transaction(function () use ($employee, $newOwnerId): int {
            $leads = $this->openLeads($employee)->get();

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
