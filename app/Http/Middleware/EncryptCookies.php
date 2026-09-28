<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * `dashboard_theme` is set directly by client-side JS (the Emerald +
     * Charcoal theme switcher, see the theme-switcher Blade override and
     * AdminPanelProvider::isEmeraldTheme()) as a plain, unencrypted value —
     * Laravel's default encryption would fail to decrypt it and silently
     * null it out on every request.
     *
     * `selected_month` and `selected_period` are set the same way by the
     * global period selector (see global-month-selector.blade.php and
     * App\Support\SelectedMonth).
     *
     * @var array<int, string>
     */
    protected $except = [
        'dashboard_theme',
        'selected_month',
        'selected_period',
    ];
}
