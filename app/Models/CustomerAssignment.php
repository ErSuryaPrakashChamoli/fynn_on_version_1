<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAssignment extends Model
{
    protected $fillable = [
        'batch_id',
        'customer_id',
        'ai_customer_record_id',
        'employee_id',
        'assigned_by',
        'opens_count',
        'first_opened_at',
        'last_opened_at',
    ];

    protected $casts = [
        'first_opened_at' => 'datetime',
        'last_opened_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(CustomerAssignmentBatch::class, 'batch_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function aiCustomerRecord()
    {
        return $this->belongsTo(AiCustomerRecord::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(Employee::class, 'assigned_by');
    }

    public function remarks()
    {
        return $this->hasMany(CustomerAssignmentRemark::class)->latest();
    }

    public function recordOpen(): void
    {
        $this->increment('opens_count', 1, [
            'first_opened_at' => $this->first_opened_at ?? now(),
            'last_opened_at' => now(),
        ]);
    }

    public function getStatusAttribute(): string
    {
        return $this->opens_count > 0 ? 'Opened' : 'Pending';
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->customer) {
            return $this->customer->customer_name;
        }

        if ($this->aiCustomerRecord) {
            return $this->aiCustomerRecord->value('customer_name')
                ?? ($this->aiCustomerRecord->schema?->name . ' #' . $this->aiCustomerRecord->id);
        }

        return '—';
    }

    public function getSourceLabelAttribute(): string
    {
        return $this->customer_id ? 'Customer' : 'AI Record';
    }

    public function latestFollowUp(): ?FollowUp
    {
        if ($this->customer_id) {
            return $this->customer?->followUps()->latest()->first();
        }

        if ($this->ai_customer_record_id) {
            return $this->aiCustomerRecord?->followUps()->latest()->first();
        }

        return null;
    }

    public function latestFollowUpStatus(): ?string
    {
        return $this->latestFollowUp()?->status;
    }

    public function isEligibleForConversion(): bool
    {
        return blank($this->customer_id)
            && filled($this->ai_customer_record_id)
            && $this->latestFollowUpStatus() === 'Interested';
    }
}
