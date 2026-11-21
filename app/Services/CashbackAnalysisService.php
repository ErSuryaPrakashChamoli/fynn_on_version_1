<?php

namespace App\Services;

use App\Enums\CashbackRequestStatus;
use App\Models\Customer;
use App\Models\CustomerCashbackRequest;
use App\Models\Employee;
use App\Support\EmployeeOptions;
use App\Support\HierarchyHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cashback given against the business it was given on, for one employee
 * (their own cases plus their branch's) or the whole company, in one month:
 *
 *  - cases punched in: cases disbursed in the month (disbursal date), each
 *    counted for the team its owner was in on that day — the same cases
 *    and the same attribution as the achievement figures
 *    (AchievementCalculatorService::disbursedCustomersFor());
 *  - cases with cashback, total cashback and disbursal on those cases, read
 *    the way the achievement maths read them (MIS figure first, else the
 *    case's own — customers.cashback already follows the live cashback
 *    forms, pending ones included);
 *  - cases approved in the month (approval date) and their approved amount;
 *  - cashback as a % of disbursal and of cases, and the average cashback.
 *
 * forForm() runs it for everyone on a cashback form's chain — saved on the
 * form when it is raised (analysis_snapshot) so whoever approves it acts on
 * the analysis and the basis stays on record.
 */
class CashbackAnalysisService
{
    /** The cashback the achievement maths read: the MIS figure when the settlement has one, else the case's own. */
    private const CASHBACK_SQL = 'COALESCE(CASE WHEN cs.mis_cashback IS NOT NULL THEN cs.mis_cashback ELSE customers.cashback END, 0)';

    public function __construct(private AchievementCalculatorService $achievement) {}

    /**
     * The month's figures for $employee (null: the whole company). With
     * $within, only what $employee did while in $within's branch.
     *
     * @return array{cases_punched_in: int, cases_with_cashback: int, cases_with_cashback_percent: float, cashback_amount: float, disbursal_amount: float, approved_cases: int, approval_amount: float, cashback_percent: float, average_cashback: float}
     */
    public function figures(?Employee $employee, Carbon $month, ?Employee $within = null): array
    {
        [$start, $end] = self::range($month);

        $disbursed = $this->disbursedCases($employee, $start, $end, $within);
        $totals = $this->achievement->computeAchievementTotals(clone $disbursed);

        $casesWithCashback = $this->withSettlement(clone $disbursed)
            ->whereRaw(self::CASHBACK_SQL.' > 0')
            ->count();

        $approved = $this->casesDatedIn($employee, 'customers.approval_date', $start, $end, $within)
            ->toBase()
            ->selectRaw('COUNT(*) as cases, SUM(customers.approved_loan_amount) as amount')
            ->first();

        return self::summarise(
            casesPunchedIn: (clone $disbursed)->count(),
            casesWithCashback: $casesWithCashback,
            cashbackAmount: $totals['cashback'],
            disbursalAmount: $totals['actual'],
            approvedCases: (int) ($approved->cases ?? 0),
            approvalAmount: (float) ($approved->amount ?? 0),
        );
    }

    /**
     * The same figures split by the people reporting straight to $employee
     * in the month (company-wide: the top of every branch), each row only
     * what they did inside that branch, plus whatever is left — the
     * employee's own cases, or cases outside every branch — so the rows add
     * up to $total.
     *
     * @param  array<string, int|float>  $total  figures() for $employee and $month
     * @return list<array<string, mixed>>
     */
    public function breakdown(?Employee $employee, Carbon $month, array $total): array
    {
        [$start, $end] = self::range($month);
        $isCompany = self::isCompany($employee);

        $members = ($isCompany
            ? HierarchyHelper::branchTopsDuring($start, $end)
            : HierarchyHelper::childrenDuring($employee, $start, $end))
            ->orderBy('emp_name')
            ->get();

        if ($members->isEmpty()) {
            return [];
        }

        $rows = $members
            ->map(fn (Employee $member): array => self::row($member, $this->figures($member, $month, $isCompany ? null : $employee)))
            ->values()
            ->all();

        $rest = self::remainder($total, $rows);

        if ($rest['cases_punched_in'] > 0 || $rest['approved_cases'] > 0 || $rest['cashback_amount'] > 0) {
            $rows[] = [
                'employee_id' => null,
                'name' => $isCompany ? 'Outside these branches' : $employee->emp_name.' — own cases',
                'designation' => null,
                ...$rest,
            ];
        }

        return $rows;
    }

    /**
     * The analysis behind one cashback form: the month the case was
     * disbursed in (the month its cashback counts against; the month the
     * form was raised while the case is not disbursed yet), the case's own
     * cashback against its disbursal, and the figures of everyone who filled
     * or owns it on the chain — case owner, Team Leader, Manager, Cluster
     * Manager and whoever filled the form.
     *
     * @return array{as_of: string, month: string, month_label: string, month_basis: string, form: array<string, mixed>, case: array<string, mixed>, people: list<array<string, mixed>>}
     */
    public function forForm(CustomerCashbackRequest $form): array
    {
        $form->loadMissing(['customer', 'aro', 'teamLeader', 'manager', 'clusterManager', 'requester.employee']);

        $customer = $form->customer;
        $disbursedOn = $customer?->disbursal_date;
        $month = ($disbursedOn ?? $form->created_at ?? now())->copy()->startOfMonth();

        $chain = [
            'Case owner' => $form->aro,
            'Team Leader' => $form->teamLeader,
            'Manager' => $form->manager,
            'Cluster Manager' => $form->clusterManager,
            'Filled by' => $form->requester?->employee,
        ];

        $people = [];

        foreach ($chain as $role => $employee) {
            if (! $employee instanceof Employee || self::isCompany($employee)) {
                continue;
            }

            if (isset($people[$employee->id])) {
                $people[$employee->id]['role'] .= ' · '.$role;

                continue;
            }

            $people[$employee->id] = ['role' => $role, ...self::row($employee, $this->figures($employee, $month))];
        }

        $case = $customer
            ? $this->achievement->computeAchievementTotals(Customer::query()->whereKey($customer->getKey()))
            : ['actual' => 0.0, 'cashback' => 0.0];

        return [
            'as_of' => now()->toDateTimeString(),
            'month' => $month->format('Y-m'),
            'month_label' => $month->format('F Y'),
            'month_basis' => $disbursedOn ? 'disbursal' : 'raised',
            'form' => [
                'voucher_no' => $form->voucher_no,
                'type' => $form->type->label(),
                'amount' => $form->effectiveAmount(),
                'status' => $form->status->label(),
            ],
            'case' => [
                'disbursal_amount' => (float) $case['actual'],
                'cashback_amount' => (float) $case['cashback'],
                'cashback_percent' => self::percent((float) $case['cashback'], (float) $case['actual']),
                'disbursed_on' => $disbursedOn?->toDateString(),
            ],
            'people' => array_values($people),
        ];
    }

    /**
     * Cashback forms raised in the month on cases owned in $employee's
     * branch (each counted for the team the case owner was in on the day it
     * was raised; null: the whole company) — the records behind the
     * analysis, each carrying the analysis saved when it was raised.
     *
     * @return Builder<CustomerCashbackRequest>
     */
    public function requestsQuery(?Employee $employee, Carbon $month): Builder
    {
        [$start, $end] = self::range($month);

        $query = CustomerCashbackRequest::query();

        if (self::isCompany($employee)) {
            return $query->whereBetween('customer_cashback_requests.created_at', [$start, $end]);
        }

        return $query->whereHas('customer', fn (Builder $customers): Builder => HierarchyHelper::scopeToBranchDuring(
            $customers,
            $employee,
            $start,
            $end,
            'customers.employee_id',
            'customer_cashback_requests.created_at',
        ));
    }

    /**
     * How the month's cashback requests stand.
     *
     * @return array{raised: int, raised_amount: float, pending: int, pending_amount: float, approved: int, approved_amount: float, paid: int, paid_amount: float, rejected: int, returned_amount: float}
     */
    public function requestSummary(?Employee $employee, Carbon $month): array
    {
        $forms = $this->requestsQuery($employee, $month)->get();
        $approved = $forms->filter(fn (CustomerCashbackRequest $form): bool => $form->isApproved());
        $paid = $approved->filter(fn (CustomerCashbackRequest $form): bool => $form->isPaid());
        $pending = $forms->filter(fn (CustomerCashbackRequest $form): bool => $form->isPending());

        return [
            'raised' => $forms->count(),
            'raised_amount' => self::sumAmounts($forms),
            'pending' => $pending->count(),
            'pending_amount' => self::sumAmounts($pending),
            'approved' => $approved->count(),
            'approved_amount' => self::sumAmounts($approved),
            'paid' => $paid->count(),
            'paid_amount' => self::sumAmounts($paid),
            'rejected' => $forms->filter(fn (CustomerCashbackRequest $form): bool => in_array($form->status, [CashbackRequestStatus::Rejected, CashbackRequestStatus::Retrieved], true))->count(),
            'returned_amount' => round((float) $forms->sum(fn (CustomerCashbackRequest $form): float => $form->returnedAmount()), 2),
        ];
    }

    /**
     * The first and last moment of the month.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(Carbon $month): array
    {
        return [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
    }

    public static function percent(float $part, float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 2) : 0.0;
    }

    /**
     * @return array{cases_punched_in: int, cases_with_cashback: int, cases_with_cashback_percent: float, cashback_amount: float, disbursal_amount: float, approved_cases: int, approval_amount: float, cashback_percent: float, average_cashback: float}
     */
    public static function summarise(int $casesPunchedIn, int $casesWithCashback, float $cashbackAmount, float $disbursalAmount, int $approvedCases, float $approvalAmount): array
    {
        return [
            'cases_punched_in' => $casesPunchedIn,
            'cases_with_cashback' => $casesWithCashback,
            'cases_with_cashback_percent' => self::percent($casesWithCashback, $casesPunchedIn),
            'cashback_amount' => round($cashbackAmount, 2),
            'disbursal_amount' => round($disbursalAmount, 2),
            'approved_cases' => $approvedCases,
            'approval_amount' => round($approvalAmount, 2),
            'cashback_percent' => self::percent($cashbackAmount, $disbursalAmount),
            'average_cashback' => $casesWithCashback > 0 ? round($cashbackAmount / $casesWithCashback, 2) : 0.0,
        ];
    }

    /** The Admin (or no employee) is the whole company. */
    private static function isCompany(?Employee $employee): bool
    {
        return $employee === null || (int) $employee->designation === Employee::DESIGNATION_ADMIN;
    }

    /**
     * Cases disbursed in [$start, $end] that count for $employee — the
     * achievement figures' own cases.
     */
    private function disbursedCases(?Employee $employee, Carbon $start, Carbon $end, ?Employee $within): Builder
    {
        return self::isCompany($employee)
            ? Customer::query()->whereBetween('customers.disbursal_date', [$start, $end])
            : $this->achievement->disbursedCustomersFor($employee, $start, $end, $within);
    }

    /** Cases whose $dateColumn falls in [$start, $end], counted for the team their owner was in that day. */
    private function casesDatedIn(?Employee $employee, string $dateColumn, Carbon $start, Carbon $end, ?Employee $within): Builder
    {
        return self::isCompany($employee)
            ? Customer::query()->whereBetween($dateColumn, [$start, $end])
            : HierarchyHelper::scopeToBranchDuring(Customer::query(), $employee, $start, $end, 'customers.employee_id', $dateColumn, $within);
    }

    private function withSettlement(Builder $customers): Builder
    {
        return $customers->leftJoin('customer_settlements as cs', function ($join): void {
            $join->on('cs.customer_id', '=', 'customers.id')->where('cs.version', 1);
        });
    }

    /**
     * @param  array<string, int|float>  $figures
     * @return array<string, mixed>
     */
    private static function row(Employee $employee, array $figures): array
    {
        return [
            'employee_id' => $employee->id,
            'name' => EmployeeOptions::label($employee),
            'designation' => Employee::designationOptions()[(int) $employee->designation] ?? null,
            ...$figures,
        ];
    }

    /**
     * $total less every row, with the ratios worked out again.
     *
     * @param  array<string, int|float>  $total
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int|float>
     */
    private static function remainder(array $total, array $rows): array
    {
        $left = fn (string $key): float => (float) $total[$key] - (float) collect($rows)->sum($key);

        return self::summarise(
            casesPunchedIn: max(0, (int) round($left('cases_punched_in'))),
            casesWithCashback: max(0, (int) round($left('cases_with_cashback'))),
            cashbackAmount: max(0.0, round($left('cashback_amount'), 2)),
            disbursalAmount: max(0.0, round($left('disbursal_amount'), 2)),
            approvedCases: max(0, (int) round($left('approved_cases'))),
            approvalAmount: max(0.0, round($left('approval_amount'), 2)),
        );
    }

    /**
     * @param  Collection<int, CustomerCashbackRequest>  $forms
     */
    private static function sumAmounts(Collection $forms): float
    {
        return round((float) $forms->sum(fn (CustomerCashbackRequest $form): float => $form->effectiveAmount()), 2);
    }
}
