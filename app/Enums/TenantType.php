<?php

namespace App\Enums;

/**
 * What a tenant row represents.
 *
 * The LMS has no pre-existing tenant concept, so this enum (and the
 * tenants table it backs) is introduced purely for the Academy and
 * Demo portals. Production is modelled as a tenant too — not because
 * anything scopes production queries by it today, but so the eventual
 * SaaS split has a real row to migrate onto rather than a null.
 */
enum TenantType: string
{
    case Production = 'production';
    case Demo = 'demo';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Production',
            self::Demo => 'Demo / Sandbox',
            self::Client => 'Client',
        };
    }
}
