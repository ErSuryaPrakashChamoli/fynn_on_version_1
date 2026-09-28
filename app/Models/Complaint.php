<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use Database\Factories\ComplaintFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A help-desk ticket: a complaint or query anybody in the company raises,
 * routed to a handling team (by role) or to a supervisor of the raiser's
 * choosing, with a resolution deadline set by the priority's SLA.
 *
 * All state changes go through App\Services\HelpDesk\ComplaintService so
 * the thread (complaint_comments) and the bell notifications stay in step
 * with the row; nothing here writes status on its own.
 *
 * @property int $id
 * @property string|null $ticket_no
 * @property int $raised_by
 * @property int|null $on_behalf_of
 * @property int $category_id
 * @property int|null $reason_id
 * @property int $priority_id
 * @property string|null $subject
 * @property string|null $description
 * @property list<string>|null $attachments
 * @property ComplaintStatus $status
 * @property string|null $handler_role
 * @property int|null $assigned_to
 * @property Carbon|null $sla_started_at
 * @property Carbon|null $due_at
 * @property int $escalation_level
 * @property Carbon|null $escalated_at
 * @property int|null $escalated_to
 * @property Carbon|null $first_response_at
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property int $reopened_count
 * @property-read User $raiser
 * @property-read User|null $beneficiary
 * @property-read User|null $assignee
 * @property-read User|null $escalatee
 * @property-read ComplaintCategory $category
 * @property-read ComplaintReason|null $reason
 * @property-read ComplaintPriority $priority
 * @property-read Collection<int, ComplaintComment> $comments
 */
class Complaint extends Model
{
    /** @use HasFactory<ComplaintFactory> */
    use HasFactory;

    public const TICKET_PREFIX = 'TKT-';

    protected $fillable = [
        'ticket_no',
        'raised_by',
        'on_behalf_of',
        'category_id',
        'reason_id',
        'priority_id',
        'subject',
        'description',
        'attachments',
        'status',
        'handler_role',
        'assigned_to',
        'sla_started_at',
        'due_at',
        'escalation_level',
        'escalated_at',
        'escalated_to',
        'first_response_at',
        'resolved_at',
        'resolved_by',
        'resolution_note',
        'closed_at',
        'closed_by',
        'reopened_count',
    ];

    protected $casts = [
        'attachments' => 'array',
        'status' => ComplaintStatus::class,
        'sla_started_at' => 'datetime',
        'due_at' => 'datetime',
        'escalation_level' => 'integer',
        'escalated_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'reopened_count' => 'integer',
    ];

    protected static function booted(): void
    {
        // The ticket number is the id, zero-padded, so it is unique without
        // a lock and sorts the same as the row.
        static::created(function (Complaint $complaint): void {
            if ($complaint->ticket_no === null) {
                $complaint->forceFill(['ticket_no' => self::ticketNumberFor($complaint->id)])->saveQuietly();
            }
        });
    }

    public static function ticketNumberFor(int $id): string
    {
        return self::TICKET_PREFIX.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    /** The team member the ticket was raised for, when not the raiser themselves. */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function escalatee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ComplaintCategory::class, 'category_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(ComplaintReason::class, 'reason_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(ComplaintPriority::class, 'priority_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ComplaintComment::class);
    }

    /** Tickets still being worked on — the SLA clock is running. */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', ComplaintStatus::unresolvedValues());
    }

    /** Unresolved tickets past their deadline. */
    public function scopeOverdue(Builder $query, ?Carbon $now = null): Builder
    {
        return $query->unresolved()->whereNotNull('due_at')->where('due_at', '<', $now ?? now());
    }

    public function isUnresolved(): bool
    {
        return $this->status->isUnresolved();
    }

    public function isOverdue(?Carbon $now = null): bool
    {
        return $this->isUnresolved() && $this->due_at !== null && $this->due_at->lt($now ?? now());
    }

    public function isRaisedBy(User $user): bool
    {
        return (int) $this->raised_by === (int) $user->getKey();
    }

    /** The raiser, or the person it was raised for — both "own" the ticket. */
    public function isOwnedBy(User $user): bool
    {
        return $this->isRaisedBy($user)
            || ($this->on_behalf_of !== null && (int) $this->on_behalf_of === (int) $user->getKey());
    }

    /** "Ravi Caller" or "Tarun Leader (for Ravi Caller)". */
    public function raisedByLabel(): string
    {
        $raiser = $this->raiser?->name ?? '—';

        return $this->beneficiary
            ? $raiser.' (for '.$this->beneficiary->name.')'
            : $raiser;
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->assigned_to !== null && (int) $this->assigned_to === (int) $user->getKey();
    }

    public function isEscalatedTo(User $user): bool
    {
        return $this->escalated_to !== null && (int) $this->escalated_to === (int) $user->getKey();
    }

    /**
     * "Due in 3h" / "Overdue by 2d" / "Resolved on time" — the SLA as a
     * line of text for tables, infolists and notifications.
     */
    public function slaLabel(?Carbon $now = null): string
    {
        $now ??= now();

        if ($this->due_at === null) {
            return 'No deadline';
        }

        if (! $this->isUnresolved()) {
            $finishedAt = $this->resolved_at ?? $this->closed_at ?? $this->updated_at;

            return $finishedAt !== null && $finishedAt->gt($this->due_at)
                ? 'Resolved late by '.$this->due_at->diffForHumans($finishedAt, ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2])
                : 'Resolved on time';
        }

        return $this->due_at->lt($now)
            ? 'Overdue by '.$this->due_at->diffForHumans($now, ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2])
            : 'Due in '.$now->diffForHumans($this->due_at, ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]);
    }

    /** Who the ticket is currently with, as a label. */
    public function handlerLabel(): string
    {
        if ($this->assignee) {
            return $this->assignee->name;
        }

        if ($this->handler_role) {
            return $this->handler_role.' team (unassigned)';
        }

        return 'Unassigned';
    }
}
