<?php

namespace App\Models;

use App\Enums\CibilRequestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A singleton settings row for the CIBIL module — exactly one record ever
 * exists (seeded by its migration with requests switched off), edited via
 * App\Filament\Pages\CibilSettings and read by
 * App\Services\Cibil\CibilReportService before every request.
 *
 * @property int $id
 * @property bool $requests_enabled
 * @property bool $score_enabled
 * @property bool $full_report_enabled
 * @property bool $pdf_enabled
 * @property bool $fresh_requests_enabled
 * @property int|null $reuse_window_days
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $editor
 */
class CibilSetting extends Model
{
    protected $fillable = [
        'requests_enabled',
        'score_enabled',
        'full_report_enabled',
        'pdf_enabled',
        'fresh_requests_enabled',
        'reuse_window_days',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'requests_enabled' => 'boolean',
            'score_enabled' => 'boolean',
            'full_report_enabled' => 'boolean',
            'pdf_enabled' => 'boolean',
            'fresh_requests_enabled' => 'boolean',
            'reuse_window_days' => 'integer',
        ];
    }

    /**
     * The settings row. Should it ever be missing, an unsaved copy with
     * requests switched off stands in, so nothing is sent to the bureau.
     */
    public static function current(): self
    {
        return static::query()->first() ?? new static([
            'requests_enabled' => false,
            'score_enabled' => true,
            'full_report_enabled' => true,
            'pdf_enabled' => true,
            'fresh_requests_enabled' => true,
            'reuse_window_days' => null,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function allows(CibilRequestType $type): bool
    {
        if (! $this->requests_enabled) {
            return false;
        }

        return match ($type) {
            CibilRequestType::Score => $this->score_enabled,
            CibilRequestType::FullReport => $this->full_report_enabled,
            CibilRequestType::Pdf => $this->pdf_enabled,
        };
    }

    /** The oldest a fetched report may be and still be reused, or null for "no limit". */
    public function reuseCutoff(): ?Carbon
    {
        return $this->reuse_window_days ? now()->subDays($this->reuse_window_days) : null;
    }
}
