<?php

namespace App\Filament\Demo\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The sandbox's login screen.
 *
 * Presents FYNN-ON as a product in its own right — no mention of
 * FynnEdge, no internal vocabulary, and no hint that an admin panel
 * exists on the same application.
 */
class DemoLogin extends BaseLogin
{
    public function getHeading(): string|Htmlable|null
    {
        return 'FYNN-ON';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Powering Every Lead — sandbox environment.';
    }
}
