<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomerSettlementHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_settlement_id',
        'customer_id',
        'action',
        'field_name',
        'old_value',
        'new_value',
        'source',
        'reason',
        'performed_by',
        'mis_batch_id',
    ];

    public function settlement()
    {
        return $this->belongsTo(
            CustomerSettlement::class,
            'customer_settlement_id'
        );
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function misBatch()
    {
        return $this->belongsTo(MisBatch::class);
    }
}
