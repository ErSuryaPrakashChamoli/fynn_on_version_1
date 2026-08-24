<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAssignmentBatch extends Model
{
    protected $fillable = [
        'assigned_by',
        'employee_id',
        'customer_count',
    ];

    public function assignedBy()
    {
        return $this->belongsTo(Employee::class, 'assigned_by');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function assignments()
    {
        return $this->hasMany(CustomerAssignment::class, 'batch_id');
    }
}
