<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class JourneyTakeover extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPES = [
        'manager_unavailable',
        'emergency',
        'sla_breach',
        'manager_on_leave',
        'manager_resigned',
        'manager_terminated',
        'escalation',
        'other',
    ];

    protected $fillable = [
        'customer_id',
        'original_manager_id',
        'takeover_by_id',
        'takeover_type',
        'reason',
        'modules',
        'status',
        'started_at',
        'ended_at',
        'created_by',
        'ended_by',
    ];

    protected $casts = [
        'modules' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function originalManager()
    {
        return $this->belongsTo(Employee::class, 'original_manager_id');
    }

    public function takeoverBy()
    {
        return $this->belongsTo(Employee::class, 'takeover_by_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function scopeActiveForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('takeover_by_id', $employeeId)
            ->where('status', self::STATUS_ACTIVE);
    }

    public function grantsModule(string $moduleValue): bool
    {
        return $this->modules === null || in_array($moduleValue, $this->modules, true);
    }
}
