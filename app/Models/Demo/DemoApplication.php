<?php

namespace App\Models\Demo;

use Database\Factories\Demo\DemoApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoApplication extends DemoModel
{
    /** @use HasFactory<DemoApplicationFactory> */
    use HasFactory;

    protected $table = 'demo_applications';

    /** @var array<string, string> */
    public const STATUSES = [
        'login' => 'Logged In',
        'under_review' => 'Under Review',
        'sanctioned' => 'Sanctioned',
        'disbursed' => 'Disbursed',
        'rejected' => 'Rejected',
    ];

    protected $fillable = [
        'tenant_id',
        'demo_customer_id',
        'demo_bank_id',
        'demo_loan_product_id',
        'demo_employee_id',
        'application_no',
        'lan_no',
        'applied_amount',
        'sanctioned_amount',
        'disbursed_amount',
        'interest_rate',
        'tenure_months',
        'status',
        'applied_on',
        'sanctioned_on',
        'disbursed_on',
        'payout_rate',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'applied_amount' => 'integer',
            'sanctioned_amount' => 'integer',
            'disbursed_amount' => 'integer',
            'interest_rate' => 'decimal:2',
            'tenure_months' => 'integer',
            'payout_rate' => 'decimal:2',
            'applied_on' => 'date',
            'sanctioned_on' => 'date',
            'disbursed_on' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(DemoCustomer::class, 'demo_customer_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(DemoBank::class, 'demo_bank_id');
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(DemoLoanProduct::class, 'demo_loan_product_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(DemoEmployee::class, 'demo_employee_id');
    }
}
