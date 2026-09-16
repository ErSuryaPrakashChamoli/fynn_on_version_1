<?php

namespace App\Models;

use App\Enums\OtherBankRemarkStage;
use Database\Factories\OtherBankSupportRemarkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property int|null $user_id
 * @property OtherBankRemarkStage $stage
 * @property string $remark
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OtherBankSupportRemark extends Model
{
    /** @use HasFactory<OtherBankSupportRemarkFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'user_id',
        'stage',
        'remark',
    ];

    protected $casts = [
        'stage' => OtherBankRemarkStage::class,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
