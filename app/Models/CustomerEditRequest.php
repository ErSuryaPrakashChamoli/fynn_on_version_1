<?php

namespace App\Models;

use App\Enums\CustomerEditRequestStatus;
use App\Support\CustomerEditableFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A request, raised on a customer file, asking the Admin to change one or
 * more fields of one journey section (see CustomerEditRequestService).
 * Approving writes the requested values onto the customer; the items are
 * the log of what changed.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $section
 * @property string $reason
 * @property CustomerEditRequestStatus $status
 * @property int|null $requested_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $applied_at
 * @property-read Customer $customer
 * @property-read User|null $requester
 * @property-read User|null $reviewer
 * @property-read Collection<int, CustomerEditRequestItem> $items
 */
class CustomerEditRequest extends Model
{
    protected $fillable = [
        'customer_id',
        'section',
        'reason',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'applied_at',
    ];

    protected $casts = [
        'status' => CustomerEditRequestStatus::class,
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(CustomerEditRequestItem::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CustomerEditRequestStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === CustomerEditRequestStatus::Pending;
    }

    public function sectionLabel(): string
    {
        return CustomerEditableFields::sectionLabel($this->section);
    }

    /** "Salary, Email Address" */
    public function fieldsLabel(): string
    {
        return $this->items
            ->map(fn (CustomerEditRequestItem $item): string => CustomerEditableFields::fieldLabel($this->section, $item->field))
            ->implode(', ');
    }
}
