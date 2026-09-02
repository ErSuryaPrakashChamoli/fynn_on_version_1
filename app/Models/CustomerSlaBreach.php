<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerSlaBreach extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'customer_id',
        'module',
        'stage_entered_at',
        'reminder_sent_at',
        'escalated_at',
        'escalated_to_employee_id',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'stage_entered_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'escalated_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function escalatedTo()
    {
        return $this->belongsTo(Employee::class, 'escalated_to_employee_id');
    }
}
