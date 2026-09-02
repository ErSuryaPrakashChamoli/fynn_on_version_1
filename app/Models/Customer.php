<?php

namespace App\Models;

use App\Enums\JourneyModule;
use App\Services\Journey\CustomerJourneyAccessService;
// use Spatie\Activitylog\Traits\LogsActivity;
// use Spatie\Activitylog\LogOptions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Throwable;

/**
 * @property int $id
 * @property string $customer_name
 * @property string $mobile_no
 * @property string|null $email
 * @property string $pan_number
 * @property string|null $job_location
 * @property string|null $residence_location
 * @property numeric|null $salary
 * @property numeric|null $eligible_loan_amount
 * @property numeric|null $approved_loan_amount
 * @property string|null $current_location
 * @property string|null $company_category
 * @property string|null $bank_eligible_for
 * @property string|null $other_bank_eligible_for
 * @property string|null $loan_applied
 * @property string|null $channel
 * @property string|null $sfl_remarks
 * @property string|null $underwriting_remarks
 * @property string|null $approved_remarks
 * @property string|null $sanctioned_remarks
 * @property string|null $not_approved_remarks
 * @property string|null $other_loan_applied
 * @property string $eligibility_status
 * @property string|null $eligibility_reason
 * @property string|null $journey_status
 * @property string|null $disbursal_status
 * @property string|null $carry_forward_date
 * @property int $disbursal_finalized
 * @property string|null $disbursal_date
 * @property array<array-key, mixed>|null $disbursal_pdf
 * @property bool $documents_submitted
 * @property string|null $underwriting_status
 * @property int $credit_approval_completed
 * @property string|null $approval_date
 * @property string|null $documentation_status
 * @property array<array-key, mixed>|null $pending_document
 * @property string|null $journey_not_approved_reason
 * @property string|null $application_no
 * @property string|null $lan_no
 * @property string|null $sanctioned_bank
 * @property string|null $other_sanctioned_bank
 * @property numeric|null $sanctioned_loan_amount
 * @property numeric|null $cashback
 * @property numeric|null $subvention
 * @property string|null $docking
 * @property numeric|null $payout_rate
 * @property string|null $bank_condition
 * @property string|null $attachment_required
 * @property string|null $attachment_file
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $employee_id
 * @property int|null $assign_to
 * @property-read Collection<int, ActivityLog> $activities
 * @property-read int|null $activities_count
 * @property-read Collection<int, Activity> $activitiesAsSubject
 * @property-read int|null $activities_as_subject_count
 * @property-read Employee|null $assignedTo
 * @property-read Employee|null $createdBy
 * @property-read Collection<int, CustomerDocument> $documents
 * @property-read int|null $documents_count
 * @property-read Employee|null $employee
 * @property-read Collection<int, FollowUp> $followUps
 * @property-read int|null $follow_ups_count
 *
 * @method static \Database\Factories\CustomerFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereApplicationNo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereApprovalDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereApprovedLoanAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereApprovedRemarks($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereAssignTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereAttachmentFile($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereAttachmentRequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereBankCondition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereBankEligibleFor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCarryForwardDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCashback($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCompanyCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCreditApprovalCompleted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCurrentLocation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCustomerName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereDisbursalDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereDisbursalFinalized($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereDisbursalPdf($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereDisbursalStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereDocking($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereDocumentationStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereDocumentsSubmitted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereEligibilityReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereEligibilityStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereEligibleLoanAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereEmployeeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereJobLocation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereJourneyNotApprovedReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereJourneyStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereLanNo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereLoanApplied($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereMobileNo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereNotApprovedRemarks($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereOtherBankEligibleFor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereOtherLoanApplied($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereOtherSanctionedBank($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer wherePanNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer wherePayoutRate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer wherePendingDocument($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereResidenceLocation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereSalary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereSanctionedBank($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereSanctionedLoanAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereSanctionedRemarks($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereSflRemarks($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereSubvention($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereUnderwritingRemarks($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereUnderwritingStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Customer extends Model
{
    use HasFactory, LogsActivity;

    //
    protected $fillable = [
        'customer_name',
        'mobile_no',
        'email',
        'pan_number',
        'job_location',
        'residence_location',
        'salary',
        'current_location',
        'company_category',
        'bank_eligible_for',
        'loan_applied',
        'eligibility_status',
        'eligibility_reason',
        'journey_status',
        'journey_not_approved_reason',
        'sanctioned_bank',
        'sanctioned_loan_amount',
        'cashback',
        'subvention',
        'payout_rate',
        'bank_condition',
        'attachment_required',
        'attachment_file',
        'other_bank_eligible_for',
        'application_no',
        'lan_no',
        'documentation_status',
        'pending_document',
        'sfl_remarks',
        'underwriting_remarks',
        'approved_remarks',
        'sanctioned_remarks',
        'not_approved_remarks',
        'assign_to',
        'employee_id',
        'eligible_loan_amount',
        'docking',
        'underwriting_status',
        'approved_loan_amount',
        'disbursal_status',
        'disbursal_date',
        'carry_forward_date',
        'channel',
        'disbursal_pdf',
        'other_loan_applied',
        'documents_submitted',
        'disbursal_finalized',
        'account_verified',
        'account_verified_by',
        'account_verified_at',
        'account_remark',
        'incentive_calculated',
        'approval_date',
        'other_sanctioned_bank',
        'direct',

    ];

    protected $casts = [
        'pending_document' => 'array',
        'disbursal_pdf' => 'array',
        'disbursal_date' => 'date',
        'documents_submitted' => 'boolean',
        'direct' => 'boolean',
        'account_verified' => 'boolean',
        'account_verified_at' => 'datetime',
        'incentive_calculated' => 'boolean',
    ];

    public function requestedBank()
    {
        return $this->belongsTo(Bank::class, 'requested_bank_id');
    }

    protected static function booted(): void
    {
        static::creating(function ($customer) {

            if (blank($customer->application_no)) {

                $date = now()->format('ymd'); // 260630

                $last = self::whereDate('created_at', today())
                    ->where('application_no', 'like', "FA{$date}%")
                    ->latest('id')
                    ->first();

                $sequence = 1;

                if ($last) {
                    $sequence = (int) substr($last->application_no, -6) + 1;
                }

                $customer->application_no = sprintf(
                    'FA%s%06d',
                    $date,
                    $sequence
                );
            }
        });

        static::updated(function (Customer $customer) {

            if (! $customer->wasChanged('journey_status')) {
                return;
            }

            CustomerStageHistory::create([
                'customer_id' => $customer->id,
                'stage_name' => ucfirst(str_replace('_', ' ', $customer->getOriginal('journey_status'))).' Stage',
                'status_value' => 'Moved to '.ucfirst(str_replace('_', ' ', $customer->journey_status)),
                'user_id' => auth()->id(),
            ]);

            self::recordJourneyAuditForStageChange($customer);
        });
    }

    /**
     * Extends the existing journey_status-change hook above to also write
     * an immutable CustomerJourneyAudit row capturing who actually acted
     * (original owner vs acting employee) and under what access type
     * (normal / delegated / takeover). Best-effort: never blocks the save.
     */
    private static function recordJourneyAuditForStageChange(Customer $customer): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        try {
            $accessService = app(CustomerJourneyAccessService::class);

            // Resolve the module from the stage being exited, not the stage
            // just entered — the action was performed under the origin
            // stage's module (e.g. an Approval-stage decision that results
            // in journey_status becoming "approved" is still an Approval
            // action, not the Bank Processing stage it lands in).
            $stageBeforeChange = clone $customer;
            $stageBeforeChange->journey_status = $customer->getOriginal('journey_status');
            $module = JourneyModule::forCustomer($stageBeforeChange);

            $decision = $accessService->decide($user, $customer, $module);

            $accessService->recordAudit(
                customer: $customer,
                action: 'Moved to '.ucfirst(str_replace('_', ' ', (string) $customer->journey_status)),
                accessType: $decision->accessType,
                performedByUserId: $user->id,
                actingEmployeeId: $decision->actingEmployeeId ?? $user->employee?->id,
                module: $module,
                delegationId: $decision->delegationId,
                takeoverId: $decision->takeoverId,
            );
        } catch (Throwable) {
            // Journey audit logging must never block a stage change save.
        }
    }

    public function assignedTo()
    {
        return $this->belongsTo(Employee::class, 'assign_to');
    }

    public function createdBy()
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function followUps()
    {
        return $this->hasMany(FollowUp::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();

        // return LogOptions::defaults()
        //     ->logAll()
        //     ->logOnlyDirty()
        //     ->dontLogEmptyChanges()
        //     ->logExcept([
        //         'created_at',
        //         'updated_at',
        //     ]);
    }

    public function activities()
    {
        return $this->morphMany(
            ActivityLog::class,
            'subject'
        );
    }

    public function documents()
    {
        return $this->hasMany(CustomerDocument::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function settlement()
    {
        return $this->hasOne(CustomerSettlement::class)->where('version', 1);
    }

    public function panRequests()
    {
        return $this->hasMany(CustomerPanRequest::class);
    }

    public function settlements()
    {
        return $this->hasMany(CustomerSettlement::class);
    }

    public function latestSettlement()
    {
        return $this->hasOne(CustomerSettlement::class)
            ->latestOfMany('version');
    }

    public function assignments()
    {
        return $this->hasMany(CustomerAssignment::class);
    }

    public function journeyTakeovers()
    {
        return $this->hasMany(JourneyTakeover::class);
    }

    public function reassignmentHistory()
    {
        return $this->hasMany(CustomerReassignment::class);
    }

    public function journeyAudits()
    {
        return $this->hasMany(CustomerJourneyAudit::class);
    }
}
