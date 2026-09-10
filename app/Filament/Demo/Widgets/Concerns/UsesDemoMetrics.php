<?php

namespace App\Filament\Demo\Widgets\Concerns;

use App\Models\Tenant;
use App\Services\Demo\DemoMetricsService;
use App\Support\Portal\PortalContext;

/**
 * Shared accessor for the sandbox chart widgets.
 *
 * Every chart asks the same two questions — "which tenant?" and "what
 * are its numbers?" — and DemoMetricsService memoizes the answer per
 * request, so six widgets on one dashboard do not each re-run the same
 * aggregate queries.
 */
trait UsesDemoMetrics
{
    /**
     * The brand's chart palette, in the order series are assigned.
     *
     * @var list<string>
     */
    protected array $palette = ['#A6D900', '#38BDF8', '#F59E0B', '#8B5CF6', '#F43F5E', '#14B8A6'];

    protected function tenant(): ?Tenant
    {
        return app(PortalContext::class)->tenant();
    }

    protected function metrics(): DemoMetricsService
    {
        return app(DemoMetricsService::class);
    }

    /**
     * An empty Chart.js payload, for when the sandbox has not been
     * seeded yet — returning nothing at all makes the widget throw.
     *
     * @return array<string, mixed>
     */
    protected function emptyChart(): array
    {
        return ['datasets' => [], 'labels' => []];
    }
}
