<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function value(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }
}
