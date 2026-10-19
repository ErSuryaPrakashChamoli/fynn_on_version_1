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
use App\Support\CibilDashboardFilters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Entry point for CIBIL Management: request counts by type, bureau and
 * outcome, success and failure rates, provider time, files checked, and the
 * newest requests and failures — all counted from the cibil_reports rows the
 * viewer can see (every row for the Admin), narrowed by the filters on top
 * (period, bureau, request type, customer vs lead; see
 * CibilDashboardFilters). Nothing is estimated: there is no cost figure
 * because no pricing is configured.
 */
class CibilDashboard extends Page
{
    use HasFiltersForm;

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

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components(CibilDashboardFilters::components());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('filtersForm'),

            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                CibilStats::class,
                CibilRecentRequests::class,
                CibilRecentFailures::class,
            ])),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetchReport')
                ->label('Fetch bureau report')
                ->icon('heroicon-o-magnifying-glass')
                ->visible(fn (): bool => CibilReportResource::canCreate())
                ->url(fn (): string => CibilReportResource::getUrl('create')),

            Action::make('employeeActivity')
                ->label('Employee activity')
                ->icon('heroicon-o-user-group')
                ->color('gray')
                ->visible(fn (): bool => CibilEmployeeActivity::canAccess())
                ->url(fn (): string => CibilEmployeeActivity::getUrl()),

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
