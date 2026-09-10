<?php

namespace App\Models\Demo;

use Database\Factories\Demo\DemoLoanProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoLoanProduct extends DemoModel
{
    /** @use HasFactory<DemoLoanProductFactory> */
    use HasFactory;

    protected $table = 'demo_loan_products';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'description',
        'min_amount',
        'max_amount',
        'min_tenure_months',
        'max_tenure_months',
        'interest_rate_from',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'min_tenure_months' => 'integer',
            'max_tenure_months' => 'integer',
            'interest_rate_from' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(DemoApplication::class);
    }
}
