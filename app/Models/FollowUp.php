<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $customer_id
 * @property int $employee_id
 * @property string $follow_up_type
 * @property string $remarks
 * @property Carbon|null $next_follow_up_date
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 * @property-read Employee $employee
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereEmployeeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereFollowUpType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereNextFollowUpDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereRemarks($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FollowUp whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class FollowUp extends Model
{
    //
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'ai_customer_record_id',
        'lead_id',
        'employee_id',
        'follow_up_type',
        'remarks',
        'next_follow_up_date',
        'status',
        'email',
        'bank_id',
    ];

    protected $casts = [
        'next_follow_up_date' => 'datetime',
    ];

    /**
     * The columns that together identify which prospect a follow-up hangs
     * off. Exactly one of them is ever set on a given row.
     *
     * @var array<int, string>
     */
    public const SUBJECT_COLUMNS = [
        'customer_id',
        'ai_customer_record_id',
        'lead_id',
    ];

    /**
     * Narrows a query to one row per prospect — the most recently logged
     * follow-up. Every follow-up interaction inserts a new row, so without
     * this a prospect whose next-follow-up date has been revised twice shows
     * up on the calendar three times: once on each superseded date and once
     * on the current one. The newest row is the only one that still describes
     * when the prospect is actually due; the older rows remain in the table
     * as the follow-up log (see {@see historyForSubject()}).
     */
    public function scopeLatestPerSubject(Builder $query): Builder
    {
        return $query->whereIn(
            $query->qualifyColumn('id'),
            fn ($subQuery) => $subQuery
                ->from('follow_ups', 'newest_follow_ups')
                ->selectRaw('MAX(newest_follow_ups.id)')
                ->groupBy(
                    'newest_follow_ups.customer_id',
                    'newest_follow_ups.ai_customer_record_id',
                    'newest_follow_ups.lead_id',
                )
        );
    }

    /**
     * Narrows a query to follow-ups that have a date to be due on. A
     * follow-up closed out as Converted/Lost carries no next date and so
     * belongs on no calendar day at all.
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('next_follow_up_date'));
    }

    /**
     * Adds a `follow_up_count` column holding how many times this prospect
     * has been followed up in total, so a latest-per-subject listing can
     * still show the size of the log behind each row without an N+1.
     *
     * COALESCE rather than a NULL-safe operator keeps the comparison working
     * on both MySQL (`<=>`) and the SQLite used by the test suite (`IS`).
     */
    public function scopeWithFollowUpCount(Builder $query): Builder
    {
        $conditions = collect(self::SUBJECT_COLUMNS)
            ->map(fn (string $column) => "COALESCE(prospect_follow_ups.{$column}, 0) = COALESCE(follow_ups.{$column}, 0)")
            ->implode(' AND ');

        return $query
            ->select('follow_ups.*')
            ->selectRaw("(SELECT COUNT(*) FROM follow_ups AS prospect_follow_ups WHERE {$conditions}) AS follow_up_count");
    }

    /**
     * Constrains a query to the follow-ups belonging to the same prospect as
     * the given row.
     */
    public function scopeForSameSubjectAs(Builder $query, self $followUp): Builder
    {
        foreach (self::SUBJECT_COLUMNS as $column) {
            $value = $followUp->getAttribute($column);

            $value === null
                ? $query->whereNull($query->qualifyColumn($column))
                : $query->where($query->qualifyColumn($column), $value);
        }

        return $query;
    }

    /**
     * A stable identifier for the prospect a follow-up belongs to, used to
     * group rows from different prospects apart when several are loaded
     * together.
     */
    public function getSubjectKeyAttribute(): string
    {
        foreach (self::SUBJECT_COLUMNS as $column) {
            if (filled($this->getAttribute($column))) {
                return $column.':'.$this->getAttribute($column);
            }
        }

        return 'unassigned:'.$this->id;
    }

    /**
     * Loads the follow-up log for every prospect in the given set in a single
     * query, keyed by subject so a listing can render each row's history
     * without falling into an N+1.
     *
     * @param  iterable<int, self>  $followUps
     * @return Collection<string, Collection<int, self>>
     */
    public static function historiesFor(iterable $followUps): Collection
    {
        $followUps = collect($followUps);

        if ($followUps->isEmpty()) {
            return collect();
        }

        return static::query()
            ->where(function (Builder $query) use ($followUps) {
                foreach (self::SUBJECT_COLUMNS as $column) {
                    $ids = $followUps->pluck($column)->filter()->unique()->values();

                    if ($ids->isNotEmpty()) {
                        $query->orWhereIn($column, $ids);
                    }
                }
            })
            ->with('employee')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (self $followUp) => $followUp->subject_key);
    }

    /**
     * The prospect's full follow-up log, oldest first — every interaction
     * that was logged and, with it, every next-follow-up date that was ever
     * set for them.
     *
     * @return Collection<int, self>
     */
    public function historyForSubject(): Collection
    {
        return static::query()
            ->forSameSubjectAs($this)
            ->with('employee')
            ->orderBy('id')
            ->get();
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function aiCustomerRecord()
    {
        return $this->belongsTo(AiCustomerRecord::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->customer) {
            return $this->customer->customer_name;
        }

        if ($this->aiCustomerRecord) {
            return $this->aiCustomerRecord->value('customer_name')
                ?? ('AI Record #'.$this->aiCustomerRecord->id);
        }

        if ($this->lead) {
            return $this->lead->customer_name;
        }

        return '—';
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    // public function bank()
    // {
    //     return $this->belongsTo(Bank::class);
    // }

    public function bank()
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }
}
