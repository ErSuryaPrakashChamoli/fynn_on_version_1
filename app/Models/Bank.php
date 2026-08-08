<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    //

    protected $fillable = [
        'bank_name',
        'loan_type',
        'payment_from',
        'payout',
        'is_active',
        'requested_bank_id'
    ];
}
