<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Filament\Resources\CibilRequests\CibilRequestResource;
use App\Filament\Widgets\CibilRecentFailures;
use App\Filament\Widgets\CibilRecentRequests;
use App\Filament\Widgets\CibilStats;
use App\Models\CibilSetting;
use App\Services\Cibil\CibilReportService;
use App\Support\CibilAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Entry point for CIBIL Management: request counts by type and outcome,
 * the newest requests and the newest failures — all from the cibil_reports
 * rows the viewer can see (every row for the Admin).
 */
class CibilDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'CIBIL Management';

    protected static ?string $navigationLabel = 'CIBIL Dashboard';

    protected static ?string $title = 'CIBIL Dashboard';

    protected static ?string $slug = 'cibil-dashboard';

    protected static ?int $navigationSort = 1;

    /** Requests the app gave up waiting for are counted as uncertain, not as still pending. */
    public function mount(): void
    {
        app(CibilReportService::class)->expireStalePending();
    }

    public function getSubheading(): ?string
    {
        if (! CibilSetting::current()->requests_enabled) {
            return 'CIBIL requests are switched off — nothing is being sent to the bureau.';
        }

        return null;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CibilStats::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            CibilRecentRequests::class,
            CibilRecentFailures::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetchReport')
                ->label('Fetch CIBIL report')
                ->icon('heroicon-o-magnifying-glass')
                ->visible(fn (): bool => CibilReportResource::canCreate())
                ->url(fn (): string => CibilReportResource::getUrl('create')),

            Action::make('requests')
                ->label('All requests')
                ->icon('heroicon-o-queue-list')
                ->color('gray')
                ->url(fn (): string => CibilRequestResource::getUrl('index')),

            Action::make('settings')
                ->label('Settings')
                ->icon('heroicon-o-adjustments-vertical')
                ->color('gray')
                ->visible(fn (): bool => CibilSettings::canAccess())
                ->url(fn (): string => CibilSettings::getUrl()),
        ];
    }

    public static function canAccess(): bool
    {
        return CibilAccess::canUse(Filament::auth()->user());
    }
}
