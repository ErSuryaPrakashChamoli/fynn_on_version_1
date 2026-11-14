<?php

namespace App\Services\Performance;

use App\Models\Customer;
use App\Models\CustomerStageHistory;
use App\Models\Employee;
use App\Models\UserLoginSession;
use App\Services\AchievementCalculatorService;
use App\Support\HierarchyHelper;
use App\Support\Performance\PerformancePeriod;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Computes the raw performance metrics (see config('performance.stages')
 * and App\Support\Performance\MetricRegistry for the full key list) for
 * one employee over an arbitrary date range.
 *
 * Scoping follows the same convention as AchievementCalculatorService:
 * a Caller sees only their own cases, a Team Leader/Manager/Cluster
 * Manager sees their whole subordinate tree rolled up (via
 * HierarchyHelper::subordinateIds()), and Admin sees the company.
 *
 * Attribution: OTP/eligible-OTP are credited to the case's owning
 * employee (customers.employee_id) — the same column every existing
 * dashboard widget scopes by; Customer::createdBy()/created_by is dead
 * code (no such column exists on `customers`). Login/Approval/Disbursal/Dropped are credited
 * to whoever performed that stage transition (customer_stage_histories
 * .user_id), not the case's current owner — so reassigning a case later
 * doesn't move credit for work already done.
 *
 * Team scope follows the reporting line of each day: a metric dated on a
 * day somebody was in the team counts for it (HierarchyHelper::
 * scopeToBranchDuring), so a reporting change never moves past work from
 * the old team to the new one.
 */
class EmployeePerformanceMetricsService
{
    public function __construct(
        private readonly AchievementCalculatorService $achievementCalculator,
    ) {}

    public function rawMetrics(Employee $employee, Carbon $start, Carbon $end): array
    {
        $isCompany = $employee->designation === Employee::DESIGNATION_ADMIN;

        $employeeIds = $isCompany
            ? Employee::query()->pluck('id')
            : HierarchyHelper::subordinateIdsDuring($employee, $start, $end);

        /**
         * Rows owned by the team and dated inside the period: for the
         * company every employee, for anybody else each row checked against
         * the team its owner was in on its date.
         */
        $scope = fn (Builder|QueryBuilder $query, string $employeeColumn, string $dateColumn): Builder|QueryBuilder => $isCompany
            ? $query->whereIn($employeeColumn, $employeeIds)->whereBetween($dateColumn, [$start, $end])
            : HierarchyHelper::scopeToBranchDuring($query, $employee, $start, $end, $employeeColumn, $dateColumn);

        $otpCount = $this->otpCount($scope);
        $eligibleOtpCount = $this->otpCount($scope, onlyEligible: true);

        $loginCount = $this->stageCount($scope, $this->stageStatus('login_count'));
        $approvalCount = $this->stageCount($scope, $this->stageStatus('approval_count'));
        [$disbursalCount, $disbursalCustomerIds] = $this->stageCountWithCustomerIds(
            $scope,
            $this->stageStatus('disbursal_count'),
        );
        $droppedCount = $this->stageCount($scope, $this->stageStatus('dropped_count'));
        $notApprovedCount = $this->stageCount($scope, $this->stageStatus('not_approved_count'));

        $disbursalAmount = $this->disbursalAmount($disbursalCustomerIds);

        [$presentDays, $screenTimeSeconds] = $this->attendance($scope);
        $calendarWorkingDays = PerformancePeriod::workingDays($start, $end);
        $workingDaySlots = $calendarWorkingDays * max($employeeIds->count(), 1);

        [$targetAmount, $actualAchievement, $countAchievement] = $this->achievement($employee, $scope, $start, $end);

        return [
            'otp_count' => $otpCount,
            'eligible_otp_count' => $eligibleOtpCount,
            'login_count' => $loginCount,
            'approval_count' => $approvalCount,
            'disbursal_count' => $disbursalCount,
            'disbursal_amount' => $disbursalAmount,
            'dropped_count' => $droppedCount,
            'not_approved_count' => $notApprovedCount,
            'target_amount' => (float) $targetAmount,
            'actual_achievement' => (float) $actualAchievement,
            'count_achievement' => (float) $countAchievement,
            'present_days' => $presentDays,
            'working_days' => $workingDaySlots,
            'screen_time_hours' => round($screenTimeSeconds / 3600, 1),
        ];
    }

    private function stageStatus(string $metricKey): string
    {
        $journeyStatus = config("performance.stages.{$metricKey}");

        return 'Moved to '.ucfirst(str_replace('_', ' ', $journeyStatus));
    }

