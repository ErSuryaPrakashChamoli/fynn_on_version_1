<?php

namespace App\Models\Training;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared tenant scoping helper for training models that carry a
 * tenant_id directly.
 *
 * Deliberately NOT a global scope: a global scope reading the session
 * would silently return an empty set in queue jobs, console commands and
 * seeders, which hides bugs rather than preventing them. Every
 * tenant-sensitive query in the Academy panel calls forTenant()
 * explicitly, and the panel resources funnel through a single
 * getEloquentQuery() override each so there is one place to audit.
 */
trait BelongsToTenant
{
    public function scopeForTenant(Builder $query, Tenant|int|null $tenant): Builder
    {
        return $query->where(
            $this->getTable().'.tenant_id',
            $tenant instanceof Tenant ? $tenant->getKey() : $tenant
        );
    }
}
