<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class HierarchyTransferLog extends Model
{
    protected $fillable = [
        'source_cluster_manager_id',
        'target_cluster_manager_id',
        'transfer_type',
        'selected_employee_ids',
        'affected_employee_ids',
        'affected_count',
        'effective_date',
        'performed_by',
        'remarks',
    ];

    protected $casts = [
        'selected_employee_ids' => 'array',
        'affected_employee_ids' => 'array',
        'effective_date' => 'date',
        'affected_count' => 'integer',
    ];

    public function sourceClusterManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'source_cluster_manager_id');
    }

    public function targetClusterManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'target_cluster_manager_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function scopeForCluster(Builder $query, int $clusterManagerId): Builder
    {
        return $query->where(function (Builder $query) use ($clusterManagerId) {
            $query
                ->where('source_cluster_manager_id', $clusterManagerId)
                ->orWhere('target_cluster_manager_id', $clusterManagerId);
        });
    }
}
