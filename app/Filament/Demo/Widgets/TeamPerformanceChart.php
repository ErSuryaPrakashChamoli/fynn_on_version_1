<?php

namespace App\Filament\Demo\Widgets;

use App\Filament\Demo\Widgets\Concerns\UsesDemoMetrics;
use Filament\Widgets\ChartWidget;

class TeamPerformanceChart extends ChartWidget
{
    use UsesDemoMetrics;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Team Performance';

    protected ?string $description = 'Disbursed value per executive, in ₹ lakh.';

    protected ?string $maxHeight = '300px';

    public function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return $this->emptyChart();
        }

        $performance = $this->metrics()->teamPerformance($tenant);

        return [
            'datasets' => [
                [
                    'label' => '₹ lakh',
                    'data' => array_map(
                        fn (int $amount): float => round($amount / 100000, 2),
                        array_values($performance),
                    ),
                    'backgroundColor' => $this->palette[0],
                    'borderRadius' => 4,
                ],
            ],
            'labels' => array_keys($performance),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
