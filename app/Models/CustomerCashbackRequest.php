<?php

namespace App\Models;

use App\Enums\CashbackPaymentMode;
use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackRequestStatus;
use App\Enums\CashbackReturnStatus;
use App\Enums\CashbackType;
use Database\Factories\CustomerCashbackRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The digital cashback form (paper "Acknowledgment" voucher) on a customer
 * file. Every write goes through App\Services\CustomerCashbackService,
 * which also keeps customers.cashback in step with the file's live form.
 *
 * @property int $id
 * @property string|null $voucher_no
 * @property int $customer_id
 * @property int|null $revision_of_id
 * @property int|null $revision_no
 * @property CashbackType $type
 * @property numeric $amount
 * @property numeric|null $approved_amount
 * @property CashbackRequestStatus $status
 * @property string|null $remarks
 * @property CashbackPaymentMode|null $payment_mode
 * @property string|null $upi_platform
 * @property string|null $payee_upi
 * @property string|null $payee_account_no
 * @property string|null $payee_ifsc
 * @property int|null $aro_employee_id
 * @property int|null $team_leader_employee_id
 * @property int|null $manager_employee_id
 * @property int|null $cluster_manager_employee_id
 * @property int|null $supervisor_employee_id
 * @property Carbon|null $supervisor_due_at
 * @property Carbon|null $escalated_at
 * @property int|null $requested_by
 * @property int|null $manager_approved_by
 * @property Carbon|null $manager_approved_at
 * @property string|null $manager_note
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $approval_note
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property int|null $retrieved_by
 * @property Carbon|null $retrieved_at
 * @property string|null $retrieval_reason
 * @property CashbackReturnStatus|null $return_status
 * @property numeric|null $returned_amount
 * @property Carbon|null $return_date
 * @property CashbackPaymentMode|null $return_mode
 * @property string|null $return_reference
 * @property string|null $return_reason
 * @property int|null $return_requested_by
 * @property Carbon|null $return_requested_at
 * @property int|null $return_reviewed_by
 * @property Carbon|null $return_reviewed_at
 * @property string|null $return_review_note
 * @property CashbackPaymentStatus $payment_status
 * @property string|null $transaction_id
 * @property Carbon|null $payment_date
 * @property int|null $paid_by
 * @property string|null $paid_by_name
 * @property string|null $payment_remarks
 * @property int|null $payment_updated_by
 * @property Carbon|null $payment_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 * @property-read CustomerCashbackRequest|null $original
 * @property-read Collection<int, CustomerCashbackRequest> $revisions
 * @property-read Employee|null $aro
 * @property-read Employee|null $teamLeader
 * @property-read Employee|null $manager
 * @property-read Employee|null $clusterManager
 * @property-read Employee|null $supervisor
 * @property-read User|null $requester
 * @property-read User|null $managerApprover
 * @property-read User|null $approver
 * @property-read User|null $rejecter
 * @property-read User|null $retriever
 * @property-read User|null $returnRequester
 * @property-read User|null $returnReviewer
 * @property-read User|null $payer
 * @property-read User|null $paymentUpdater
 */
