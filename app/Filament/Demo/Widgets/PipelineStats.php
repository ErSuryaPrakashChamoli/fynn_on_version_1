<?php

namespace App\Filament\Demo\Widgets;

use App\Services\Demo\DemoMetricsService;
use App\Support\Portal\PortalContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The headline pipeline numbers.
 *
 * Amounts are rendered in crore because that is how the figures are
 * actually discussed on a sales call — a raw rupee total is unreadable
 * at this magnitude.
 */
class PipelineStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $tenant = app(PortalContext::class)->tenant();

        if ($tenant === null) {
            return [];
        }

        $metrics = app(DemoMetricsService::class)->headline($tenant);

        return [
            Stat::make('Total Leads', number_format($metrics['total_leads']))
                ->description(number_format($metrics['new_leads']).' new this cycle')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary'),

            Stat::make('Qualified Leads', number_format($metrics['qualified_leads']))
                ->description('Passed eligibility screening')
                ->color('info'),

            Stat::make('Applications', number_format($metrics['applications']))
                ->description(number_format($metrics['sanctioned']).' sanctioned')
                ->color('warning'),

            Stat::make('Disbursed', number_format($metrics['disbursed']))
                ->description('Cases funded')
                ->color('success'),

            Stat::make('Disbursal Amount', static::crore($metrics['disbursed_amount']))
                ->description('Total funded value')
                ->color('success'),

            Stat::make('Sanctioned Amount', static::crore($metrics['sanctioned_amount']))
                ->description('Approved value')
                ->color('info'),

            Stat::make('Conversion Rate', $metrics['conversion_rate'].'%')
                ->description('Lead to disbursal')
                ->color($metrics['conversion_rate'] >= 10 ? 'success' : 'warning'),

            Stat::make('Active Team', number_format($metrics['employees']))
                ->description(number_format($metrics['customers']).' customers managed')
                ->color('gray'),
        ];
    }

    protected static function crore(int $amount): string
    {
        return '₹'.number_format($amount / 10000000, 2).' Cr';
    }
}
