<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminThemeSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_menu_renders_all_three_theme_options(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('Emerald + Charcoal theme');
        $response->assertSee('Indigo + Teal theme');
        $response->assertSee('FYNN-ON theme');
        $response->assertSee('fi-user-menu', false);
    }

    public function test_fynnon_theme_is_active_by_default(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('rgb(21 21 21)', false);
    }

    public function test_classic_cookie_opts_out_of_the_fynnon_chrome_override(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->withUnencryptedCookie('dashboard_theme', 'classic')->get('/admin');

        $response->assertOk();
        $response->assertDontSee('rgb(21 21 21)', false);
        $response->assertDontSee('rgb(38 38 43)', false);
    }

    public function test_emerald_cookie_applies_the_emerald_chrome_instead_of_fynnon(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->withUnencryptedCookie('dashboard_theme', 'emerald')->get('/admin');

        $response->assertOk();
        $response->assertSee('rgb(38 38 43)', false);
        $response->assertDontSee('rgb(21 21 21)', false);
    }

    /**
     * Regression: with three themes sharing one cookie, an "active" check
     * that only excludes the *other* two named cookie values (rather than
     * checking its own) can end up double-lit whenever a new theme value
     * is introduced. Guards against that recurring for whichever theme
     * currently owns the "default" (no-cookie) case.
     */
    public function test_only_the_fynnon_swatch_is_marked_active_by_default(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $content = $response->getContent();

        preg_match_all('/aria-label="(Indigo \+ Teal|Emerald \+ Charcoal|FYNN-ON) theme"[^>]*aria-pressed="(true|false)"/', $content, $matches, PREG_SET_ORDER);

        $pressed = collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]]);

        $this->assertSame('false', $pressed['Indigo + Teal']);
        $this->assertSame('false', $pressed['Emerald + Charcoal']);
        $this->assertSame('true', $pressed['FYNN-ON']);
    }

    public function test_only_the_emerald_swatch_is_marked_active_when_emerald_is_selected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->withUnencryptedCookie('dashboard_theme', 'emerald')->get('/admin');

        $response->assertOk();
        $content = $response->getContent();

        preg_match_all('/aria-label="(Indigo \+ Teal|Emerald \+ Charcoal|FYNN-ON) theme"[^>]*aria-pressed="(true|false)"/', $content, $matches, PREG_SET_ORDER);

        $pressed = collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]]);

        $this->assertSame('false', $pressed['Indigo + Teal']);
        $this->assertSame('true', $pressed['Emerald + Charcoal']);
        $this->assertSame('false', $pressed['FYNN-ON']);
    }
}
