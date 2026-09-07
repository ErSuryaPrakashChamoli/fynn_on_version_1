<?php

namespace App\Models;

use App\Enums\InactivityRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A "this person has gone inactive" ticket raised from inside the Daily
 * Commitment module, used ONLY by that module's monthly-target gate.
 *
 * While a ticket is open (pending) or approved, MonthlyTargetGate stops
 * demanding a monthly commitment target for the employee, so one person
 * who has stopped turning up cannot hold their whole team out of the
 * panel. Approving the ticket is what actually takes the employee off
 * the rolls (employees.exit_status).
 *
 * @property int $employee_id
 * @property Carbon $month
 * @property InactivityRequestStatus $status
 * @property-read Employee $employee
 */
class EmployeeInactivityRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'month',
        'requested_by',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'month' => 'date',
        'status' => InactivityRequestStatus::class,
        'reviewed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The `month` cast writes "Y-m-d H:i:s", so a bare "Y-m-d" equality
     * match silently misses every row — the same trap the monthly target
     * table carries. Always look a month up through here.
     */
    public function scopeForMonth(Builder $query, Carbon $month): Builder
    {
        return $query->whereDate('month', $month->copy()->startOfMonth()->toDateString());
    }

    /** Tickets that currently let the month's target be skipped. */
    public function scopeSkipping(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InactivityRequestStatus::Pending->value,
            InactivityRequestStatus::Approved->value,
        ]);
    }

    public function isPending(): bool
    {
        return $this->status === InactivityRequestStatus::Pending;
    }
}
