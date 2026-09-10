<?php

namespace App\Models\Demo;

use Database\Factories\Demo\DemoEmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoEmployee extends DemoModel
{
    /** @use HasFactory<DemoEmployeeFactory> */
    use HasFactory;

    protected $table = 'demo_employees';

    protected $fillable = [
        'tenant_id',
        'emp_code',
        'name',
        'email',
        'mobile_no',
        'designation',
        'department',
        'city',
        'reports_to',
        'joined_on',
        'monthly_target',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
            'monthly_target' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to');
    }

    public function reportees(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(DemoLead::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(DemoCustomer::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(DemoApplication::class);
    }
}
