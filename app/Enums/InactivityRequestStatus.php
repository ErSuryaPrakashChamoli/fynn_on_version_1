<?php

namespace App\Enums;

/**
 * Where an inactivity ticket stands. Pending and Approved both skip the
 * month's commitment target — the team should not be held up waiting for
 * the Admin to get to the ticket. Rejected puts the target back.
 */
enum InactivityRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    /** Statuses that let the month's target be skipped. */
    public function skipsTarget(): bool
    {
        return $this !== self::Rejected;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
