<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MisBatchRow extends Model
{
    protected $fillable = [
        'mis_batch_id',
        'row_number',
        'customer_id',
        'application_no',
        'lan_no',
        'customer_name',
        'mobile_no',
        'pan_number',
        'bank_name',
        'loan_amount',
        'cashback',
        'subvention',
        'docking',
        'roi',
        'processing_fee',
        'disbursal_date',
        'raw_data',
        'match_status',
        'match_remarks',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'disbursal_date' => 'date',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MisBatch::class, 'mis_batch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(MisBatchRow::class);
    }
}
