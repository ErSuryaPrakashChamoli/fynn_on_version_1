<?php

namespace App\Enums;

enum JourneyAccessType: string
{
    case Normal = 'normal';
    case TemporaryDelegation = 'temporary_delegation';
    case EmergencyTakeover = 'emergency_takeover';
    case AdminOrganisationWideHandover = 'admin_org_wide_handover';
    case PermanentReassignment = 'permanent_reassignment';
    case Escalation = 'escalation';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::TemporaryDelegation => 'Temporary Delegation',
            self::EmergencyTakeover => 'Emergency Takeover',
            self::AdminOrganisationWideHandover => 'Admin Organisation-Wide Handover',
            self::PermanentReassignment => 'Permanent Reassignment',
            self::Escalation => 'Escalation',
        };
    }
}
