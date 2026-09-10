<?php

namespace App\Enums;

/**
 * The role a portal account carries inside its own portal.
 *
 * These are intentionally NOT Spatie roles. The Spatie role table is the
 * production LMS's authorization vocabulary (Admin, Manager, Caller, ...)
 * and every existing resource reads it with hasRole('Admin'); minting new
 * Spatie roles for trainers/trainees would put portal users inside the
 * same namespace those checks read from, one typo away from a production
 * grant. Portal roles live in their own column, on their own table, and
 * are only ever consulted by Academy/Demo code.
 */
enum PortalRole: string
{
    case Trainer = 'trainer';
    case Trainee = 'trainee';
    case Demo = 'demo';

    public function label(): string
    {
        return match ($this) {
            self::Trainer => 'Trainer',
            self::Trainee => 'Trainee',
            self::Demo => 'Demo User',
        };
    }

    public function portal(): PortalType
    {
        return match ($this) {
            self::Trainer, self::Trainee => PortalType::Academy,
            self::Demo => PortalType::Demo,
        };
    }
}
