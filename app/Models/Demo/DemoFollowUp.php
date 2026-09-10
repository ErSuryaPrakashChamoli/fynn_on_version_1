<?php

namespace App\Models\Demo;

use Database\Factories\Demo\DemoFollowUpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoFollowUp extends DemoModel
{
    /** @use HasFactory<DemoFollowUpFactory> */
    use HasFactory;

    protected $table = 'demo_follow_ups';

    protected $fillable = [
        'tenant_id',
        'demo_lead_id',
        'demo_customer_id',
        'demo_employee_id',
        'type',
        'scheduled_at',
        'status',
        'outcome',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(DemoLead::class, 'demo_lead_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(DemoCustomer::class, 'demo_customer_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(DemoEmployee::class, 'demo_employee_id');
    }
}
