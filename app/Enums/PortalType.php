<?php

namespace App\Enums;

/**
 * The three separate experiences this application serves.
 *
 * A user either has no portal account at all — every pre-existing LMS
 * user — and therefore belongs to Admin, or has exactly one portal
 * account pinning them to Academy or Demo. There is deliberately no
 * "both": the whole security boundary is that a portal account is a
 * one-way narrowing, never a widening (see App\Models\PortalAccount).
 */
enum PortalType: string
{
    case Admin = 'admin';
    case Academy = 'academy';
    case Demo = 'demo';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'FynnEdge Admin',
            self::Academy => 'FYNN-ON Academy',
            self::Demo => 'FYNN-ON Demo',
        };
    }

    /**
     * The Filament panel id this portal is served by.
     */
    public function panelId(): string
    {
        return $this->value;
    }

    /**
     * The URL path prefix this portal owns. Every request a portal user
     * makes must fall under this prefix or one of the shared framework
     * paths — see App\Http\Middleware\RestrictPortalUsers.
     */
    public function pathPrefix(): string
    {
        return $this->value;
    }
}
