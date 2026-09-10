<?php

namespace App\Filament\Demo\Widgets;

use App\Filament\Demo\Widgets\Concerns\UsesDemoMetrics;
use Filament\Widgets\ChartWidget;

/**
 * The lead-to-disbursal funnel. Rendered as a horizontal bar chart
 * because a true funnel shape adds nothing a reader can measure.
 */
class LeadFunnelChart extends ChartWidget
{
    use UsesDemoMetrics;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Application Funnel';

    protected ?string $description = 'Where cases fall out of the pipeline.';

    protected ?string $maxHeight = '280px';

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

        $funnel = $this->metrics()->funnel($tenant);

        return [
            'datasets' => [
                [
                    'label' => 'Cases',
                    'data' => array_values($funnel),
                    'backgroundColor' => $this->palette[0],
                    'borderRadius' => 4,
                ],
            ],
            'labels' => array_keys($funnel),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true]],
        ];
    }
}
