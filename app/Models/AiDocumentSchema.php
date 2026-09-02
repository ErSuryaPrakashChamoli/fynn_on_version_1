<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiDocumentSchema extends Model
{
    protected $fillable = [
        'name',
        'description',
        'fields',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AiCustomerRecord::class, 'schema_id');
    }

    public function getFieldDefinitions(): array
    {
        return collect($this->fields ?? [])
            ->filter(fn ($field) => is_array($field) && filled($field['key'] ?? null))
            ->values()
            ->all();
    }
}
