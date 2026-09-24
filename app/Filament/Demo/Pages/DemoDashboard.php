<?php

namespace App\Filament\Demo\Pages;

use App\Filament\Demo\Widgets\DisbursalTrendChart;
use App\Filament\Demo\Widgets\LeadFunnelChart;
use App\Filament\Demo\Widgets\LeadTrendChart;
use App\Filament\Demo\Widgets\PipelineStats;
use App\Filament\Demo\Widgets\ProductMixChart;
use App\Filament\Demo\Widgets\TeamPerformanceChart;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * The sandbox's landing page — the first thing a prospect sees, so it
 * carries the headline numbers and the five charts that show the shape
 * of the product rather than a bare table.
 */
class DemoDashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $title = 'Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    public function getWidgets(): array
    {
        return [
            PipelineStats::class,
            LeadTrendChart::class,
            LeadFunnelChart::class,
            ProductMixChart::class,
            DisbursalTrendChart::class,
            TeamPerformanceChart::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    public function getSubheading(): ?string
    {
        return 'Demo Administration — this is a demonstration environment. '
            .'All records created here are stored in the Demo database, never in the live system.';
    }
}
