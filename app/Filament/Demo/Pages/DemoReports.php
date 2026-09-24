<?php

namespace App\Filament\Demo\Pages;

use App\Models\Demo\DemoApplication;
use App\Models\Demo\DemoUser;
use App\Services\Demo\DemoMetricsService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The sandbox's reporting view — funnel, product mix, lender mix and
 * team leaderboard in one page, all from the demo database.
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
        return Filament::auth()->user() instanceof DemoUser;
    }

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        $metrics = app(DemoMetricsService::class);

        return [
            'headline' => $metrics->headline(),
            'funnel' => $metrics->funnel(),
            'products' => $metrics->productDistribution(),
            'team' => $metrics->teamPerformance(),
            'lenders' => DemoApplication::query()
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
