<?php

namespace App\Models;

use App\Enums\JourneyAccessType;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable audit trail of Customer Journey actions (normal, delegated,
 * takeover, reassignment, escalation). Intentionally has no corresponding
 * Filament create/edit/delete pages — see CustomerJourneyAuditResource —
 * so nothing in the UI can ever mutate or remove a row.
 */
class CustomerJourneyAudit extends Model
{
    protected $fillable = [
        'customer_id',
        'journey_stage',
        'module',
        'action',
        'original_owner_id',
        'acting_employee_id',
        'access_type',
        'case_type',
        'is_admin_override',
        'original_hierarchy',
        'backup_hierarchy',
        'delegation_id',
        'takeover_id',
        'reason',
        'performed_by',
        'performed_at',
    ];

    protected $casts = [
        'performed_at' => 'datetime',
        'access_type' => JourneyAccessType::class,
        'is_admin_override' => 'boolean',
        'original_hierarchy' => 'array',
        'backup_hierarchy' => 'array',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function originalOwner()
    {
        return $this->belongsTo(Employee::class, 'original_owner_id');
    }

    public function actingEmployee()
    {
        return $this->belongsTo(Employee::class, 'acting_employee_id');
    }

    public function delegation()
    {
        return $this->belongsTo(CustomerJourneyDelegation::class, 'delegation_id');
    }

    public function takeover()
    {
        return $this->belongsTo(JourneyTakeover::class, 'takeover_id');
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
