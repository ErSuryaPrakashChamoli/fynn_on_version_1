<?php

namespace Tests\Feature;

use App\Filament\Pages\MyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The greeting is wired up via a PanelsRenderHook::PAGE_START render hook
 * (see AdminPanelProvider), which Filament only registers once the panel
 * actually boots on a real HTTP request — Livewire::test() mounts a page
 * component directly and skips that middleware, so these assertions go
 * through $this->get() instead to exercise the real request pipeline.
 */
class DashboardGreetingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_shows_a_morning_greeting_with_the_signed_in_users_name(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 08:00:00'));

        $user = User::factory()->create(['name' => 'Priya Sharma', 'employee_id' => null]);
        $this->actingAs($user);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Good Morning, Priya Sharma!')
            ->assertSee("Let's make every move count!");
    }

    public function test_dashboard_greeting_switches_to_afternoon_and_evening_by_server_time(): void
    {
        $user = User::factory()->create(['name' => 'Priya Sharma', 'employee_id' => null]);
        $this->actingAs($user);

        Carbon::setTestNow(Carbon::parse('2026-08-29 14:00:00'));
        $this->get('/admin')->assertSee('Good Afternoon, Priya Sharma!');

        Carbon::setTestNow(Carbon::parse('2026-08-29 20:00:00'));
        $this->get('/admin')->assertSee('Good Evening, Priya Sharma!');
    }

    public function test_greeting_does_not_leak_onto_other_panel_pages(): void
    {
        $user = User::factory()->create(['name' => 'Priya Sharma', 'employee_id' => null]);
        $this->actingAs($user);

        $this->get(MyProfile::getUrl())->assertDontSee("Let's make every move count!");
    }
}
