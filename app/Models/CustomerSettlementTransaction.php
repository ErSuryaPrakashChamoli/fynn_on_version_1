<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerSettlementTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_settlement_id',
        'type',
        'amount',
        'transaction_date',
        'reference_no',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    public function settlement()
    {
        return $this->belongsTo(CustomerSettlement::class, 'customer_settlement_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
