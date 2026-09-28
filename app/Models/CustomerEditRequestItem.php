<?php

namespace App\Models;

use App\Support\CustomerEditableFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One field of a CustomerEditRequest: its value when the request was
 * raised, the value asked for, and — once approved — the value that was
 * actually overwritten (which differs from current_value if the file was
 * edited in between).
 *
 * @property int $id
 * @property int $customer_edit_request_id
 * @property string $field
 * @property string|null $current_value
 * @property string|null $requested_value
 * @property string|null $overwritten_value
 * @property Carbon|null $applied_at
 * @property-read CustomerEditRequest $request
 */
class CustomerEditRequestItem extends Model
{
    protected $fillable = [
        'customer_edit_request_id',
        'field',
        'current_value',
        'requested_value',
        'overwritten_value',
        'applied_at',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(CustomerEditRequest::class, 'customer_edit_request_id');
    }

    public function fieldLabel(): string
    {
        return CustomerEditableFields::fieldLabel($this->request?->section, $this->field);
    }

    /** Indian-formatted, with amounts also in words. */
    public function display(?string $value): string
    {
        return CustomerEditableFields::displayWithWords((string) $this->request?->section, $this->field, $value);
    }
}
