<?php

namespace App\Filament\Academy\Resources\Concerns;

use App\Support\Portal\PortalContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared guard for every trainer-only Academy resource.
 *
 * Two things every one of them must do, in one place so none can forget:
 *
 *  - canAccess(): trainees are refused outright. Filament calls this for
 *    the navigation item AND for the route, so a trainee typing the URL
 *    gets a 403 rather than a hidden-but-reachable page.
 *  - getEloquentQuery(): the base query is narrowed to the caller's
 *    tenant before any filter, sort or search is applied, so no table
 *    interaction can widen it.
 *
 * Resources whose model has no tenant_id of its own override
 * scopeQueryToTenant() to constrain through their parent instead.
 */
trait TrainerResource
{
    public static function canAccess(): bool
    {
        $context = app(PortalContext::class);

        return $context->isTrainer() || ($context->isInternalUser() && auth()->user()?->hasRole('Admin'));
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeQueryToTenant(parent::getEloquentQuery());
    }

    protected static function scopeQueryToTenant(Builder $query): Builder
    {
        return $query->where(
            $query->getModel()->getTable().'.tenant_id',
            app(PortalContext::class)->tenantId()
        );
    }

    protected static function currentTenantId(): ?int
    {
        return app(PortalContext::class)->tenantId();
    }
}
