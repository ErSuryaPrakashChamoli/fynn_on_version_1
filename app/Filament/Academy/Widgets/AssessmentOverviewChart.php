<?php

namespace App\Filament\Academy\Widgets;

use App\Models\Training\TrainingQuizAttempt;
use App\Support\Portal\PortalContext;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pass/fail/in-progress split for every attempt in the tenant.
 */
class AssessmentOverviewChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Assessment Outcomes';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return ! app(PortalContext::class)->isTrainee();
    }

    public function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $base = fn (): Builder => TrainingQuizAttempt::query()
            ->whereHas(
                'enrollment',
                fn (Builder $query) => $query->where('tenant_id', app(PortalContext::class)->tenantId())
            );

        $passed = $base()->where('status', 'completed')->where('passed', true)->count();
        $failed = $base()->where('status', 'completed')->where('passed', false)->count();
        $pending = $base()->where('status', 'in_progress')->count();

        return [
            'datasets' => [
                [
                    'label' => 'Attempts',
                    'data' => [$passed, $failed, $pending],
                    'backgroundColor' => ['#8FBE00', '#F43F5E', '#F59E0B'],
                    'borderWidth' => 0,
                ],
            ],
            'labels' => ['Passed', 'Failed', 'In Progress'],
        ];
    }
}
