<?php

namespace App\Filament\Demo\Widgets;

use App\Filament\Demo\Widgets\Concerns\UsesDemoMetrics;
use Filament\Widgets\ChartWidget;

class LeadTrendChart extends ChartWidget
{
    use UsesDemoMetrics;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Lead Trend';

    protected ?string $description = 'Leads created per month.';

    protected ?string $maxHeight = '280px';

    public function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return $this->emptyChart();
        }

        $trend = $this->metrics()->leadTrend($tenant);

        return [
            'datasets' => [
                [
                    'label' => 'Leads',
                    'data' => $trend['values'],
                    'borderColor' => $this->palette[0],
                    'backgroundColor' => 'rgba(166, 217, 0, 0.15)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
            'labels' => $trend['labels'],
        ];
    }
}
