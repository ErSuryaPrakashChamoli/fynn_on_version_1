<?php

namespace App\Filament\Academy\Widgets;

use App\Models\Training\TrainingCertificate;
use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingQuizAttempt;
use App\Support\Portal\PortalContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The trainee's own numbers.
 *
 * Every query here is filtered by the authenticated user's id, not by
 * anything taken from the request — there is no id a trainee could
 * substitute to read someone else's figures.
 */
class TraineeStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return app(PortalContext::class)->isTrainee();
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $enrollments = TrainingEnrollment::query()->ownedBy($user);

        $assigned = (clone $enrollments)->count();
        $completed = (clone $enrollments)->where('status', 'completed')->count();
        $overall = (int) round((clone $enrollments)->avg('progress_percentage') ?? 0);

        $averageScore = (int) round(
            TrainingQuizAttempt::query()
                ->ownedBy($user)
                ->where('status', 'completed')
                ->avg('percentage') ?? 0
        );

        $certificates = TrainingCertificate::query()->ownedBy($user)->count();

        return [
            Stat::make('Overall Progress', $overall.'%')
                ->description("{$completed} of {$assigned} courses complete")
                ->color($overall >= 70 ? 'success' : 'primary'),

            Stat::make('Assigned Courses', $assigned)
                ->description('Keep going')
                ->color('info'),

            Stat::make('Average Quiz Score', $averageScore.'%')
                ->description($averageScore >= 60 ? 'Above pass mark' : 'Below pass mark')
                ->color($averageScore >= 60 ? 'success' : 'warning'),

            Stat::make('Certificates', $certificates)
                ->description('Earned so far')
                ->color('success'),
        ];
    }
}
