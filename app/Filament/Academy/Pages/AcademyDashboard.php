<?php

namespace App\Filament\Academy\Pages;

use App\Filament\Academy\Widgets\AssessmentOverviewChart;
use App\Filament\Academy\Widgets\BatchProgressTable;
use App\Filament\Academy\Widgets\MyLearningPath;
use App\Filament\Academy\Widgets\TraineeStatsOverview;
use App\Filament\Academy\Widgets\TrainerStatsOverview;
use App\Filament\Academy\Widgets\UpcomingSessionsTable;
use App\Support\Portal\PortalContext;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * One dashboard URL, two dashboards.
 *
 * Trainers and trainees both land on /academy, but see entirely
 * different widget sets. Doing this by swapping the widget list — rather
 * than by two pages and a redirect — means the trainee never has a
 * trainer URL to discover, and the widgets themselves stay small and
 * single-purpose.
 */
class AcademyDashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $title = 'Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    public function getWidgets(): array
    {
        return app(PortalContext::class)->isTrainee()
            ? [
                TraineeStatsOverview::class,
                MyLearningPath::class,
            ]
            : [
                TrainerStatsOverview::class,
                BatchProgressTable::class,
                UpcomingSessionsTable::class,
                AssessmentOverviewChart::class,
            ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    public function getSubheading(): ?string
    {
        $context = app(PortalContext::class);

        if ($context->isTrainee()) {
            return 'Your training progress at a glance.';
        }

        return 'Batch delivery, trainee progress and assessment outcomes.';
    }

    public function getTitle(): string
    {
        $name = auth()->user()?->name;

        return app(PortalContext::class)->isTrainee() && $name
            ? "Welcome, {$name}"
            : 'Trainer Dashboard';
    }
}