    /**
     * @param  Closure(Builder|QueryBuilder, string, string): (Builder|QueryBuilder)  $scope
     */
    private function otpCount(Closure $scope, bool $onlyEligible = false): int
    {
        $query = $scope(Customer::query(), 'customers.employee_id', 'customers.created_at');

        if ($onlyEligible) {
            $query->where('eligibility_status', 'eligible');
        }

        return $query->count();
    }

    /**
     * @param  Closure(Builder|QueryBuilder, string, string): (Builder|QueryBuilder)  $scope
     */
    private function stageCount(Closure $scope, string $statusValue): int
    {
        return $this->stageHistories($scope, $statusValue)->count();
    }

    /**
     * @param  Closure(Builder|QueryBuilder, string, string): (Builder|QueryBuilder)  $scope
     * @return array{0: int, 1: Collection<int, int>}
     */
    private function stageCountWithCustomerIds(Closure $scope, string $statusValue): array
    {
        $customerIds = $this->stageHistories($scope, $statusValue)
            ->pluck('customer_stage_histories.customer_id');

        return [$customerIds->count(), $customerIds];
    }

    /**
     * @param  Closure(Builder|QueryBuilder, string, string): (Builder|QueryBuilder)  $scope
     */
    private function stageHistories(Closure $scope, string $statusValue): Builder
    {
        return $scope(
            CustomerStageHistory::query()
                ->join('users', 'users.id', '=', 'customer_stage_histories.user_id')
                ->where('customer_stage_histories.status_value', $statusValue),
            'users.employee_id',
            'customer_stage_histories.created_at',
        );
    }

    private function disbursalAmount(Collection $customerIds): float
    {
        if ($customerIds->isEmpty()) {
            return 0.0;
        }

        $totals = Customer::query()
            ->whereIn('customers.id', $customerIds)
            ->leftJoin('customer_settlements as cs', function ($join) {
                $join->on('cs.customer_id', '=', 'customers.id')->where('cs.version', 1);
            })
            ->selectRaw('SUM(CASE WHEN cs.mis_disbursal_amount IS NOT NULL THEN cs.mis_disbursal_amount ELSE customers.sanctioned_loan_amount END) as total')
            ->first();

        return (float) ($totals->total ?? 0);
    }

    /**
     * @return array{0: int, 1: int} [distinct present-days summed per member, total screen-time seconds]
     */
    private function attendance(Closure $scope): array
    {
        $sessions = $scope(UserLoginSession::query(), 'employee_id', 'login_at')
            ->get(['employee_id', 'login_at', 'screen_time_seconds']);

        $presentDays = $sessions
            ->groupBy('employee_id')
            ->sum(fn (Collection $group) => $group->pluck('login_at')->map->toDateString()->unique()->count());

        return [$presentDays, (int) $sessions->sum('screen_time_seconds')];
    }

    /**
     * @return array{0: float, 1: float, 2: float} [target, actual (gross), count achievement (net)]
     */
    private function achievement(Employee $employee, Closure $scope, Carbon $start, Carbon $end): array
    {
        $isSingleCalendarMonth = $start->isSameDay($start->copy()->startOfMonth())
            && $end->isSameDay($start->copy()->endOfMonth());

        // For a single calendar month, defer entirely to
        // AchievementCalculatorService — passing THIS report's own $start
        // as the reference month explicitly, never the ambient global month
        // selector — so this module's numbers always match the existing
        // dashboards for that exact month, even when the report's own
        // period and the global selector currently disagree.
        if ($isSingleCalendarMonth) {
            $performance = $this->achievementCalculator->getPerformance($employee, $start);

            return [$performance['target'], $performance['actual'], $performance['count_achievement']];
        }

        // For any other (multi-month/partial-month) period, reuse the same
        // authoritative engines AchievementCalculatorService uses for a
        // single month — computeAchievementTotals() for the bank-aware
        // deduction formula, and getTargetForPeriod() for the hierarchy
        // target (joining/exit-date rules and the ₹30L top-up), so a given
        // period never produces a different target depending on how it's
        // framed.
        $customers = $scope(Customer::query(), 'customers.employee_id', 'customers.created_at');

        $totals = $this->achievementCalculator->computeAchievementTotals($customers);

        $actual = $totals['actual'];
        $countAchievement = $totals['count_achievement'];

        $target = $this->achievementCalculator->getTargetForPeriod($employee, $start, $end);

        return [$target, $actual, $countAchievement];
    }
}
