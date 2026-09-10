<?php

namespace App\Filament\Academy\Widgets;

use App\Models\Training\TrainingBatch;
use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingQuizAttempt;
use App\Support\Portal\PortalContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The trainer's headline numbers, scoped to their own tenant.
 */
class TrainerStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return ! app(PortalContext::class)->isTrainee();
    }

    protected function getStats(): array
    {
        $tenantId = app(PortalContext::class)->tenantId();

        $activeBatches = TrainingBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'running')
            ->count();

        $enrollments = TrainingEnrollment::query()->where('tenant_id', $tenantId);

        $totalTrainees = (clone $enrollments)->distinct('trainee_id')->count('trainee_id');
        $averageProgress = (int) round((clone $enrollments)->avg('progress_percentage') ?? 0);
        $completed = (clone $enrollments)->where('status', 'completed')->count();

        $attempts = TrainingQuizAttempt::query()
            ->whereHas('enrollment', fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('status', 'completed');

        $averageScore = (int) round((clone $attempts)->avg('percentage') ?? 0);
        $passed = (clone $attempts)->where('passed', true)->count();
        $failed = (clone $attempts)->where('passed', false)->count();

        return [
            Stat::make('Active Batches', $activeBatches)
                ->description('Currently running')
                ->color('primary'),

            Stat::make('Trainees', $totalTrainees)
                ->description("{$completed} completed a course")
                ->color('info'),

            Stat::make('Average Progress', $averageProgress.'%')
                ->description('Across every enrollment')
                ->color($averageProgress >= 70 ? 'success' : 'warning'),

            Stat::make('Average Score', $averageScore.'%')
                ->description("{$passed} passed · {$failed} failed")
                ->color($averageScore >= 70 ? 'success' : 'warning'),
        ];
    }
}
