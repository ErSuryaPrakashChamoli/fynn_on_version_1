<?php

namespace App\Models;

use App\Enums\ContinuityCoverageType;
use App\Enums\ContinuityScopeType;
use App\Enums\JourneyAccessType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The Team Continuity / Backup Access record. Despite the column names
 * (delegating_manager_id/acting_manager_id, kept as-is to avoid a
 * disruptive rename), this represents ANY employee at ANY hierarchy level
 * (Caller/Team Leader/Manager/Cluster Manager/Business Head) delegating
 * temporary operational access to an eligible backup — see
 * CustomerJourneyDelegationService for the generalized validation and
 * CustomerJourneyAccessService for how access is resolved.
 */
class CustomerJourneyDelegation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'delegating_manager_id',
        'acting_manager_id',
        'start_at',
        'end_at',
        'modules',
        'coverage_type',
        'scope_type',
        'access_type',
        'is_admin_override',
        'reason',
        'status',
        'requires_approval',
        'approved_by',
        'approved_at',
        'created_by',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'modules' => 'array',
        'coverage_type' => ContinuityCoverageType::class,
        'scope_type' => ContinuityScopeType::class,
        'access_type' => JourneyAccessType::class,
        'is_admin_override' => 'boolean',
        'requires_approval' => 'boolean',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function delegatingManager()
    {
        return $this->belongsTo(Employee::class, 'delegating_manager_id');
    }

    public function actingManager()
    {
        return $this->belongsTo(Employee::class, 'acting_manager_id');
    }

    /**
     * Clearer aliases now that this covers any hierarchy level, not just
     * Managers — same underlying columns/relation as above.
     */
    public function originalEmployee()
    {
        return $this->delegatingManager();
    }

    public function backupEmployee()
    {
        return $this->actingManager();
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Delegations that are genuinely granting access right now. Status alone
     * is never trusted for authorization — the time window is re-checked
     * every time so a delayed status-sync job can never over-grant access.
     */
    public function scopeActiveAt(Builder $query, ?Carbon $moment = null): Builder
    {
        $moment ??= now();

        return $query->where('status', self::STATUS_ACTIVE)
            ->where('start_at', '<=', $moment)
            ->where('end_at', '>=', $moment);
    }

    public function isActiveAt(?Carbon $moment = null): bool
    {
        $moment ??= now();

        return $this->status === self::STATUS_ACTIVE
            && $this->start_at <= $moment
            && $this->end_at >= $moment;
    }

    /**
     * Does this rule's coverage_type apply to a record created at
     * $createdAt? "Existing" = created before this rule started; "new" =
     * created at/after it started. This is the audit/eligibility
     * distinction between Engine 1 and Engine 2 — both are still resolved
     * by the same live access check, just gated by this one comparison.
     */
    public function coversRecordCreatedAt(?Carbon $createdAt): bool
    {
        if (! $createdAt) {
            return true;
        }

        $isNew = $createdAt->greaterThanOrEqualTo($this->start_at);

        return $isNew
            ? $this->coverage_type->coversNew()
            : $this->coverage_type->coversExisting();
    }

    /**
     * Display-only bucket for the four statuses called out in the spec
     * (Pending/Active/Upcoming/Expired/Cancelled). This never drives
     * authorization decisions — see isActiveAt()/scopeActiveAt().
     */
    public function displayStatus(): string
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return 'Cancelled';
        }

        if ($this->status === self::STATUS_REJECTED) {
            return 'Rejected';
        }

        if ($this->status === self::STATUS_PENDING) {
            return 'Pending';
        }

        $now = now();

        if ($this->end_at < $now) {
            return 'Expired';
        }

        if ($this->start_at > $now) {
            return 'Upcoming';
        }

        return 'Active';
    }
}
