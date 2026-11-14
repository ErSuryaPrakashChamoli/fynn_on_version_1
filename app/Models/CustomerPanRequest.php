<?php

namespace App\Models;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPanRequest extends Model
{
    protected $fillable = [
        'request_no',

        'customer_id',
        'ai_customer_record_id',
        'lead_id',
        'matched_lead_id',
        'request_for',

        'requested_by',
        'requested_by_emp_id',
        'requested_by_name',

        'team_leader_id',
        'team_leader_name',

        'manager_id',
        'manager_name',

        'cluster_manager_id',
        'cluster_manager_name',

        'business_head_id',
        'business_head_name',

        'requested_bank_id',
        'requested_bank_name',

        'requested_loan_type',

        'reason',

        'status',

        'approved_by',
        'approved_at',
        'remarks',

        'application_id',
        'used_at',

        'pan_number',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (CustomerPanRequest $request) {
            if (! $request->request_no) {
                $request->updateQuietly([
                    'request_no' => 'PR'.str_pad($request->id, 6, '0', STR_PAD_LEFT),
                ]);
            }
        });
    }

    /**
     * Existing Customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function aiCustomerRecord(): BelongsTo
    {
        return $this->belongsTo(AiCustomerRecord::class);
    }

    /**
     * The requester's own lead: the one being converted (customer requests)
     * or the one being edited / created (lead requests).
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The existing open lead whose PAN clashed (customer_id is null then).
     */
    public function matchedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'matched_lead_id');
    }

    /**
     * Spent approvals: a customer file was created from it (application_id)
     * or a lead was saved with it (used_at).
     */
    public function isUsed(): bool
    {
        return filled($this->application_id) || filled($this->used_at);
    }

    public function isForLead(): bool
    {
        return $this->request_for === self::FOR_LEAD;
    }

    /**
     * Where the requester picks up an approved request.
     */
    public function continueUrl(): string
    {
        if ($this->isForLead()) {
            return filled($this->lead_id)
                ? LeadResource::getUrl('edit', ['record' => $this->lead_id, 'pan_request' => $this->id])
                : LeadResource::getUrl('create', ['pan_request' => $this->id]);
        }

        return CustomerResource::getUrl('create', ['pan_request' => $this->id]);
    }

    /**
     * e.g. "Customer: Rahul Sharma" / "Lead: Priya Singh".
     */
    public function matchedRecordLabel(): ?string
    {
        if ($this->customer) {
            return 'Customer: '.$this->customer->customer_name;
        }

        if ($this->matchedLead) {
            return 'Lead: '.$this->matchedLead->customer_name;
        }

        return null;
    }

    /**
     * Employee who raised the request
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    /**
     * Requested Bank
     */
    public function requestedBank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'requested_bank_id');
    }

    /**
     * Team Leader
     */
    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'team_leader_id');
    }

    /**
     * Manager
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * Cluster Manager
     */
    public function clusterManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cluster_manager_id');
    }

    /**
     * Business Head
     */
    public function businessHead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'business_head_id');
    }

    /**
     * Admin who approved/rejected
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    // Uncomment when LoanApplication model is ready.
    /*
    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'application_id');
    }
    */

    /*
    |--------------------------------------------------------------------------
    | Status Constants
    |--------------------------------------------------------------------------
    */

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /*
    |--------------------------------------------------------------------------
    | What the requester wants to save with the duplicate PAN
    |--------------------------------------------------------------------------
    */

    public const FOR_CUSTOMER = 'customer';

    public const FOR_LEAD = 'lead';
}
