<?php

namespace App\Filament\Demo\Pages;

use App\Models\Demo\DemoApplication;
use App\Services\Demo\DemoMetricsService;
use App\Support\Portal\PortalContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The sandbox's reporting view — funnel, product mix, lender mix and
 * team leaderboard in one page, all from demo_* tables.
 */
class DemoReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Reports';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.demo.pages.reports';

    public static function canAccess(): bool
    {
        return app(PortalContext::class)->isDemo();
    }

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        $tenant = app(PortalContext::class)->tenant();

        if ($tenant === null) {
            return ['funnel' => [], 'products' => [], 'team' => [], 'lenders' => [], 'headline' => []];
        }

        $metrics = app(DemoMetricsService::class);

        return [
            'headline' => $metrics->headline($tenant),
            'funnel' => $metrics->funnel($tenant),
            'products' => $metrics->productDistribution($tenant),
            'team' => $metrics->teamPerformance($tenant),
            'lenders' => DemoApplication::query()
                ->where('demo_applications.tenant_id', $tenant->getKey())
                ->where('demo_applications.status', 'disbursed')
                ->join('demo_banks', 'demo_banks.id', '=', 'demo_applications.demo_bank_id')
                ->selectRaw('demo_banks.name as lender, count(*) as cases, sum(demo_applications.disbursed_amount) as amount')
                ->groupBy('demo_banks.name')
                ->orderByDesc('amount')
                ->get()
                ->all(),
        ];
    }
}
