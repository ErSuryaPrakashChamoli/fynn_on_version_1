<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerReassignment extends Model
{
    protected $fillable = [
        'customer_id',
        'previous_owner_id',
        'new_owner_id',
        'reassigned_by',
        'reason',
        'reassigned_at',
    ];

    protected $casts = [
        'reassigned_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function previousOwner()
    {
        return $this->belongsTo(Employee::class, 'previous_owner_id');
    }

    public function newOwner()
    {
        return $this->belongsTo(Employee::class, 'new_owner_id');
    }

    public function reassignedBy()
    {
        return $this->belongsTo(User::class, 'reassigned_by');
    }
}
