<?php

namespace Tests\Feature\Portal;

use App\Models\Demo\DemoUser;

/**
 * The /demo "View as" switcher: one click signs in as another seeded role
 * login, after which the shared admin code applies that role's normal
 * permissions. It exists only on /demo and only switches between demo
 * logins in the demo database.
 */
class DemoRoleSwitcherTest extends PortalBoundaryTestCase
{
    public function test_the_floating_switcher_is_shown_on_demo_with_every_role(): void
    {
        $this->makeDemoUser(['name' => 'Priya Caller', 'email' => 'priya@demo-fynnon.test'], 'Caller');
        $this->makeDemoUser(['name' => 'Tarun Leader', 'email' => 'tarun@demo-fynnon.test'], 'Team Leader');
        $this->actingAsDemoUser($this->makeDemoUser(['name' => 'Ada Admin', 'email' => config('demo.role_logins.admin.email')]));

        $this->get('/demo')
            ->assertOk()
            ->assertSeeInOrder(['Demo', 'Ada Admin', 'Admin', 'Viewing as Ada Admin (Admin)'])
            // Every demo user, grouped under their role.
            ->assertSee('Caller (1)')
            ->assertSee('Priya Caller')
            ->assertSee('Team Leader (1)')
            ->assertSee('Tarun Leader');
    }

    public function test_the_floating_switcher_can_be_dragged_and_remembers_its_spot(): void
    {
        $this->actingAsDemoUser($this->makeDemoUser(['email' => config('demo.role_logins.admin.email')]));

        $this->get('/demo')
            ->assertOk()
            ->assertSee('class="demo-view-as"', escape: false)
            ->assertSee('x-on:pointerdown="start($event)"', escape: false)
            ->assertSee('x-on:mousedown.capture="holdPressUntilRelease($event)"', escape: false)
            ->assertSee('x-on:click.capture="swallowClickAfterDrag($event)"', escape: false)
            ->assertSee('fynnon.demo-view-as-position', escape: false);
    }

    public function test_admin_can_switch_to_any_specific_user_and_back(): void
    {
        $caller = $this->makeDemoUser(['name' => 'Priya Caller', 'email' => 'priya@demo-fynnon.test'], 'Caller');
        $admin = $this->makeDemoUser(['name' => 'Ada Admin', 'email' => config('demo.role_logins.admin.email')]);
        $this->actingAsDemoUser($admin);

        $this->post('/demo/switch-user/'.$caller->id)->assertRedirect('/demo');
        $this->assertSame($caller->id, auth('demo')->id());

        $this->get('/demo')->assertOk()->assertSee('Viewing as Priya Caller (Caller)')->assertSee('Back to Admin');
        $this->get('/demo/users')->assertForbidden();

        $this->post('/demo/switch-role/admin')->assertRedirect('/demo');
        $this->assertSame($admin->id, auth('demo')->id());
        $this->get('/demo/users')->assertOk();
    }

    public function test_a_deactivated_or_unknown_user_cannot_be_switched_to(): void
    {
        $inactive = $this->makeDemoUser(['is_active' => false], 'Caller');
        $this->actingAsDemoUser($this->makeDemoUser());

        $this->post('/demo/switch-user/'.$inactive->id)->assertNotFound();
        $this->post('/demo/switch-user/999999')->assertNotFound();
    }

    public function test_switching_signs_in_as_that_roles_demo_login(): void
    {
        $this->makeDemoUser(['email' => config('demo.role_logins.cluster-manager.email')], 'Cluster Manager');
        $this->actingAsDemoUser($this->makeDemoUser(['email' => config('demo.role_logins.admin.email')]));

        $this->post('/demo/switch-role/cluster-manager')->assertRedirect('/demo');

        $this->assertInstanceOf(DemoUser::class, auth('demo')->user());
        $this->assertSame(config('demo.role_logins.cluster-manager.email'), auth('demo')->user()->email);
        $this->assertGuest('web');

        $this->get('/demo')->assertOk()->assertSee('Viewing as '.auth('demo')->user()->name.' (Cluster Manager)');
    }

    public function test_after_switching_the_roles_own_permissions_apply(): void
    {
        $this->makeDemoUser(['email' => config('demo.role_logins.caller.email')], 'Caller');
        $this->actingAsDemoUser($this->makeDemoUser(['email' => config('demo.role_logins.admin.email')]));

        // As Admin, the Users screen (Admin/IT only) opens.
        $this->get('/demo/users')->assertOk();

        $this->post('/demo/switch-role/caller')->assertRedirect('/demo');

        // As a Caller, the same screen is refused by the shared admin code.
        $this->get('/demo/users')->assertForbidden();
    }

    public function test_an_unknown_role_is_not_found(): void
    {
        $this->actingAsDemoUser($this->makeDemoUser());

        $this->post('/demo/switch-role/super-admin')->assertNotFound();
    }

    public function test_a_missing_role_login_leaves_the_user_signed_in_as_before(): void
    {
        $admin = $this->makeDemoUser(['email' => config('demo.role_logins.admin.email')]);
        $this->actingAsDemoUser($admin);

        $this->from('/demo')->post('/demo/switch-role/manager')
            ->assertRedirect('/demo')
            ->assertSessionHas('demo_role_switch_error');

        $this->assertSame($admin->id, auth('demo')->id());
    }

    public function test_guests_and_main_logins_cannot_switch(): void
    {
        $this->makeDemoUser(['email' => config('demo.role_logins.cluster-manager.email')], 'Cluster Manager');

        $this->post('/demo/switch-role/cluster-manager')->assertRedirect('/demo/login');
        $this->assertGuest('demo');

        $this->actingAsPortalUser($this->makeInternalUser('Admin'));
        $this->post('/demo/switch-role/cluster-manager')->assertRedirect('/demo/login');
        $this->assertGuest('demo');
    }

    public function test_the_admin_panel_has_no_role_switcher(): void
    {
        $this->actingAsPortalUser($this->makeInternalUser('Admin'));

        $this->get('/admin')->assertOk()->assertDontSee('Viewing as')->assertDontSee('demo-view-as');
    }
}
