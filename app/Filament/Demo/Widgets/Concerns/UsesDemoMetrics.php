<?php

namespace App\Filament\Demo\Widgets\Concerns;

use App\Services\Demo\DemoMetricsService;

/**
 * Shared accessor for the sandbox chart widgets.
 *
 * Every chart reads from the same DemoMetricsService, which only ever
 * queries the demo database.
 */
trait UsesDemoMetrics
{
    /**
     * The brand's chart palette, in the order series are assigned.
     *
     * @var list<string>
     */
    protected array $palette = ['#A6D900', '#38BDF8', '#F59E0B', '#8B5CF6', '#F43F5E', '#14B8A6'];

    protected function metrics(): DemoMetricsService
    {
        return app(DemoMetricsService::class);
    }
}
