<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAssignmentRemark extends Model
{
    protected $fillable = [
        'customer_assignment_id',
        'employee_id',
        'remark',
    ];

    public function customerAssignment(): BelongsTo
    {
        return $this->belongsTo(CustomerAssignment::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
