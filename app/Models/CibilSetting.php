<?php

namespace App\Models;

use App\Enums\CibilRequestType;
use App\Enums\CreditBureau;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A singleton settings row for the CIBIL module — exactly one record ever
 * exists (seeded by its migration with requests switched off), edited via
 * App\Filament\Pages\CibilSettings and read by
 * App\Services\Cibil\CibilReportService before every request.
 *
 * requests_enabled is the master switch for every bureau. Below it each
 * bureau is controlled on its own: CIBIL by score/full_report/pdf_enabled,
 * Experian by experian_enabled and its own three type switches — turning
 * one bureau off never touches the other, and a request is never sent to
 * another bureau instead.
 *
 * @property int $id
 * @property bool $requests_enabled
 * @property bool $score_enabled
 * @property bool $full_report_enabled
 * @property bool $pdf_enabled
 * @property bool $experian_enabled
 * @property bool $experian_score_enabled
 * @property bool $experian_full_report_enabled
 * @property bool $experian_pdf_enabled
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
        'experian_enabled',
        'experian_score_enabled',
        'experian_full_report_enabled',
        'experian_pdf_enabled',
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
            'experian_enabled' => 'boolean',
            'experian_score_enabled' => 'boolean',
            'experian_full_report_enabled' => 'boolean',
            'experian_pdf_enabled' => 'boolean',
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
            'experian_enabled' => true,
            'experian_score_enabled' => true,
            'experian_full_report_enabled' => true,
            'experian_pdf_enabled' => true,
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

    /** This request type may go to this bureau: the master, bureau and type switches are on. */
    public function allows(CibilRequestType $type, CreditBureau $bureau = CreditBureau::Cibil): bool
    {
        return $this->allowsBureau($bureau) && $this->typeSwitchedOn($type, $bureau);
    }

    /** Requests may go to this bureau at all: the master switch and the bureau's own. */
    public function allowsBureau(CreditBureau $bureau): bool
    {
        return $this->requests_enabled && $this->bureauSwitchedOn($bureau);
    }

    /** The bureau's own switch, whatever the master switch says. CIBIL has no separate one. */
    public function bureauSwitchedOn(CreditBureau $bureau): bool
    {
        return match ($bureau) {
            CreditBureau::Cibil => true,
            CreditBureau::Experian => (bool) ($this->experian_enabled ?? true),
        };
    }

    public function typeSwitchedOn(CibilRequestType $type, CreditBureau $bureau): bool
    {
        return (bool) match ($bureau) {
            CreditBureau::Cibil => match ($type) {
                CibilRequestType::Score => $this->score_enabled,
                CibilRequestType::FullReport => $this->full_report_enabled,
                CibilRequestType::Pdf => $this->pdf_enabled,
            },
            CreditBureau::Experian => match ($type) {
                CibilRequestType::Score => $this->experian_score_enabled ?? true,
                CibilRequestType::FullReport => $this->experian_full_report_enabled ?? true,
                CibilRequestType::Pdf => $this->experian_pdf_enabled ?? true,
            },
        };
    }

    /** The oldest a fetched report may be and still be reused, or null for "no limit". */
    public function reuseCutoff(): ?Carbon
    {
        return $this->reuse_window_days ? now()->subDays($this->reuse_window_days) : null;
    }
}
