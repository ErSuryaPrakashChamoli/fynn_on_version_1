<?php

namespace App\Enums;

/**
 * What became of a follow-up that fell due, judged from the prospect's own
 * follow-up log (see FollowUpMonitorService::classify()). A follow-up is
 * "acted on" the moment the next row for the same prospect is logged —
 * a call, a reschedule, a close or a drop all count.
 */
enum FollowUpOutcome: string
{
    /** Acted on no later than the end of the due day plus the grace period. */
    case OnTime = 'on_time';

    /** Acted on, but only after the grace period ran out. */
    case Late = 'late';

    /** Never acted on and the grace period has run out. */
    case Missed = 'missed';

    /** Due already, not acted on yet, still inside the grace period. */
    case Pending = 'pending';

    /** Not due yet. */
    case Upcoming = 'upcoming';

    public function label(): string
    {
        return match ($this) {
            self::OnTime => 'On time',
            self::Late => 'Late',
            self::Missed => 'Missed',
            self::Pending => 'Pending',
            self::Upcoming => 'Upcoming',
        };
    }

    /** Filament colour name, for badges. */
    public function color(): string
    {
        return match ($this) {
            self::OnTime => 'success',
            self::Late => 'warning',
            self::Missed => 'danger',
            self::Pending => 'info',
            self::Upcoming => 'gray',
        };
    }

    /** Still waiting for someone to act on it. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Missed, self::Pending, self::Upcoming], true);
    }

    /** Has a verdict that counts towards the on-time rate. */
    public function isJudged(): bool
    {
        return in_array($this, [self::OnTime, self::Late, self::Missed], true);
    }
}
