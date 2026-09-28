<?php

namespace Tests\Feature;

use App\Filament\Resources\Designations\DesignationResource;
use App\Models\Employee;
use App\Models\User;
use App\Support\ModuleSearchIndex;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The topbar "Search modules" pop-up lists the modules and sub-modules of
 * the user's own sidebar — nothing the sidebar hides from them.
 */
class TopbarModuleSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function login(string $role): User
    {
        $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $user->assignRole(Role::findOrCreate($role));
        $this->actingAs($user);

        return $user;
    }

    public function test_the_topbar_carries_the_module_search(): void
    {
        $this->login('Admin');

        $this->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee('fynn-module-search-trigger', false)
            ->assertSee('Search modules…')
            ->assertSee('Search all modules and sub-modules…');
    }

    public function test_an_admin_can_search_every_module_including_employee_setup(): void
    {
        $this->login('Admin');

        $entries = collect(ModuleSearchIndex::entries());

        $designations = $entries->firstWhere('label', 'Designations');

        $this->assertNotNull($designations);
        $this->assertSame('Employee Setup', $designations['module']);
        $this->assertSame(DesignationResource::getUrl(), $designations['url']);
        $this->assertStringContainsString('<svg', (string) $designations['icon']);

        $this->assertSame('Dashboard', $entries->first()['module']);
        $this->assertContains('Customers', $entries->pluck('module'));
        $this->assertGreaterThan(20, $entries->count());
    }

    public function test_the_search_only_offers_what_the_users_sidebar_shows(): void
    {
        $this->login('Caller');

        $entries = collect(ModuleSearchIndex::entries());

        $this->assertNotContains('Employee Setup', $entries->pluck('module'));
        $this->assertNotContains('Designations', $entries->pluck('label'));
        $this->assertContains('Dashboard', $entries->pluck('label'));
    }

    public function test_a_guest_gets_no_entries(): void
    {
        $this->assertSame([], ModuleSearchIndex::entries());
    }
}
