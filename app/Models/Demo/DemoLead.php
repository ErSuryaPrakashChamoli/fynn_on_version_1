<?php

namespace App\Models\Demo;

use Database\Factories\Demo\DemoLeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DemoLead extends DemoModel
{
    /** @use HasFactory<DemoLeadFactory> */
    use HasFactory;

    protected $table = 'demo_leads';

    /**
     * The lead pipeline the demo dashboard funnels on, in order.
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'qualified' => 'Qualified',
        'converted' => 'Converted',
        'not_interested' => 'Not Interested',
        'invalid' => 'Invalid',
    ];

    protected $fillable = [
        'tenant_id',
        'demo_employee_id',
        'demo_bank_id',
        'demo_loan_product_id',
        'lead_code',
        'customer_name',
        'mobile_no',
        'email',
        'pan_number',
        'city',
        'source',
        'salary',
        'requested_amount',
        'status',
        'follow_up_date',
        'remarks',
        'is_converted',
    ];

    protected function casts(): array
    {
        return [
            'salary' => 'integer',
            'requested_amount' => 'integer',
            'follow_up_date' => 'date',
            'is_converted' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(DemoEmployee::class, 'demo_employee_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(DemoBank::class, 'demo_bank_id');
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(DemoLoanProduct::class, 'demo_loan_product_id');
    }

    public function customer(): HasOne
    {
        return $this->hasOne(DemoCustomer::class, 'demo_lead_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(DemoFollowUp::class, 'demo_lead_id');
    }
}
