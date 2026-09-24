<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hand-over of an assigned lead from one employee to another.
 */
class CustomerAssignmentTransfer extends Model
{
    protected $fillable = [
        'customer_assignment_id',
        'from_employee_id',
        'to_employee_id',
        'transferred_by',
        'reason',
    ];

    public function customerAssignment(): BelongsTo
    {
        return $this->belongsTo(CustomerAssignment::class);
    }

    public function fromEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'transferred_by');
    }
}
