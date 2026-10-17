<?php

namespace App\Enums;

/**
 * CIBIL's published score ranges (300–900). A score below 300 — the bureau
 * answers -1 or 0 for "NH"/"NA" — means the applicant has no usable credit
 * history, which is not the same as a poor score.
 */
enum CibilScoreBand: string
{
    case Excellent = 'excellent';
    case Good = 'good';
    case Fair = 'fair';
    case Poor = 'poor';
    case NoHistory = 'no_history';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 750 => self::Excellent,
            $score >= 700 => self::Good,
            $score >= 650 => self::Fair,
            $score >= 300 => self::Poor,
            default => self::NoHistory,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Excellent => 'Excellent (750+)',
            self::Good => 'Good (700–749)',
            self::Fair => 'Fair (650–699)',
            self::Poor => 'Poor (300–649)',
            self::NoHistory => 'No credit history',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Excellent => 'success',
            self::Good => 'info',
            self::Fair => 'warning',
            self::Poor => 'danger',
            self::NoHistory => 'gray',
        };
    }

    /**
     * Inclusive score range for filtering, as [min, max].
     *
     * @return array{0: int, 1: int}
     */
    public function range(): array
    {
        return match ($this) {
            self::Excellent => [750, 900],
            self::Good => [700, 749],
            self::Fair => [650, 699],
            self::Poor => [300, 649],
            self::NoHistory => [-1, 299],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $band): array => [$band->value => $band->label()])
            ->all();
    }
}
