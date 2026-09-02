<?php

namespace Tests\Feature\JourneyContinuity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The panel builds its sidebar from an explicit, hand-written allow-list
 * (AdminPanelProvider::buildNavigation()) rather than Filament's automatic
 * discovery — ->discoverResources() only registers routes, it does not by
 * itself make anything appear in the sidebar. This proves the Customer
 * Journey Continuity module's group and every one of its seven items is
 * actually wired into that list and renders for an authorized user, not
 * just reachable by a direct URL.
 */
class NavigationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_the_customer_journey_continuity_group_and_all_its_items(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('Customer Journey Continuity');
        $response->assertSee('Dashboard');
        $response->assertSee('Team Continuity / Backup Access');
        $response->assertSee('Emergency Takeovers');
        $response->assertSee('Pending Manager Cases');
        $response->assertSee('SLA Breaches');
        $response->assertSee('Reassignments');
        $response->assertSee('Audit History');
    }

    public function test_a_caller_does_not_see_the_customer_journey_continuity_group(): void
    {
        Role::firstOrCreate(['name' => 'Caller']);

        $caller = User::factory()->create();
        $caller->assignRole('Caller');
        $this->actingAs($caller);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertDontSee('Customer Journey Continuity');
    }
}
