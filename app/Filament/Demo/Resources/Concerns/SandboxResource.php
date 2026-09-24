<?php

namespace App\Filament\Demo\Resources\Concerns;

use App\Models\Demo\DemoUser;
use Filament\Facades\Filament;

/**
 * Shared guard for every sandbox resource.
 *
 * The isolation that matters is structural — each of these resources is
 * bound to a Demo model, and those live on the separate demo database.
 * What this trait adds is the second layer: only a DemoUser signed in on
 * the `demo` guard may reach the resource at all.
 *
 * Deletes are refused panel-wide (DemoRecordPolicy): a prospect clicking
 * around must not be able to empty the dataset they are being shown.
 */
trait SandboxResource
{
    public static function canAccess(): bool
    {
        return Filament::auth()->user() instanceof DemoUser;
    }
}
