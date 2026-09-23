<?php

namespace App\Models;

use App\Enums\EligibilityRequestStatus;
use Database\Factories\CustomerEligibilityRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request, raised from a Not Eligible customer file, asking the Admin to
 * make it eligible. See CustomerEligibilityService.
 *
 * @property int $id
 * @property int $customer_id
 * @property int|null $requested_by
 * @property string $reason
 * @property EligibilityRequestStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property-read Customer $customer
 * @property-read User|null $requester
 * @property-read User|null $reviewer
 */
class CustomerEligibilityRequest extends Model
{
    /** @use HasFactory<CustomerEligibilityRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'requested_by',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'status' => EligibilityRequestStatus::class,
        'reviewed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', EligibilityRequestStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === EligibilityRequestStatus::Pending;
    }
}
