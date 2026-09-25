<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\DemoModeServiceProvider;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client demo is the real /admin panel with DEMO_MODE on. The
 * persona picker and the switch route must exist only then, and the
 * switch may only ever sign in as one of the configured persona logins.
 */
class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    /**
     * The provider decides at boot, which has already happened with the
     * test suite's DEMO_MODE off — so turn it on and boot it again.
     */
    private function enableDemoMode(): void
    {
        config(['demo.enabled' => true]);

        (new DemoModeServiceProvider($this->app))->boot();

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function persona(string $slug): User
    {
        return User::factory()->create(['email' => config("demo.personas.{$slug}.email")]);
    }

    public function test_the_switch_route_does_not_exist_outside_demo_mode(): void
    {
        $this->persona('admin');

        $this->post('/demo-persona/admin')->assertNotFound();

        $this->assertGuest();
    }

    public function test_the_login_page_has_no_persona_picker_outside_demo_mode(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertDontSee('Explore the demo as');
    }

    public function test_the_login_page_lists_every_persona_in_demo_mode(): void
    {
        $this->enableDemoMode();

        $response = $this->get('/admin/login')->assertOk()->assertSee('Explore the demo as');

        foreach (config('demo.personas') as $slug => $persona) {
            $response->assertSee(route('demo-persona.switch', $slug), escape: false);
        }
    }

    public function test_a_guest_can_sign_in_as_a_persona(): void
    {
        $this->enableDemoMode();
        $manager = $this->persona('manager');

        $this->post('/demo-persona/manager')->assertRedirect(Filament::getPanel('admin')->getUrl());

        $this->assertAuthenticatedAs($manager);
    }

    public function test_a_signed_in_persona_can_switch_to_another(): void
    {
        $this->enableDemoMode();
        $caller = $this->persona('caller');
        $admin = $this->persona('admin');

        $this->actingAs($caller)->post('/demo-persona/admin')->assertRedirect();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_signed_in_role_switcher_is_draggable(): void
    {
        $this->enableDemoMode();
        $admin = $this->persona('admin');

        $this->actingAs($admin)
            ->get(Filament::getPanel('admin')->getUrl())
            ->assertOk()
            ->assertSee('fynn-demo-switcher', escape: false)
            ->assertSee('x-on:pointerdown="start($event)"', escape: false)
            ->assertSee('fynnon.demo-switcher-position', escape: false);
    }

    public function test_an_unknown_persona_is_refused(): void
    {
        $this->enableDemoMode();
        $this->persona('admin');

        $this->post('/demo-persona/someone-else')->assertNotFound();

        $this->assertGuest();
    }

    public function test_a_persona_that_was_never_seeded_is_refused(): void
    {
        $this->enableDemoMode();

        $this->post('/demo-persona/admin')->assertNotFound();

        $this->assertGuest();
    }

    public function test_the_switch_cannot_reach_a_user_outside_the_persona_list(): void
    {
        $this->enableDemoMode();
        User::factory()->create(['email' => 'real.person@example.com']);

        $this->post('/demo-persona/real.person@example.com')->assertNotFound();

        $this->assertGuest();
    }

    public function test_the_refresh_command_refuses_outside_the_demo_environment(): void
    {
        config(['demo.enabled' => true]);

        $this->artisan('demo-environment:refresh', ['--force' => true])
            ->expectsOutputToContain('Refusing to run')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
