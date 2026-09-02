<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DashboardGreetingSettings;
use App\Models\DashboardGreetingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardGreetingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_update_the_dashboard_greeting_tagline(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(DashboardGreetingSettings::class)
            ->fillForm(['tagline' => 'New week, new wins!'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(Dashboard::getUrl());

        $this->assertSame('New week, new wins!', DashboardGreetingSetting::current()->tagline);
    }

    public function test_tagline_is_required(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(DashboardGreetingSettings::class)
            ->fillForm(['tagline' => ''])
            ->call('save')
            ->assertHasFormErrors(['tagline' => 'required']);
    }

    public function test_non_admin_cannot_access_the_settings_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(DashboardGreetingSettings::canAccess());
    }

    public function test_updated_tagline_appears_on_everyones_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 08:00:00'));

        DashboardGreetingSetting::current()->update(['tagline' => "Today's thought: chase progress, not perfection."]);

        $user = User::factory()->create(['name' => 'Priya Sharma', 'employee_id' => null]);
        $this->actingAs($user);

        $this->get('/admin')
            ->assertOk()
            ->assertSee("Today's thought: chase progress, not perfection.");
    }

    public function test_admin_can_switch_the_greeting_icon_to_a_different_heroicon(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(DashboardGreetingSettings::class)
            ->fillForm(['icon' => 'heroicon-o-fire'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('heroicon-o-fire', DashboardGreetingSetting::current()->icon);
    }

    public function test_admin_can_switch_the_greeting_icon_to_an_emoji(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(DashboardGreetingSettings::class)
            ->fillForm(['icon' => '🔥'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('🔥', DashboardGreetingSetting::current()->icon);
    }

    public function test_icon_is_required(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(DashboardGreetingSettings::class)
            ->fillForm(['icon' => ''])
            ->call('save')
            ->assertHasFormErrors(['icon' => 'required']);
    }

    public function test_selected_heroicon_renders_on_the_dashboard(): void
    {
        DashboardGreetingSetting::current()->update(['icon' => 'heroicon-o-fire']);

        $user = User::factory()->create(['employee_id' => null]);
        $this->actingAs($user);

        // The rendered <x-filament::icon> component has no attribute naming
        // "heroicon-o-fire" itself -- assert on a snippet of that icon's own
        // (otherwise-unique) SVG path data instead.
        $this->get('/admin')
            ->assertOk()
            ->assertSee('M15.362 5.214A8.252', false);
    }

    public function test_selected_emoji_renders_on_the_dashboard(): void
    {
        DashboardGreetingSetting::current()->update(['icon' => '🔥']);

        $user = User::factory()->create(['employee_id' => null]);
        $this->actingAs($user);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('🔥', false);
    }
}
