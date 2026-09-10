<?php

namespace App\Filament\Academy\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The Academy's login screen.
 *
 * Every credential rule, rate limit and throttle is inherited from
 * Filament's own Login page — only the copy changes. Deliberately says
 * nothing about the LMS: someone who lands here without an account
 * should learn that this is a training portal and no more.
 */
class AcademyLogin extends BaseLogin
{
    public function getHeading(): string|Htmlable|null
    {
        return 'FYNN-ON Academy';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Sign in to continue your training.';
    }
}
