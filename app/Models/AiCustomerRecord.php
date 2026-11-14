<?php

namespace App\Models;

use App\Support\AiRecordProspectFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiCustomerRecord extends Model
{
    protected $fillable = [
        'schema_id',
        'ocr_document_id',
        'customer_id',
        'data',
        'status',
        'confidence_score',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'is_duplicate',
        'duplicate_of_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'confidence_score' => 'float',
            'reviewed_at' => 'datetime',
            'is_duplicate' => 'boolean',
        ];
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function schema(): BelongsTo
    {
        return $this->belongsTo(AiDocumentSchema::class, 'schema_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(OcrDocument::class, 'ocr_document_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CustomerAssignment::class);
    }

    /**
     * The assignment that currently owns this record. The bulk assign action
     * skips records that already have one, so this is normally the only one.
     */
    public function latestAssignment(): HasOne
    {
        return $this->hasOne(CustomerAssignment::class)->latestOfMany();
    }

    /**
     * The hand-over that followed this record into its customer file.
     * Converting a lead moves its customer_assignments row from
     * ai_customer_record_id to customer_id (CreateCustomer::afterCreate),
     * so latestAssignment() no longer finds it.
     */
    public function convertedAssignment(): HasOne
    {
        return $this->hasOne(CustomerAssignment::class, 'customer_id', 'customer_id')->latestOfMany();
    }

    /**
     * Who holds this record now: its own assignment, or the one it carried
     * into its customer file once converted.
     */
    public function currentAssignment(): ?CustomerAssignment
    {
        if ($this->latestAssignment) {
            return $this->latestAssignment;
        }

        return $this->customer_id ? $this->convertedAssignment : null;
    }

    /**
     * Records nobody holds yet: never assigned and not converted into a
     * customer (a converted record's assignment lives under customer_id).
     *
     * @param  Builder<AiCustomerRecord>  $query
     * @return Builder<AiCustomerRecord>
     */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('ai_customer_records.customer_id')->whereDoesntHave('assignments');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * A prospect detail (customer_name, mobile_number, pan_number, ...)
     * wherever this record's template stores it — see AiRecordProspectFields.
     */
    public function prospectValue(string $field): ?string
    {
        return AiRecordProspectFields::value($this, $field);
    }
}
