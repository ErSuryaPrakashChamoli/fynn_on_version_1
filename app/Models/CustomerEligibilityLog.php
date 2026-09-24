<?php

namespace App\Models;

use App\Enums\EligibilityLogEvent;
use Database\Factories\CustomerEligibilityLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a customer's eligibility log — written only by
 * CustomerEligibilityService.
 *
 * @property int $id
 * @property int $customer_id
 * @property int|null $customer_eligibility_request_id
 * @property EligibilityLogEvent $event
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $remarks
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property-read Customer $customer
 * @property-read CustomerEligibilityRequest|null $request
 * @property-read User|null $user
 */
class CustomerEligibilityLog extends Model
{
    /** @use HasFactory<CustomerEligibilityLogFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'customer_eligibility_request_id',
        'event',
        'from_status',
        'to_status',
        'remarks',
        'user_id',
    ];

    protected $casts = [
        'event' => EligibilityLogEvent::class,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CustomerEligibilityRequest::class, 'customer_eligibility_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
