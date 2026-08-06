<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MisBatch extends Model
{
    protected $fillable = [
        'batch_name',
        'batch_code',
        'month',
        'year',
        'file_name',
        'file_path',
        'bank_name',
        'total_records',
        'matched_records',
        'unmatched_records',
        'duplicate_records',
        'updated_records',
        'new_records',
        'total_disbursed_amount',
        'total_cashback',
        'total_subvention',
        'total_docking',
        'total_commission',
        'status',
        'uploaded_by',
        'uploaded_at',
        'verified_by',
        'verified_at',
        'remarks',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function settlements(): HasMany
    {
        return $this->hasMany(CustomerSettlement::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
