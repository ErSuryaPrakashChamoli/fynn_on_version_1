<?php

namespace App\Models\Demo;

use Database\Factories\Demo\DemoCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoCustomer extends DemoModel
{
    /** @use HasFactory<DemoCustomerFactory> */
    use HasFactory;

    protected $table = 'demo_customers';

    /**
     * Mirrors the vocabulary a FYNN-ON prospect sees in the real journey
     * ladder, without reusing the production enum.
     *
     * @var array<string, string>
     */
    public const JOURNEY_STATUSES = [
        'otp' => 'OTP Verified',
        'sfl' => 'SFL',
        'underwriting' => 'Underwriting',
        'approved' => 'Approved',
        'sanctioned' => 'Sanctioned',
        'disbursed' => 'Disbursed',
        'rejected' => 'Rejected',
    ];

    protected $fillable = [
        'tenant_id',
        'demo_lead_id',
        'demo_employee_id',
        'customer_code',
        'customer_name',
        'mobile_no',
        'email',
        'pan_number',
        'city',
        'company_name',
        'company_category',
        'salary',
        'eligible_loan_amount',
        'journey_status',
        'eligibility_status',
    ];

    protected function casts(): array
    {
        return [
            'salary' => 'integer',
            'eligible_loan_amount' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(DemoLead::class, 'demo_lead_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(DemoEmployee::class, 'demo_employee_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(DemoApplication::class, 'demo_customer_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(DemoFollowUp::class, 'demo_customer_id');
    }
}
