<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserMenuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * "Change Password" is only visible to users holding one of the roles
     * ChangePassword::shouldRegisterNavigation() checks for — a plain
     * factory user has none, so it silently disappears from the menu.
     */
    private function actingAsAuthorizedUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Admin'));
        $this->actingAs($user);

        return $user;
    }

    public function test_user_menu_renders_the_renamed_and_recolored_items(): void
    {
        $this->actingAsAuthorizedUser();

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('Relax Out');
        $response->assertDontSee('Sign out');
        $response->assertSee('Change Password');
        $response->assertSee('fi-color-info', false);
        $response->assertSee('fi-color-warning', false);
        $response->assertSee('fi-color-success', false);
    }

    public function test_logout_still_posts_to_the_logout_url(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('action="'.route('filament.admin.auth.logout'), false);
    }
}
