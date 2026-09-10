<?php

namespace App\Models\Demo;

use Database\Factories\Demo\DemoBankFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoBank extends DemoModel
{
    /** @use HasFactory<DemoBankFactory> */
    use HasFactory;

    protected $table = 'demo_banks';

    protected $fillable = [
        'tenant_id',
        'name',
        'short_name',
        'type',
        'min_interest_rate',
        'max_interest_rate',
        'min_salary',
        'payout_rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_interest_rate' => 'decimal:2',
            'max_interest_rate' => 'decimal:2',
            'payout_rate' => 'decimal:2',
            'min_salary' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(DemoApplication::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(DemoLead::class);
    }
}
