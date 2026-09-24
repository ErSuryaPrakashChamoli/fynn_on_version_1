<?php

namespace App\Enums;

/**
 * One line of a customer's eligibility log. Every write to
 * customers.eligibility_status after creation goes through
 * CustomerEligibilityService and leaves one of these behind.
 */
enum EligibilityLogEvent: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case RequestRaised = 'request_raised';
    case RequestApproved = 'request_approved';
    case RequestRejected = 'request_rejected';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Set at creation',
            self::StatusChanged => 'Status changed',
            self::RequestRaised => 'Eligibility requested',
            self::RequestApproved => 'Request approved',
            self::RequestRejected => 'Request rejected',
        };
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Created => 'gray',
            self::StatusChanged => 'info',
            self::RequestRaised => 'warning',
            self::RequestApproved => 'success',
            self::RequestRejected => 'danger',
        };
    }
}
