<?php

namespace App\Models;

use App\Enums\BureauDecision;
use App\Enums\CibilReportStatus;
use App\Enums\CibilScoreBand;
use Database\Factories\CibilReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One CIBIL pull: who asked for it, the lead or customer file it was made
 * for, the applicant's details as they were typed, and the bureau's answer.
 * Rows are written only by App\Services\Cibil\CibilReportService. The pull
 * itself is never edited afterwards, so the history stays a true record of
 * what was fetched and when; the one later write is the manager's bureau
 * decision on a lead's report, recorded once.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $lead_id
 * @property int|null $customer_assignment_id
 * @property int|null $requested_by
 * @property string $customer_name
 * @property string $pan_number
 * @property string|null $mobile_no
 * @property string|null $email
 * @property Carbon|null $date_of_birth
 * @property string|null $gender
 * @property string|null $pincode
 * @property CibilReportStatus $status
 * @property int|null $score
 * @property string|null $bureau_reference
 * @property array<string, mixed>|null $response_payload
 * @property string|null $error_message
 * @property BureauDecision|null $bureau_decision
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_remarks
 * @property Carbon $consent_given_at
 * @property Carbon|null $fetched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $fetch_count Pulls for this PAN, set by CibilReportService::latestPerApplicant()
 * @property-read Customer|null $customer
 * @property-read Lead|null $lead
 * @property-read CustomerAssignment|null $assignedLead
 * @property-read User|null $requester
 * @property-read User|null $decider
 */
class CibilReport extends Model
{
    /** @use HasFactory<CibilReportFactory> */
    use HasFactory;

    public const GENDERS = [
        'male' => 'Male',
        'female' => 'Female',
        'other' => 'Other',
    ];

    protected $fillable = [
        'customer_id',
        'lead_id',
        'customer_assignment_id',
        'requested_by',
        'customer_name',
        'pan_number',
        'mobile_no',
        'email',
        'date_of_birth',
        'gender',
        'pincode',
        'status',
        'score',
        'bureau_reference',
        'response_payload',
        'error_message',
        'bureau_decision',
        'decided_by',
        'decided_at',
        'decision_remarks',
        'consent_given_at',
        'fetched_at',
    ];

    /**
     * The bureau's response holds the applicant's whole credit history, so
     * it is encrypted at rest and never serialised by accident.
     *
     * @var list<string>
     */
    protected $hidden = [
        'response_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => CibilReportStatus::class,
            'score' => 'integer',
            'date_of_birth' => 'date',
            'response_payload' => 'encrypted:array',
            'bureau_decision' => BureauDecision::class,
            'decided_at' => 'datetime',
            'consent_given_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * A lead from My Leads.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * A lead from Assigned Leads.
     *
     * @return BelongsTo<CustomerAssignment, $this>
     */
    public function assignedLead(): BelongsTo
    {
        return $this->belongsTo(CustomerAssignment::class, 'customer_assignment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The lead or customer file this pull was made for. */
    public function subject(): Lead|CustomerAssignment|Customer|null
    {
        return $this->lead ?? $this->assignedLead ?? $this->customer;
    }

    /** What the pull was made for, for listings: Lead, Assigned lead or Customer. */
    public function subjectType(): ?string
    {
        return match (true) {
            $this->lead_id !== null => 'Lead',
            $this->customer_assignment_id !== null => 'Assigned lead',
            $this->customer_id !== null => 'Customer',
            default => null,
        };
    }

    public function isForLead(): bool
    {
        return $this->lead_id !== null || $this->customer_assignment_id !== null;
    }

    public function scoreBand(): ?CibilScoreBand
    {
        return $this->score === null ? null : CibilScoreBand::fromScore($this->score);
    }

    public function isRequestedBy(User $user): bool
    {
        return $this->requested_by === $user->getKey();
    }
}
