<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerAssignment extends Model
{
    /**
     * Every remark type a follow-up on an assigned lead can carry.
     *
     * @var array<string, string>
     */
    public const FOLLOW_UP_STATUSES = [
        'Pending' => 'Pending',
        'Call Back' => 'Call Back',
        'Interested' => 'Interested',
        'Not Interested' => 'Not Interested',
        'Busy' => 'Busy',
        'No Response' => 'No Response',
        'Not Eligible' => 'Not Eligible',
        'Eligible for Other Bank' => 'Eligible for Other Bank',
        'Dropped' => 'Dropped',
    ];

    /**
     * Remark types that end the chase, so no next follow-up is expected.
     *
     * @var list<string>
     */
    public const CLOSED_FOLLOW_UP_STATUSES = ['Not Interested', 'Not Eligible', 'Dropped'];

    protected $fillable = [
        'batch_id',
        'customer_id',
        'ai_customer_record_id',
        'ai_document_schema_id',
        'employee_id',
        'assigned_by',
        'opens_count',
        'first_opened_at',
        'last_opened_at',
        'converted_at',
        'reassign_count',
        'last_reassigned_at',
    ];

    protected $casts = [
        'first_opened_at' => 'datetime',
        'last_opened_at' => 'datetime',
        'converted_at' => 'datetime',
        'last_reassigned_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(CustomerAssignmentBatch::class, 'batch_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function aiCustomerRecord()
    {
        return $this->belongsTo(AiCustomerRecord::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(Employee::class, 'assigned_by');
    }

    public function remarks()
    {
        return $this->hasMany(CustomerAssignmentRemark::class)->latest();
    }

    /**
     * The upload template (AI document schema) this lead was extracted with.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AiDocumentSchema::class, 'ai_document_schema_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(CustomerAssignmentTransfer::class)->latest('id');
    }

    /**
     * Follow-ups recorded against this row's lead, correlated to the outer
     * customer_assignments table so it can be used as a subquery. Mirrors
     * latestFollowUp(): AI-record follow-ups until conversion, customer
     * follow-ups after.
     *
     * @return Builder<FollowUp>
     */
    public static function linkedFollowUpsQuery(): Builder
    {
        return FollowUp::query()->where(function (Builder $query): void {
            $query->where(function (Builder $query): void {
                $query->whereNotNull('customer_assignments.ai_customer_record_id')
                    ->whereColumn('follow_ups.ai_customer_record_id', 'customer_assignments.ai_customer_record_id');
            })->orWhere(function (Builder $query): void {
                $query->whereNull('customer_assignments.ai_customer_record_id')
                    ->whereColumn('follow_ups.customer_id', 'customer_assignments.customer_id');
            });
        });
    }

    /**
     * The latest follow-up's value of $column for each row, as a subquery.
     *
     * @return Builder<FollowUp>
     */
    public static function latestFollowUpValueQuery(string $column): Builder
    {
        return self::linkedFollowUpsQuery()
            ->select("follow_ups.{$column}")
            ->orderByDesc('follow_ups.created_at')
            ->orderByDesc('follow_ups.id')
            ->limit(1);
    }

    /**
     * Rows whose latest follow-up carries one of the given remark types.
     * "Pending" also matches rows nobody has followed up yet, the same way
     * the listing badge falls back to Pending.
     *
     * @param  list<string>  $statuses
     */
    public function scopeWhereLatestFollowUpStatus(Builder $query, array $statuses): Builder
    {
        return $query->where(function (Builder $query) use ($statuses): void {
            foreach ($statuses as $status) {
                $query->orWhere(self::latestFollowUpValueQuery('status'), '=', $status);
            }

            if (in_array('Pending', $statuses, true)) {
                $query->orWhereNotExists(self::linkedFollowUpsQuery()->getQuery());
            }
        });
    }

    /**
     * Nobody has opened the lead or logged a single follow-up on it.
     */
    public function scopeUntouched(Builder $query): Builder
    {
        return $query->where('customer_assignments.opens_count', 0)
            ->whereNotExists(self::linkedFollowUpsQuery()->getQuery());
    }

    public function scopeFollowedUp(Builder $query): Builder
    {
        return $query->whereExists(self::linkedFollowUpsQuery()->getQuery());
    }

    /**
     * The next follow-up date has passed and the lead is still open.
     */
    public function scopeOverdueFollowUp(Builder $query): Builder
    {
        return $query->whereNull('customer_assignments.converted_at')
            ->where(self::latestFollowUpValueQuery('next_follow_up_date'), '<', now())
            ->where(function (Builder $query): void {
                foreach (self::CLOSED_FOLLOW_UP_STATUSES as $status) {
                    $query->where(self::latestFollowUpValueQuery('status'), '!=', $status);
                }
            });
    }

    public function recordOpen(): void
    {
        $this->increment('opens_count', 1, [
            'first_opened_at' => $this->first_opened_at ?? now(),
            'last_opened_at' => now(),
        ]);
    }

    public function getStatusAttribute(): string
    {
        return $this->opens_count > 0 ? 'Opened' : 'Pending';
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->customer) {
            return $this->customer->customer_name;
        }

        if ($this->aiCustomerRecord) {
            return $this->aiCustomerRecord->value('customer_name')
                ?? ($this->aiCustomerRecord->schema?->name.' #'.$this->aiCustomerRecord->id);
        }

        return '—';
    }

    public function getTemplateNameAttribute(): ?string
    {
        return $this->template?->name ?? $this->aiCustomerRecord?->schema?->name;
    }

    public function getSourceLabelAttribute(): string
    {
        return $this->customer_id ? 'Customer' : 'AI Record';
    }

    public function latestFollowUp(): ?FollowUp
    {
        if ($this->customer_id) {
            return $this->customer?->followUps()->latest()->first();
        }

        if ($this->ai_customer_record_id) {
            return $this->aiCustomerRecord?->followUps()->latest()->first();
        }

        return null;
    }

    public function latestFollowUpStatus(): ?string
    {
        return $this->latestFollowUp()?->status;
    }

    public function isEligibleForConversion(): bool
    {
        return blank($this->customer_id)
            && filled($this->ai_customer_record_id)
            && $this->latestFollowUpStatus() === 'Interested';
    }
}
