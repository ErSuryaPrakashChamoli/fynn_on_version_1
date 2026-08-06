<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSettlement extends Model
{
    //

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (Auth::check()) {
                $model->created_by = Auth::id();
                $model->updated_by = Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });
    }


    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function latestSettlement(): HasOne
    {
        return $this->hasOne(CustomerSettlement::class)->latestOfMany();
    }

    protected $fillable = [

        'customer_id',

        'mis_batch_id',

        'version',

        'mis_disbursal_amount',

        'mis_cashback',

        'mis_subvention',

        'mis_docking',

        'mis_processing_fee',

        'mis_roi',

        'mis_lan_no',

        'mis_disbursal_date',

        'company_commission',

        'sales_incentive',

        'variance_amount',

        'variance_cashback',

        'variance_subvention',

        'variance_docking',

        'status',

        'remarks',

        'verified_by',

        'verified_at',

    ];

    protected $casts = [

        'mis_disbursal_date' => 'date',

        'verified_at' => 'datetime',

    ];



    public function batch(): BelongsTo
    {
        return $this->belongsTo(MisBatch::class, 'mis_batch_id');
    }
}
