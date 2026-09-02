<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Swaps only the login page's layout for the full-bleed two-column banner
 * (see resources/views/filament/auth/login-layout.blade.php) — every field,
 * validation rule, rate limit and submit action is inherited unchanged from
 * Filament's own Login page. The default heading/logo/subheading (normally
 * rendered above the form by `page.simple`) are suppressed since the new
 * layout builds its own header out of the login page settings instead.
 */
class Login extends BaseLogin
{
    protected static string $layout = 'filament.auth.login-layout';

    public function hasLogo(): bool
    {
        return false;
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }
}
