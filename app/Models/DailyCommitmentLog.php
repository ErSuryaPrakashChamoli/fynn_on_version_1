<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only old/new value trail for a DailyCommitment. `updated_at` is
 * deliberately absent — a log row is never modified.
 *
 * @property string $change_type 'commitment' when the employee revised
 *                               their commitment, 'progress' when the
 *                               achievement snapshot moved.
 */
class DailyCommitmentLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'daily_commitment_id',
        'employee_id',
        'old_stage',
        'new_stage',
        'old_amount',
        'new_amount',
        'old_count',
        'new_count',
        'change_type',
        'note',
    ];

    protected $casts = [
        'old_amount' => 'float',
        'new_amount' => 'float',
        'old_count' => 'integer',
        'new_count' => 'integer',
        'created_at' => 'datetime',
    ];

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(DailyCommitment::class, 'daily_commitment_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
