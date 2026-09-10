<?php

namespace App\Filament\Demo\Resources\Concerns;

use App\Support\Portal\PortalContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared guard for every sandbox resource.
 *
 * The isolation that matters is already structural — each of these
 * resources is bound to a demo_* model, and those tables share nothing
 * with the production schema. What this trait adds is the second layer:
 * only a demo portal user may reach the resource at all, and even within
 * the sandbox the query is pinned to the caller's own demo tenant, so
 * per-prospect sandboxes stay separate from each other.
 *
 * Deletes are refused panel-wide (DemoRecordPolicy says the same): a
 * prospect clicking around must not be able to empty the dataset they
 * are being shown mid-demonstration.
 */
trait SandboxResource
{
    public static function canAccess(): bool
    {
        return app(PortalContext::class)->isDemo();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return $query->where(
            $query->getModel()->getTable().'.tenant_id',
            app(PortalContext::class)->tenantId()
        );
    }
}
