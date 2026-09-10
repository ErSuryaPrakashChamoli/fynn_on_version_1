<?php

namespace App\Models\Demo;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Base for every sandbox model.
 *
 * These map onto the demo_* tables, which have no relationship of any
 * kind to the production leads/customers/employees tables — no shared
 * table, no shared key space, no foreign key crossing between them. A
 * Demo panel resource bound to one of these classes therefore cannot
 * name a production row even if its query were written wrongly.
 *
 * The tenant scope on top is belt-and-braces for the eventual case of
 * more than one sandbox tenant (per-prospect demos), not the primary
 * isolation mechanism.
 */
abstract class DemoModel extends Model
{
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, Tenant|int|null $tenant): Builder
    {
        return $query->where(
            $this->getTable().'.tenant_id',
            $tenant instanceof Tenant ? $tenant->getKey() : $tenant
        );
    }

    /**
     * Every demo table name is prefixed, which the reset service relies
     * on to know exactly which tables it may wipe.
     */
    public static function tableName(): string
    {
        return (new static)->getTable();
    }
}
