<?php

namespace Tests\Feature;

use App\Filament\Resources\Cities\CityResource;
use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\Designations\DesignationResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Units\UnitResource;
use App\Livewire\SidebarFavourites;
use App\Models\Employee;
use App\Models\User;
use App\Support\ModuleSearchIndex;
use App\Support\SidebarShortcuts;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * User decision 2026-11-14: a ★ on every submodule also lists it under
 * "Favourites" just below Dashboard (it stays in its own module), and
 * "Recent" at the bottom holds the last submodules opened. See
 * App\Support\SidebarShortcuts, App\Livewire\SidebarFavourites and the
 * published sidebar/item.blade.php view.
 */
class SidebarFavouritesAndRecentTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role, int $designation): User
    {
        $employee = Employee::factory()->create(['designation' => $designation]);

        $user = User::factory()->create(['employee_id' => $employee->id]);
        $user->assignRole(Role::findOrCreate($role));
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    /**
     * Opens a page as its own request. Filament resolves the request it
     * marks navigation against ('originalRequest') once per app, and the
     * test app is not reset between requests the way a real one is.
     *
     * @param  class-string  $resource
     */
    private function open(string $resource): string
    {
        $this->app->forgetScopedInstances();

        return $this->followingRedirects()->get($resource::getUrl())->assertOk()->getContent();
    }

    /**
     * @param  class-string  $resource
     */
    private function keyOf(string $resource): string
    {
        return (string) parse_url($resource::getUrl(), PHP_URL_PATH);
    }

    /**
     * @return array<string, list<string>>
     */
    private function sidebar(): array
    {
        return collect(Filament::getNavigation())
            ->mapWithKeys(fn (NavigationGroup $group): array => [
                (string) ($group->getLabel() ?? 'Dashboard') => collect($group->getItems())->map->getLabel()->values()->all(),
            ])
            ->all();
    }

    public function test_a_starred_submodule_is_listed_under_favourites_and_stays_in_its_module(): void
    {
        $user = $this->actingAsRole('Admin', Employee::DESIGNATION_ADMIN);
        $user->forceFill(['sidebar_favourites' => [$this->keyOf(LeadResource::class), $this->keyOf(CityResource::class)]])->save();

        $sidebar = $this->sidebar();

        $this->assertSame(['Dashboard', 'Favourites', 'Credit & Accounts'], array_slice(array_keys($sidebar), 0, 3));
        $this->assertSame(['My Leads', 'Cities'], $sidebar['Favourites'], 'Favourites keep the order they were starred in.');
        $this->assertContains('My Leads', $sidebar['Leads']);
        $this->assertContains('Cities', $sidebar['Settings']);

        $html = $this->followingRedirects()->get('/admin')->assertOk()->getContent();

        // Two starred rows in their modules, two in Favourites.
        $this->assertSame(4, substr_count($html, 'data-favourite="true"'));
        $this->assertSame(4, preg_match_all('/aria-pressed="true"[^>]*class="fynn-sidebar-item-favourite-btn"/', $html));
        $this->assertStringContainsString('aria-label="Remove My Leads from favourites"', $html);
        $this->assertStringContainsString('data-sidebar-shortcuts="favourites"', $html);
    }

    public function test_every_submodule_row_has_a_star_and_modules_have_none(): void
    {
        $this->actingAsRole('Admin', Employee::DESIGNATION_ADMIN);
        $submoduleCount = count(SidebarShortcuts::keysIn(Filament::getNavigation()));

        $html = $this->followingRedirects()->get('/admin')->assertOk()->getContent();

        $this->assertGreaterThan(60, $submoduleCount);
        $this->assertSame($submoduleCount, substr_count($html, 'class="fynn-sidebar-item-favourite-btn"'));
        $this->assertStringContainsString("toggle-sidebar-favourite', { item: '", $html);
        $this->assertStringNotContainsString('fynn-sidebar-group-favourite-btn', $html);
        $this->assertStringContainsString('wire:key="fynn-sidebar-item-'.md5($this->keyOf(LeadResource::class)).'"', $html);
    }

    public function test_opening_a_submodule_puts_it_at_the_top_of_recent_at_the_bottom_of_the_sidebar(): void
    {
        $user = $this->actingAsRole('Admin', Employee::DESIGNATION_ADMIN);
        $updatedAt = $user->updated_at;

        $this->open(LeadResource::class);
        $this->open(CityResource::class);

        $this->assertSame([$this->keyOf(CityResource::class), $this->keyOf(LeadResource::class)], $user->fresh()->sidebarRecents());

        $html = $this->open(LeadResource::class);

        $this->assertSame([$this->keyOf(LeadResource::class), $this->keyOf(CityResource::class)], $user->fresh()->sidebarRecents());
        $this->assertEquals($updatedAt, $user->fresh()->updated_at, 'Recording a visit must not bump updated_at.');

        // The shortcut is never highlighted: only the row in its own module is.
        $this->assertSame(1, substr_count($html, 'class="fi-sidebar-item fi-active'));
        $this->assertStringContainsString('wire:key="fynn-sidebar-recent-'.md5($this->keyOf(LeadResource::class)).'"', $html);
        $this->assertStringContainsString('data-sidebar-shortcuts="recent"', $html);

        $sidebar = $this->sidebar();
        $this->assertSame('Recent', array_key_last($sidebar));
        $this->assertSame(['My Leads', 'Cities'], $sidebar['Recent']);
    }

    public function test_recent_keeps_only_the_last_five_submodules(): void
    {
        $user = $this->actingAsRole('Admin', Employee::DESIGNATION_ADMIN);

        $resources = [LeadResource::class, CityResource::class, DesignationResource::class, PositionResource::class, UnitResource::class, CostCenterResource::class, TeamResource::class];

        foreach ($resources as $resource) {
            $this->open($resource);
        }

        $this->assertSame(
            array_map($this->keyOf(...), array_reverse(array_slice($resources, -SidebarShortcuts::RECENT_LIMIT))),
            $user->fresh()->sidebarRecents(),
        );
    }

    public function test_the_star_adds_and_removes_a_favourite_and_refreshes_the_sidebar(): void
    {
        $user = $this->actingAsRole('Admin', Employee::DESIGNATION_ADMIN);
        $leads = $this->keyOf(LeadResource::class);
        $cities = $this->keyOf(CityResource::class);

        Livewire::test(SidebarFavourites::class)
            ->dispatch('toggle-sidebar-favourite', item: $leads)
            ->assertDispatched('refresh-sidebar');

        $this->assertSame([$leads], $user->fresh()->sidebarFavourites());

        Livewire::test(SidebarFavourites::class)
            ->dispatch('toggle-sidebar-favourite', item: $cities)
            ->dispatch('toggle-sidebar-favourite', item: $leads);

        $this->assertSame([$cities], $user->fresh()->sidebarFavourites());
    }

    public function test_a_submodule_outside_the_users_own_sidebar_is_never_stored(): void
    {
        $user = $this->actingAsRole('Caller', Employee::DESIGNATION_CALLER);

        Livewire::test(SidebarFavourites::class)
            ->dispatch('toggle-sidebar-favourite', item: $this->keyOf(DesignationResource::class))
            ->assertNotDispatched('refresh-sidebar')
            ->dispatch('toggle-sidebar-favourite', item: '/admin/not-a-page')
            ->assertNotDispatched('refresh-sidebar');

        $this->assertSame([], $user->fresh()->sidebarFavourites());
    }

    public function test_a_favourite_the_user_can_no_longer_open_is_not_shown(): void
    {
        $user = $this->actingAsRole('Caller', Employee::DESIGNATION_CALLER);
        $user->forceFill(['sidebar_favourites' => [$this->keyOf(DesignationResource::class)]])->save();

        $this->assertArrayNotHasKey('Favourites', $this->sidebar());
    }

    public function test_a_guest_cannot_store_favourites(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SidebarFavourites::class)
            ->dispatch('toggle-sidebar-favourite', item: '/admin/leads')
            ->assertNotDispatched('refresh-sidebar');
    }

    public function test_module_search_lists_a_favourite_only_once(): void
    {
        $user = $this->actingAsRole('Admin', Employee::DESIGNATION_ADMIN);
        $user->forceFill(['sidebar_favourites' => [$this->keyOf(LeadResource::class)]])->save();

        $entries = collect(ModuleSearchIndex::entries());

        $this->assertSame(1, $entries->where('label', 'My Leads')->count());
        $this->assertNotContains('Favourites', $entries->pluck('module'));
    }
}
