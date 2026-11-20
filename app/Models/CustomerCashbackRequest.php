<?php

namespace App\Models;

use App\Enums\CashbackPaymentMode;
use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackRequestStatus;
use App\Enums\CashbackType;
use Database\Factories\CustomerCashbackRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property CashbackPaymentStatus $payment_status
 * @property string|null $transaction_id
 * @property Carbon|null $payment_date
 * @property int|null $paid_by
 * @property string|null $payment_remarks
 * @property int|null $payment_updated_by
 * @property Carbon|null $payment_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 * @property-read Employee|null $aro
 * @property-read Employee|null $teamLeader
 * @property-read Employee|null $manager
 * @property-read User|null $requester
 * @property-read User|null $managerApprover
 * @property-read User|null $approver
 * @property-read User|null $rejecter
 * @property-read User|null $retriever
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
        'payment_status',
        'transaction_id',
        'payment_date',
        'paid_by',
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
            'amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
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

    /** The amount the file carries: the Admin's figure once approved, else the one asked for. */
    public function effectiveAmount(): float
    {
        return (float) ($this->approved_amount ?? $this->amount);
    }
}
