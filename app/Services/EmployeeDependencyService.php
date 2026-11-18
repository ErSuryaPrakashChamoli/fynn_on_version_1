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
 * Two scopes (user decision 2026-11-18):
 * - SCOPE_LEAVE: leaving (EmployeeInactivationService) — everything is
 *   handed over first, to anybody on the rolls whatever their position.
 * - SCOPE_MOVE: staying on the rolls — a move to a new boss
 *   (EmployeeTransferService) or a promotion / demotion
 *   (EmployeePositionChangeService). Work is never handed over while they
 *   stay: every customer, lead and assigned lead they hold, whoever created
 *   or gave it to them, stays theirs, and delegations run on. Only their
 *   team is asked about — it never moves with them, so anyone reporting to
 *   them is given a new boss and keeps their own work.
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
     * Leaving: everything is handed over.
     */
    public const SCOPE_LEAVE = 'leave';

    /**
     * Staying on the rolls (a move or a change of position): only the team.
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
        $counts = [self::DIRECT_REPORTS => $this->directReports($employee, $exceptReportIds)->count()];

        if ($scope === self::SCOPE_LEAVE) {
            $counts += [
                self::CUSTOMERS => $this->openCustomers($employee)->count(),
                self::ASSIGNED_LEADS => $this->openAssignedLeads($employee)->count(),
                self::LEADS => $this->openLeads($employee)->count(),
                self::DELEGATIONS => $this->liveDelegations($employee)->count(),
            ];
        }

        return array_filter($counts);
    }

    /**
     * What still keeps $employee from moving to a new boss or changing
     * position (SCOPE_MOVE): only people reporting to them.
     *
     * @param  list<int>  $exceptReportIds
     * @return array<string, int>
     */
    public function moveDependencyCounts(Employee $employee, array $exceptReportIds = []): array
    {
        return $this->dependencyCounts($employee, $exceptReportIds, self::SCOPE_MOVE);
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
    public function assertNothingAttached(Employee $employee, string $field, array $exceptReportIds = [], string $scope = self::SCOPE_LEAVE): void
    {
        $dependencies = $this->dependencyCounts($employee, $exceptReportIds, $scope);

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
            ->where('leads.employee_id', $employee->id)
            ->where('leads.is_converted', false)
            ->where(function (Builder $query): void {
                $query->whereNull('leads.status')
                    ->orWhereNotIn('leads.status', CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES);
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
     * Who a leaving employee's open customers, assigned leads and leads may
     * be handed to: anybody on the rolls across the organisation, whatever
     * their position (user decision 2026-11-18), grouped by position, most
     * senior first.
     *
     * @return array<string, array<int, string>>
     */
    public function newOwnerOptions(Employee $employee): array
    {
        $positions = Employee::designationOptions();

        return $this->onTheRollsExcept($employee)
            ->get(['id', 'emp_name', 'emp_id', 'designation'])
            ->sortBy([
                fn (Employee $a, Employee $b): int => Employee::designationRank($b->designation) <=> Employee::designationRank($a->designation),
                fn (Employee $a, Employee $b): int => strcasecmp((string) ($positions[$a->designation] ?? ''), (string) ($positions[$b->designation] ?? '')),
                fn (Employee $a, Employee $b): int => strcasecmp((string) $a->emp_name, (string) $b->emp_name),
            ])
            ->groupBy(fn (Employee $option): string => $positions[$option->designation] ?? 'Other')
            ->map(fn ($group): array => $group
                ->mapWithKeys(fn (Employee $option): array => [$option->id => EmployeeOptions::label($option)])
                ->all())
            ->all();
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
        $this->assertOnTheRolls($newOwnerId, $employee, 'customers_target');

        return DB::transaction(function () use ($employee, $newOwnerId, $performedBy): int {
            $customers = $this->openCustomers($employee)->get();

            foreach ($customers as $customer) {
                $this->customerReassignments->reassign(
                    $customer,
                    $newOwnerId,
                    $performedBy->id,
                    "Handed over from {$employee->emp_name}.",
                    anyPosition: true,
                );
            }

            return $customers->count();
        });
    }

    public function moveOpenAssignedLeads(Employee $employee, int $newOwnerId, ?User $performedBy): int
    {
        $this->assertOnTheRolls($newOwnerId, $employee, 'assigned_leads_target');

        return $this->customerAssignments->reassign(
            $this->openAssignedLeads($employee)->get(),
            $newOwnerId,
            $performedBy?->employee?->id,
            "Handed over from {$employee->emp_name}.",
        )['reassigned'];
    }

    public function moveOpenLeads(Employee $employee, int $newOwnerId): int
    {
        $this->assertOnTheRolls($newOwnerId, $employee, 'leads_target');

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
     * Everybody on the rolls but $employee.
     *
     * @return Builder<Employee>
     */
    private function onTheRollsExcept(Employee $employee): Builder
    {
        return Employee::query()
            ->where('exit_status', '!=', 'yes')
            ->whereKeyNot($employee->id);
    }

    private function assertOnTheRolls(int $employeeId, Employee $leaving, string $field): void
    {
        if (! $this->onTheRollsExcept($leaving)->whereKey($employeeId)->exists()) {
            throw ValidationException::withMessages([$field => 'Choose somebody on the rolls from the list.']);
        }
    }
}
