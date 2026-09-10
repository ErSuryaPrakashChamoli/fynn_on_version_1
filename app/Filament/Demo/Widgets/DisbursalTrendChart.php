<?php

namespace App\Filament\Demo\Widgets;

use App\Filament\Demo\Widgets\Concerns\UsesDemoMetrics;
use Filament\Widgets\ChartWidget;

class DisbursalTrendChart extends ChartWidget
{
    use UsesDemoMetrics;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Monthly Disbursal';

    protected ?string $description = 'Disbursed value in ₹ lakh.';

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

        $trend = $this->metrics()->disbursalTrend($tenant);

        return [
            'datasets' => [
                [
                    'label' => '₹ lakh',
                    'data' => $trend['values'],
                    'backgroundColor' => $this->palette[1],
                    'borderRadius' => 4,
                ],
            ],
            'labels' => $trend['labels'],
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
