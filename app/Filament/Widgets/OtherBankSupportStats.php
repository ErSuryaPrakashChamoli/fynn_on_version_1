<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\OtherBankSupportService;
use App\Support\SelectedMonth;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The Other Bank Support user's dashboard: they sit outside the reporting
 * tree, so the hierarchy widgets have nothing to show them.
 */
class OtherBankSupportStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getHeading(): ?string
    {
        return '🏦 Other Bank Support';
    }

    protected function getDescription(): ?string
    {
        return 'Other-bank business for the month, your target and your incentive.';
    }

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $performance = app(OtherBankSupportService::class)->performanceFor($user, SelectedMonth::current());
        $summary = $performance['summary'];
        $hasTarget = $performance['target'] > 0;

        return [
            Stat::make('Other Bank Business', indianAmount($summary['other_bank_achievement']))
                ->description(number_format($summary['share_percentage'], 2).'% of '.indianAmount($summary['total_achievement']).' total business')
                ->icon('heroicon-o-building-library')
                ->color('info'),

            Stat::make('My Target', $hasTarget ? indianAmount($performance['target']) : 'Not set')
                ->description($hasTarget
                    ? number_format($performance['percentage'], 2).'% achieved'
                    : 'The Admin has not set this month\'s target')
                ->icon('heroicon-o-flag')
                ->color($hasTarget && $performance['percentage'] >= 100 ? 'success' : 'warning'),

            Stat::make('Incentive Earned', indianAmount($performance['incentive']))
                ->description(match (true) {
                    $performance['next_slab'] !== null => indianAmount($performance['remaining_to_next']).' more for the next slab',
                    $performance['slab'] !== null => 'Top slab reached',
                    default => 'No incentive slabs defined',
                })
                ->icon('heroicon-o-currency-rupee')
                ->color('success'),
        ];
    }

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && OtherBankSupportService::isScopedSupportUser($user);
    }
}
