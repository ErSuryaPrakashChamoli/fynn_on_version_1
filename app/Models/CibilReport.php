<?php

namespace App\Models;

use App\Enums\BureauDecision;
use App\Enums\CibilConsentMethod;
use App\Enums\CibilPdfStatus;
use App\Enums\CibilReportStatus;
use App\Enums\CibilRequestType;
use App\Enums\CibilScoreBand;
use App\Enums\CreditBureau;
use App\Support\CibilMask;
use App\Support\Demo\DemoContext;
use Database\Factories\CibilReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One CIBIL request: what was asked for (score, full report or PDF), who
 * asked, the lead or customer file it was made for, the applicant's details
 * as they were sent, the customer's consent, and the bureau's answer. Rows
 * are written only by App\Services\Cibil\CibilReportService. The answer is
 * never edited afterwards, so the history stays a true record of what was
 * fetched and when. The only later writes are bookkeeping around it: the
 * manager's bureau decision on a lead's report (recorded once), the link to
 * the customer file a lead converts into, saving the PDF, and the
 * verification of a request whose outcome was uncertain.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $lead_id
 * @property int|null $customer_assignment_id
 * @property CibilRequestType $request_type
 * @property CreditBureau $bureau
 * @property string|null $request_reason
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
 * @property string|null $bureau_reference Surepass client_id
 * @property int|null $provider_status_code
 * @property string|null $provider_message_code
 * @property array<string, mixed>|null $response_payload
 * @property CibilPdfStatus|null $pdf_status
 * @property string|null $pdf_path
 * @property string|null $pdf_link
 * @property string|null $error_message
 * @property BureauDecision|null $bureau_decision
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_remarks
 * @property Carbon $consent_given_at
 * @property string|null $consent_purpose
 * @property CibilConsentMethod|null $consent_method
 * @property string|null $consent_reference
 * @property Carbon|null $fetched_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $fetch_count Pulls for this PAN, set by CibilReportService::latestPerApplicant()
 * @property-read Customer|null $customer
 * @property-read Lead|null $lead
 * @property-read CustomerAssignment|null $assignedLead
 * @property-read User|null $requester
 * @property-read User|null $decider
 * @property-read User|null $resolver
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
        'request_type',
        'bureau',
        'request_reason',
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
        'provider_status_code',
        'provider_message_code',
        'response_payload',
        'pdf_status',
        'pdf_path',
        'pdf_link',
        'error_message',
        'bureau_decision',
        'decided_by',
        'decided_at',
        'decision_remarks',
        'consent_given_at',
        'consent_purpose',
        'consent_method',
        'consent_reference',
        'fetched_at',
        'completed_at',
        'resolved_at',
        'resolved_by',
        'resolution_note',
    ];

    /**
     * The bureau's response holds the applicant's whole credit history and
     * the PDF link is a signed URL to it, so both are encrypted at rest and
     * never serialised by accident; nor is where the PDF is stored.
     *
     * @var list<string>
     */
    protected $hidden = [
        'response_payload',
        'pdf_link',
        'pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'request_type' => CibilRequestType::class,
            'bureau' => CreditBureau::class,
            'status' => CibilReportStatus::class,
            'score' => 'integer',
            'date_of_birth' => 'date',
            'provider_status_code' => 'integer',
            'response_payload' => 'encrypted:array',
            'pdf_status' => CibilPdfStatus::class,
            'pdf_link' => 'encrypted',
            'bureau_decision' => BureauDecision::class,
            'decided_at' => 'datetime',
            'consent_given_at' => 'datetime',
            'consent_method' => CibilConsentMethod::class,
            'fetched_at' => 'datetime',
            'completed_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    /**
     * Who verified this request after its outcome was uncertain.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
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

    /** The provider may have processed this request, and nobody has verified it yet. */
    public function isUnresolvedUncertain(): bool
    {
        return $this->status === CibilReportStatus::Uncertain && $this->resolved_at === null;
    }

    public function hasPdf(): bool
    {
        return $this->pdf_status === CibilPdfStatus::Available && filled($this->pdf_path);
    }

    /** The app's own authorised route to the saved PDF (never the provider's link). */
    public function pdfUrl(): ?string
    {
        if (! $this->hasPdf()) {
            return null;
        }

        return route(DemoContext::isActive() ? 'demo.cibil-reports.pdf' : 'cibil-reports.pdf', ['cibilReport' => $this->getKey()]);
    }

    public function maskedPan(): string
    {
        return CibilMask::pan($this->pan_number);
    }

    public function maskedMobile(): ?string
    {
        return CibilMask::mobile($this->mobile_no);
    }
}
