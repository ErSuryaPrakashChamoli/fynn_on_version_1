<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A singleton settings row for the admin login page's banner/branding —
 * exactly one record ever exists (seeded by its migration), edited via
 * App\Filament\Pages\LoginPageSettings and read by App\Filament\Pages\Auth\Login.
 */
class LoginPageSetting extends Model
{
    protected $fillable = [
        'left_logo_path',
        'left_heading',
        'left_tagline',
        'right_logo_path',
        'right_tagline',
        'welcome_heading',
        'welcome_subheading',
        'footer_text',
    ];

    public static function current(): self
    {
        return static::query()->firstOrFail();
    }

    protected function leftLogoUrl(): Attribute
    {
        return Attribute::get(fn (): string => $this->left_logo_path
            ? Storage::disk('public')->url($this->left_logo_path)
            : asset('images/fynn-on-logo.png'));
    }

    protected function rightLogoUrl(): Attribute
    {
        return Attribute::get(fn (): string => $this->right_logo_path
            ? Storage::disk('public')->url($this->right_logo_path)
            : asset('images/Fynnedge_Advisory.png'));
    }
}
