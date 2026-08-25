<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\DailyCommitmentStats;
use App\Filament\Widgets\DashboardFollowUpCalendarWidget;
use App\Filament\Widgets\IncentiveStats;
use App\Filament\Widgets\ManagerPPPStats;
use App\Filament\Widgets\PerformanceStats;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Filament's stock Dashboard renders every panel-discovered widget
 * (Filament\Pages\Dashboard::getWidgets() just returns Filament::getWidgets()),
 * which is how AssignedLeadFollowUpCalendarWidget ended up on "/admin" purely
 * as a side effect of ->discoverWidgets(). Overriding getWidgets() here with
 * an explicit list swaps that widget for the combined
 * DashboardFollowUpCalendarWidget on the Dashboard only — the original
 * widget and its dedicated "Lead Follow-Up Calendar" page are untouched.
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        // getWidgetsSchemaComponents() renders widgets in this exact array
        // order (it doesn't re-sort by each widget's $sort), so this list
        // is ordered to match each widget's own $sort value and preserve
        // the Dashboard's existing visual order: the calendar first (as
        // AssignedLeadFollowUpCalendarWidget's default sort of -1 already
        // placed it), then PerformanceStats (1), DailyCommitmentStats /
        // IncentiveStats (3), ManagerPPPStats (4).
        return [
            DashboardFollowUpCalendarWidget::class,
            PerformanceStats::class,
            DailyCommitmentStats::class,
            IncentiveStats::class,
            ManagerPPPStats::class,
        ];
    }
}
