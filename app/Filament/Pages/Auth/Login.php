<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

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

    /**
     * Filament raises the same generic "these credentials do not match"
     * error for a wrong password and for a user whose canAccessPanel()
     * says no. A switched-off account (deactivated user, or an employee
     * marked as exited) is told so plainly instead, otherwise the only
     * symptom of an approved exit is a password that appears to have
     * stopped working.
     */
    protected function throwFailureValidationException(): never
    {
        $email = $this->data['email'] ?? null;

        $user = filled($email)
            ? User::query()->where('email', $email)->first()
            : null;

        if ($user?->isDeactivated()) {
            throw ValidationException::withMessages([
                'data.email' => 'Your account is no longer active. Please contact your administrator.',
            ]);
        }

        parent::throwFailureValidationException();
    }
}
