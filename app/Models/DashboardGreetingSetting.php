<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A singleton settings row for the dashboard greeting banner's tagline —
 * exactly one record ever exists (seeded by its migration), edited via
 * App\Filament\Pages\DashboardGreetingSettings and read by the
 * filament.components.dashboard-greeting view.
 */
class DashboardGreetingSetting extends Model
{
    protected $fillable = [
        'tagline',
        'icon',
    ];

    public static function current(): self
    {
        return static::query()->firstOrFail();
    }
}
