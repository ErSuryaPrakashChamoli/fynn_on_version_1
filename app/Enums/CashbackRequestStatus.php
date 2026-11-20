<?php

namespace App\Enums;

/**
 * Where a cashback form stands. A Team Leader's form waits for the Manager
 * first; a Manager's goes straight to the Admin; the Admin's own is
 * approved as it is filled. An expected cashback can be retrieved by the
 * Team Leader or Manager while it is unpaid.
 */
enum CashbackRequestStatus: string
{
    case PendingManager = 'pending_manager';
    case PendingAdmin = 'pending_admin';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Retrieved = 'retrieved';

    public function label(): string
    {
        return match ($this) {
            self::PendingManager => 'Waiting for Manager',
            self::PendingAdmin => 'Waiting for Admin',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Retrieved => 'Retrieved',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::PendingManager, self::PendingAdmin => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Retrieved => 'gray',
        };
    }

    public function isPending(): bool
    {
        return in_array($this, [self::PendingManager, self::PendingAdmin], true);
    }

    /**
     * A live form holds the file's cashback figure: pending or approved.
     *
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::PendingManager, self::PendingAdmin, self::Approved];
    }

    /**
     * @return list<string>
     */
    public static function liveValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::live());
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
