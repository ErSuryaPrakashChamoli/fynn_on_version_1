<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class OcrDocument extends Model
{
    protected $fillable = [
        'customer_id',
        'uploaded_by',
        'title',
        'document_type',
        'schema_id',
        'original_path',
        'original_name',
        'mime_type',
        'file_size',
        'page_count',
        'status',
        'ocr_text',
        'extracted_data',
        'page_data',
        'confidence_score',
        'error_message',
        'is_verified',
        'approved_by',
        'approved_at',
        'approved_data',
        'rejection_reason',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'extracted_data' => 'array',
            'page_data' => 'array',
            'confidence_score' => 'float',
            'is_verified' => 'boolean',
            'processed_at' => 'datetime',
            'approved_at' => 'datetime',
            'approved_data' => 'array',
        ];
    }

    public function schema(): BelongsTo
    {
        return $this->belongsTo(AiDocumentSchema::class, 'schema_id');
    }

    public function aiCustomerRecord(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AiCustomerRecord::class, 'ocr_document_id')->latestOfMany();
    }

    public function aiCustomerRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AiCustomerRecord::class, 'ocr_document_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getFileUrlAttribute(): string
    {
        return route('ocr-documents.file', ['ocrDocument' => $this->id]);
    }

    public function getIsPdfAttribute(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function getFormattedConfidenceAttribute(): string
    {
        return $this->confidence_score === null
            ? '-'
            : number_format($this->confidence_score * 100, 1) . '%';
    }

    public function deleteOriginalFile(): void
    {
        if ($this->original_path && Storage::disk('local')->exists($this->original_path)) {
            Storage::disk('local')->delete($this->original_path);
        }
    }
}
