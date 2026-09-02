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
        'left_logo_side',
        'left_logo_vertical_align',
        'left_logo_horizontal_align',
        'left_banner_path',
        'left_heading',
        'left_heading_size',
        'left_heading_align',
        'left_tagline',
        'right_logo_path',
        'right_logo_side',
        'right_logo_vertical_align',
        'right_logo_horizontal_align',
        'right_tagline',
        'welcome_heading',
        'welcome_heading_size',
        'welcome_heading_align',
        'welcome_subheading',
        'footer_text',
    ];

    public static function current(): self
    {
        return static::query()->firstOrFail();
    }

    /**
     * Unlike the company logo, this has no built-in fallback asset: a
     * null value means "no logo uploaded", and the login page simply
     * omits it rather than showing a default FYNN-ON mark.
     */
    protected function leftLogoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->left_logo_path
            ? Storage::disk('public')->url($this->left_logo_path)
            : null);
    }

    /**
     * A complete, admin-uploaded banner image for the whole left panel —
     * unlike the other image fields, there's no built-in fallback asset:
     * a null value means "no custom banner", and the login page falls
     * back to composing the logo/heading/tagline itself instead.
     */
    protected function leftBannerUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->left_banner_path
            ? Storage::disk('public')->url($this->left_banner_path)
            : null);
    }

    protected function rightLogoUrl(): Attribute
    {
        return Attribute::get(fn (): string => $this->right_logo_path
            ? Storage::disk('public')->url($this->right_logo_path)
            : asset('images/Fynnedge_Advisory.png'));
    }
}
