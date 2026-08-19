<?php

namespace App\Services\Settlement;

use App\Models\CustomerSettlement;
use App\Services\AchievementCalculatorService;

class SalesImpactService
{
    public function captureBefore(CustomerSettlement $settlement): void
    {
        $employee = $settlement->customer?->employee;

        if (! $employee) {
            return;
        }

        $performance = app(AchievementCalculatorService::class)->getPerformance($employee);

        $settlement->achievement_before = $performance['count_achievement'] ?? 0;
        $settlement->incentive_before = $performance['incentive'] ?? 0;
    }

    public function captureAfter(CustomerSettlement $settlement): void
    {
        $employee = $settlement->customer?->employee;

        if (! $employee) {
            return;
        }

        $performance = app(AchievementCalculatorService::class)->getPerformance($employee);

        $settlement->achievement_after = $performance['count_achievement'] ?? 0;
        $settlement->incentive_after = $performance['incentive'] ?? 0;
        $settlement->achievement_difference =
            (float) $settlement->achievement_after - (float) $settlement->achievement_before;
        $settlement->incentive_difference =
            (float) $settlement->incentive_after - (float) $settlement->incentive_before;
        $settlement->sales_incentive = $settlement->incentive_after;
        $settlement->impact_calculated_at = now();
    }
}
