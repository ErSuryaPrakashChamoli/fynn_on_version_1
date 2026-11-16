<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * On desktop the login page is exactly one viewport tall: the banner is
 * pinned at 100dvh, so a right panel taller than the viewport used to make
 * the page scroll and leave a blank strip under the banner.
 */
class LoginPageFitsViewportTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_the_two_column_layout(): void
    {
        $this->get(Filament::getPanel('admin')->getLoginUrl())
            ->assertOk()
            ->assertSee('class="fynn-auth', escape: false)
            ->assertSee('fynn-auth-banner', escape: false)
            ->assertSee('fynn-auth-panel-inner', escape: false);
    }

    public function test_desktop_login_layout_is_locked_to_the_viewport_without_page_scroll(): void
    {
        $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));

        $this->assertMatchesRegularExpression(
            '/html:has\(\.fynn-auth\),\s*body:has\(\.fynn-auth\) \{\s*overflow: hidden;\s*\}/',
            $theme,
        );
        $this->assertMatchesRegularExpression(
            '/\.fynn-auth \{\s*flex-direction: row;\s*height: 100dvh;\s*overflow: hidden;\s*\}/',
            $theme,
        );
        $this->assertMatchesRegularExpression(
            '/\.fynn-auth-panel \{\s*overflow-y: auto;\s*\}/',
            $theme,
        );
    }

    public function test_login_panel_spacing_scales_with_viewport_height(): void
    {
        $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));

        $this->assertDoesNotMatchRegularExpression('/\.fynn-auth-panel \{[^}]*padding: 8vh/', $theme);
        $this->assertMatchesRegularExpression('/\.fynn-auth-panel \{[^}]*padding: clamp\(/', $theme);
        $this->assertMatchesRegularExpression('/\.fynn-auth-panel-inner \{[^}]*margin-block: auto;/', $theme);
        $this->assertMatchesRegularExpression('/\.fynn-auth-panel-logo-inline \{[^}]*height: clamp\(/', $theme);
        $this->assertMatchesRegularExpression('/\.fynn-auth-panel-footer \{[^}]*margin-top: clamp\(/', $theme);
    }
}