class CustomerCashbackRequest extends Model
{
    /** @use HasFactory<CustomerCashbackRequestFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'voucher_no',
        'customer_id',
        'revision_of_id',
        'revision_no',
        'type',
        'amount',
        'approved_amount',
        'status',
        'remarks',
        'payment_mode',
        'upi_platform',
        'payee_upi',
        'payee_account_no',
        'payee_ifsc',
        'aro_employee_id',
        'team_leader_employee_id',
        'manager_employee_id',
        'cluster_manager_employee_id',
        'supervisor_employee_id',
        'supervisor_due_at',
        'escalated_at',
        'requested_by',
        'manager_approved_by',
        'manager_approved_at',
        'manager_note',
        'approved_by',
        'approved_at',
        'approval_note',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'retrieved_by',
        'retrieved_at',
        'retrieval_reason',
        'return_status',
        'returned_amount',
        'return_date',
        'return_mode',
        'return_reference',
        'return_reason',
        'return_requested_by',
        'return_requested_at',
        'return_reviewed_by',
        'return_reviewed_at',
        'return_review_note',
        'payment_status',
        'transaction_id',
        'payment_date',
        'paid_by',
        'paid_by_name',
        'payment_remarks',
        'payment_updated_by',
        'payment_updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CashbackType::class,
            'status' => CashbackRequestStatus::class,
            'payment_status' => CashbackPaymentStatus::class,
            'payment_mode' => CashbackPaymentMode::class,
            'return_status' => CashbackReturnStatus::class,
            'return_mode' => CashbackPaymentMode::class,
            'amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'returned_amount' => 'decimal:2',
            'return_date' => 'date',
            'return_requested_at' => 'datetime',
            'return_reviewed_at' => 'datetime',
            'supervisor_due_at' => 'datetime',
            'escalated_at' => 'datetime',
            'manager_approved_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'retrieved_at' => 'datetime',
            'payment_date' => 'date',
            'payment_updated_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('cashback');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The paid form this one revises (a second payment on the same cashback). */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_of_id');
    }

    /**
     * @return HasMany<CustomerCashbackRequest, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'revision_of_id')->orderBy('revision_no');
    }

    public function isRevision(): bool
    {
        return $this->revision_of_id !== null;
    }

    /**
     * "Revision 1 of CB-000012". Reads the relation by name: inside the
     * model `$this->original` is Eloquent's own original-attributes array.
     */
    public function revisionLabel(): ?string
    {
        return $this->isRevision()
            ? 'Revision '.$this->revision_no.' of '.$this->getRelationValue('original')?->voucher_no
            : null;
    }

    public function aro(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'aro_employee_id');
    }

    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'team_leader_employee_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function clusterManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cluster_manager_employee_id');
    }

    /** Whoever filled the form reports to them; they approve it first, within the TAT. */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_employee_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function managerApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_approved_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function retriever(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retrieved_by');
    }

    /** Who asked for the customer's return of the cashback to be recorded. */
    public function returnRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_requested_by');
    }

    /** The Admin who approved or rejected the return. */
    public function returnReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_reviewed_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function paymentUpdater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_updated_by');
    }

    /** Pending or approved — the form that holds the file's cashback figure. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', CashbackRequestStatus::liveValues());
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CashbackRequestStatus::PendingManager->value,
            CashbackRequestStatus::PendingAdmin->value,
        ]);
    }

    /** Waiting for the supervisor with the 24-hour turnaround already over. */
    public function scopeOverdueForSupervisor(Builder $query, ?Carbon $now = null): Builder
    {
        return $query
            ->where('status', CashbackRequestStatus::PendingManager->value)
            ->whereNotNull('supervisor_due_at')
            ->where('supervisor_due_at', '<=', $now ?? now());
    }

    public function isPastSupervisorTat(?Carbon $now = null): bool
    {
        return $this->status === CashbackRequestStatus::PendingManager
            && $this->supervisor_due_at !== null
            && $this->supervisor_due_at->lte($now ?? now());
    }

    /** "Due in 5 hours" / "Overdue by 2 hours" while the supervisor has it. */
    public function supervisorTatLabel(): ?string
    {
        if ($this->status !== CashbackRequestStatus::PendingManager || $this->supervisor_due_at === null) {
            return null;
        }

        return $this->isPastSupervisorTat()
            ? 'TAT over — moving to Admin'
            : 'Due '.$this->supervisor_due_at->diffForHumans(['parts' => 2]);
    }

    /**
     * The voucher's signature cell for one person on the file's chain (TL,
     * Manager, Cluster Manager): signed when they filled the form or signed
     * it off as supervisor; otherwise, if it was waiting on them, whether
     * it still is or moved to the Admin after the TAT.
     *
     * @return array{text: string, tone: 'signed'|'waiting'|'missed'}|null
     */
    public function chainSignature(?int $employeeId): ?array
    {
        if ($employeeId === null) {
            return null;
        }

        if ((int) $this->requester?->employee_id === $employeeId) {
            return ['text' => 'Filled & signed · '.$this->created_at?->format('d/m/Y, h:i A'), 'tone' => 'signed'];
        }

        if ($this->manager_approved_at !== null && (int) $this->managerApprover?->employee_id === $employeeId) {
            return ['text' => 'Digitally signed · '.$this->manager_approved_at->format('d/m/Y, h:i A'), 'tone' => 'signed'];
        }

        if ((int) $this->supervisor_employee_id !== $employeeId) {
            return null;
        }

        return match (true) {
            $this->escalated_at !== null => ['text' => 'Not signed within 24 h — moved to Admin '.$this->escalated_at->format('d/m/Y, h:i A'), 'tone' => 'missed'],
            $this->status === CashbackRequestStatus::PendingManager => ['text' => 'Awaiting approval · due '.$this->supervisor_due_at?->format('d/m/Y, h:i A'), 'tone' => 'waiting'],
            default => null,
        };
    }

    public function isLive(): bool
    {
        return in_array($this->status, CashbackRequestStatus::live(), true);
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isApproved(): bool
    {
        return $this->status === CashbackRequestStatus::Approved;
    }

    public function isPaid(): bool
    {
        return $this->payment_status === CashbackPaymentStatus::Paid;
    }

    /** The amount given: the Admin's figure once approved, else the one asked for. */
    public function effectiveAmount(): float
    {
        return (float) ($this->approved_amount ?? $this->amount);
    }

    /** The customer gave some or all of a paid cashback back, and the Admin approved it. */
    public function isReturned(): bool
    {
        return $this->return_status === CashbackReturnStatus::Approved;
    }

    /** A return has been asked for and waits for the Admin. */
    public function hasPendingReturn(): bool
    {
        return $this->return_status === CashbackReturnStatus::Pending;
    }

    /** The amount returned — counted only once the Admin approved the return. */
    public function returnedAmount(): float
    {
        return $this->isReturned() ? (float) ($this->returned_amount ?? 0) : 0.0;
    }

    public function isFullyReturned(): bool
    {
        return $this->isReturned() && $this->returnedAmount() >= $this->effectiveAmount();
    }

    /** What the case carries from this form: the amount given less anything the customer returned. */
    public function netAmount(): float
    {
        return round(max(0.0, $this->effectiveAmount() - $this->returnedAmount()), 2);
    }
}
