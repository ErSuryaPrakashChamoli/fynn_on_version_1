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
 */
class EmployeePerformanceMetricsService
{
    public function __construct(
        private readonly AchievementCalculatorService $achievementCalculator,
    ) {}

    public function rawMetrics(Employee $employee, Carbon $start, Carbon $end): array
    {
        $employeeIds = $employee->designation === Employee::DESIGNATION_ADMIN
            ? Employee::query()->pluck('id')
            : HierarchyHelper::subordinateIds($employee);

        $otpCount = $this->otpCount($employeeIds, $start, $end);
        $eligibleOtpCount = $this->otpCount($employeeIds, $start, $end, onlyEligible: true);

        $loginCount = $this->stageCount($employeeIds, $this->stageStatus('login_count'), $start, $end);
        $approvalCount = $this->stageCount($employeeIds, $this->stageStatus('approval_count'), $start, $end);
        [$disbursalCount, $disbursalCustomerIds] = $this->stageCountWithCustomerIds(
            $employeeIds,
            $this->stageStatus('disbursal_count'),
            $start,
            $end
        );
        $droppedCount = $this->stageCount($employeeIds, $this->stageStatus('dropped_count'), $start, $end);
        $notApprovedCount = $this->stageCount($employeeIds, $this->stageStatus('not_approved_count'), $start, $end);

        $disbursalAmount = $this->disbursalAmount($disbursalCustomerIds);

        [$presentDays, $screenTimeSeconds] = $this->attendance($employeeIds, $start, $end);
        $calendarWorkingDays = PerformancePeriod::workingDays($start, $end);
        $workingDaySlots = $calendarWorkingDays * max($employeeIds->count(), 1);

        [$targetAmount, $actualAchievement, $countAchievement] = $this->achievement($employee, $employeeIds, $start, $end);

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

    private function otpCount(Collection $employeeIds, Carbon $start, Carbon $end, bool $onlyEligible = false): int
    {
        $query = Customer::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('created_at', [$start, $end]);

        if ($onlyEligible) {
            $query->where('eligibility_status', 'eligible');
        }

        return $query->count();
    }

    private function stageCount(Collection $employeeIds, string $statusValue, Carbon $start, Carbon $end): int
    {
        return CustomerStageHistory::query()
            ->join('users', 'users.id', '=', 'customer_stage_histories.user_id')
            ->whereIn('users.employee_id', $employeeIds)
            ->where('customer_stage_histories.status_value', $statusValue)
            ->whereBetween('customer_stage_histories.created_at', [$start, $end])
            ->count();
    }

    /**
     * @return array{0: int, 1: Collection<int, int>}
     */
    private function stageCountWithCustomerIds(Collection $employeeIds, string $statusValue, Carbon $start, Carbon $end): array
    {
        $customerIds = CustomerStageHistory::query()
            ->join('users', 'users.id', '=', 'customer_stage_histories.user_id')
            ->whereIn('users.employee_id', $employeeIds)
            ->where('customer_stage_histories.status_value', $statusValue)
            ->whereBetween('customer_stage_histories.created_at', [$start, $end])
            ->pluck('customer_stage_histories.customer_id');

        return [$customerIds->count(), $customerIds];
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
    private function attendance(Collection $employeeIds, Carbon $start, Carbon $end): array
    {
        $sessions = UserLoginSession::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('login_at', [$start, $end])
            ->get(['employee_id', 'login_at', 'screen_time_seconds']);

        $presentDays = $sessions
            ->groupBy('employee_id')
            ->sum(fn (Collection $group) => $group->pluck('login_at')->map->toDateString()->unique()->count());

        return [$presentDays, (int) $sessions->sum('screen_time_seconds')];
    }

    /**
     * @return array{0: float, 1: float, 2: float} [target, actual (gross), count achievement (net)]
     */
    private function achievement(Employee $employee, Collection $employeeIds, Carbon $start, Carbon $end): array
    {
        $isCurrentCalendarMonth = $start->isSameDay(now()->copy()->startOfMonth())
            && $end->isSameDay(now()->copy()->endOfMonth());

        // For the current month, defer entirely to AchievementCalculatorService
        // so this module's numbers always match the existing dashboards.
        if ($isCurrentCalendarMonth) {
            $performance = $this->achievementCalculator->getPerformance($employee);

            return [$performance['target'], $performance['actual'], $performance['count_achievement']];
        }

        // For any other historical/future period, reuse the same
        // authoritative engines AchievementCalculatorService uses for the
        // current month — computeAchievementTotals() for the bank-aware
        // deduction formula, and getTargetForPeriod() for the hierarchy
        // target (joining/exit-date rules and the ₹30L top-up), so a given
        // month never produces a different target depending on whether
        // it's being viewed as "current" or as a historical period.
        $customers = Customer::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('customers.created_at', [$start, $end]);

        $totals = $this->achievementCalculator->computeAchievementTotals($customers);

        $actual = $totals['actual'];
        $countAchievement = $totals['count_achievement'];

        $target = $this->achievementCalculator->getTargetForPeriod($employee, $start, $end);

        return [$target, $actual, $countAchievement];
    }
}
