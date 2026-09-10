<?php

namespace Tests\Feature\Portal;

use App\Enums\PortalRole;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;

/**
 * The headline security requirement: a trainer, trainee or demo user
 * must never reach the existing Admin Panel, and hiding the link is not
 * enough — these tests type the URLs directly.
 */
class AdminPanelIsolationTest extends PortalBoundaryTestCase
{
    /**
     * Every admin URL the brief named explicitly, plus the panel root.
     *
     * @return list<string>
     */
    public static function adminUrls(): array
    {
        return [
            ['/admin'],
            ['/admin/users'],
            ['/admin/employees'],
            ['/admin/customers'],
            ['/admin/leads'],
            ['/admin/teams'],
            ['/admin/activity-logs'],
            ['/admin/user-login-sessions'],
        ];
    }

    #[DataProvider('adminUrls')]
    public function test_a_trainer_cannot_reach_any_admin_url(string $url): void
    {
        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Trainer));

        $this->get($url)->assertForbidden();
    }

    #[DataProvider('adminUrls')]
    public function test_a_trainee_cannot_reach_any_admin_url(string $url): void
    {
        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Trainee));

        $this->get($url)->assertForbidden();
    }

    #[DataProvider('adminUrls')]
    public function test_a_demo_user_cannot_reach_any_admin_url(string $url): void
    {
        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Demo));

        $this->get($url)->assertForbidden();
    }

    /**
     * The refusal is decided by canAccessPanel(), not by navigation, so
     * it holds for every panel a portal user does not own — including
     * the other portal's.
     */
    public function test_a_trainee_cannot_reach_the_demo_panel_and_vice_versa(): void
    {
        $trainee = $this->makePortalUser(PortalRole::Trainee);
        $demo = $this->makePortalUser(PortalRole::Demo);

        $this->actingAsPortalUser($trainee);
        $this->get('/demo')->assertForbidden();

        $this->actingAsPortalUser($demo);
        $this->get('/academy')->assertForbidden();
    }

    /**
     * A Spatie role is not a way in. Even if a portal user somehow
     * acquired the Admin role, the portal account still pins them.
     */
    public function test_a_spatie_admin_role_does_not_let_a_portal_user_into_admin(): void
    {
        $trainee = $this->makePortalUser(PortalRole::Trainee);
        $trainee->assignRole(Role::findOrCreate('Admin'));

        $this->actingAsPortalUser($trainee->refresh());

        $this->get('/admin')->assertForbidden();
    }

    /**
     * The other half of the contract: nothing changes for the existing
     * LMS population, who have no portal account.
     */
    public function test_an_existing_lms_user_still_reaches_admin_exactly_as_before(): void
    {
        $this->actingAsPortalUser($this->makeInternalUser('Admin'));

        $this->get('/admin')->assertOk();
    }

    public function test_an_existing_lms_user_without_the_admin_role_still_reaches_admin(): void
    {
        // canAccessPanel() returned true unconditionally before this
        // change; for users with no portal account it still does, so a
        // Caller/Manager/MIS user is unaffected.
        $this->actingAsPortalUser($this->makeInternalUser('Caller'));

        $this->get('/admin')->assertOk();
    }

    /**
     * A revoked or lapsed account stops working everywhere at once,
     * because every gate asks PortalAccount::isUsable().
     */
    public function test_an_expired_portal_account_is_refused_by_its_own_panel(): void
    {
        $trainee = $this->makePortalUser(PortalRole::Trainee);
        $this->accountFor($trainee)->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAsPortalUser($trainee->refresh());

        $this->get('/academy')->assertForbidden();
    }

    public function test_a_revoked_portal_account_is_refused_by_its_own_panel(): void
    {
        $demo = $this->makePortalUser(PortalRole::Demo);
        $this->accountFor($demo)->forceFill(['is_active' => false])->save();

        $this->actingAsPortalUser($demo->refresh());

        $this->get('/demo')->assertForbidden();
    }

    public function test_each_portal_user_can_reach_their_own_panel(): void
    {
        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Trainer));
        $this->get('/academy')->assertOk();

        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Trainee));
        $this->get('/academy')->assertOk();

        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Demo));
        $this->get('/demo')->assertOk();
    }

    /**
     * An unauthenticated visitor is sent to the portal's own login page
     * and is told nothing about the admin panel.
     */
    public function test_the_portals_do_not_leak_the_admin_panel_to_guests(): void
    {
        $this->get('/academy')->assertRedirect('/academy/login');
        $this->get('/demo')->assertRedirect('/demo/login');

        $this->get('/demo/login')
            ->assertOk()
            ->assertDontSee('FynnEdge')
            ->assertDontSee('/admin');
    }

    /**
     * A demo user must not be told the admin panel even exists — the
     * sandbox is presented as a standalone product.
     */
    public function test_the_demo_dashboard_never_mentions_the_admin_panel(): void
    {
        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Demo));

        $this->get('/demo')
            ->assertOk()
            ->assertDontSee('/admin');
    }

    /**
     * The site root redirects to /admin, so a portal user visiting "/"
     * must be sent to their own portal instead of following it.
     */
    public function test_the_site_root_sends_a_portal_user_to_their_own_portal(): void
    {
        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Trainee));
        $this->get('/')->assertRedirect('/academy');

        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Demo));
        $this->get('/')->assertRedirect('/demo');
    }

    public function test_the_site_root_still_sends_an_lms_user_to_admin(): void
    {
        $this->actingAsPortalUser($this->makeInternalUser('Admin'));

        $this->get('/')->assertRedirect('/admin');
    }

    public function test_portal_users_are_never_given_a_production_role(): void
    {
        foreach ([PortalRole::Trainer, PortalRole::Trainee, PortalRole::Demo] as $role) {
            $user = $this->makePortalUser($role);

            $this->assertTrue(
                $user->roles()->count() === 0,
                "A {$role->value} account was created holding a production role.",
            );
        }
    }
}
