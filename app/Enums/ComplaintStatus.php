<?php

namespace App\Enums;

/**
 * Where a help-desk ticket stands. Open, In Progress and On Hold are all
 * "unresolved" — the SLA clock keeps running through every one of them
 * (parking a ticket on hold never dodges an escalation). Resolved waits for
 * the person who raised it to accept or reopen; Closed is final.
 */
enum ComplaintStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::OnHold => 'On Hold',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::InProgress => 'primary',
            self::OnHold => 'warning',
            self::Resolved => 'success',
            self::Closed => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Open => 'heroicon-o-inbox',
            self::InProgress => 'heroicon-o-wrench-screwdriver',
            self::OnHold => 'heroicon-o-pause-circle',
            self::Resolved => 'heroicon-o-check-circle',
            self::Closed => 'heroicon-o-lock-closed',
        };
    }

    /** Still needs work: the SLA clock is running. */
    public function isUnresolved(): bool
    {
        return in_array($this, self::unresolved(), true);
    }

    /**
     * @return list<self>
     */
    public static function unresolved(): array
    {
        return [self::Open, self::InProgress, self::OnHold];
    }

    /**
     * @return list<string>
     */
    public static function unresolvedValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::unresolved());
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
