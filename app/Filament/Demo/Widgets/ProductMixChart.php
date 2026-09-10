<?php

namespace App\Filament\Demo\Widgets;

use App\Filament\Demo\Widgets\Concerns\UsesDemoMetrics;
use Filament\Widgets\ChartWidget;

class ProductMixChart extends ChartWidget
{
    use UsesDemoMetrics;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Product Distribution';

    protected ?string $description = 'Applications by loan product.';

    protected ?string $maxHeight = '280px';

    public function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return $this->emptyChart();
        }

        $distribution = $this->metrics()->productDistribution($tenant);

        return [
            'datasets' => [
                [
                    'label' => 'Applications',
                    'data' => array_values($distribution),
                    'backgroundColor' => array_slice($this->palette, 0, max(1, count($distribution))),
                    'borderWidth' => 0,
                ],
            ],
            'labels' => array_keys($distribution),
        ];
    }
}
