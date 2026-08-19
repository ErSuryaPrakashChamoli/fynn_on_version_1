<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MisBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_no',
        'batch_date',
        'file_name',
        'file_path',
        'source',
        'status',
        'total_rows',
        'processed_rows',
        'successful_rows',
        'failed_rows',
        'unmatched_rows',
        'error_summary',
        'created_by',
        'completed_at',
        'successful_rows',
        'lan_not_found_rows',
        'validation_failed_rows',
        'processing_failed_rows',
    ];

    protected function casts(): array
    {
        return [
            'batch_date' => 'date',
            'error_summary' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function settlements()
    {
        return $this->hasMany(CustomerSettlement::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
