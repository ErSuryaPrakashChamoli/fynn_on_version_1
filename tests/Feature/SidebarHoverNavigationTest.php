<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SidebarHoverNavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An Admin with an employee record sees every sidebar entry: the Admin
     * role opens the role-gated resources and the employee record is what
     * MyDailyCommitment::shouldRegisterNavigation() checks for.
     */
    private function actingAsAdminWithEmployee(): User
    {
        $employee = Employee::factory()->create();

        $user = User::factory()->create(['employee_id' => $employee->id]);
        $user->assignRole(Role::findOrCreate('Admin'));
        $this->actingAs($user);

        return $user;
    }

    public function test_sidebar_rests_as_an_icon_rail_that_expands_on_hover(): void
    {
        $this->actingAsAdminWithEmployee();

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('fi-body-has-sidebar-collapsible-on-desktop', false);
        $response->assertDontSee('fi-body-has-sidebar-fully-collapsible-on-desktop', false);
        $response->assertSee('--collapsed-sidebar-width: 5rem', false);
        $response->assertSee('sidebar-hover-expand', false);
    }

    /**
     * The pin control is injected by the published copy of the script
     * (Filament serves public/js/app, not resources/js — see js.md), so the
     * published file is what must carry it.
     */
    public function test_the_published_sidebar_script_carries_the_pin_control(): void
    {
        $published = public_path('js/app/sidebar-hover-expand.js');

        $this->assertFileExists($published);
        $this->assertStringContainsString('fynn-sidebar-pin-btn', file_get_contents($published));
        $this->assertStringContainsString('Keep sidebar open', file_get_contents($published));
    }

    public function test_collapsed_rail_shows_a_module_marker_above_each_groups_submodule_icons(): void
    {
        $this->actingAsAdminWithEmployee();

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('fynn-sidebar-group-rail-icon', false);
        $response->assertSee('title="Leads"', false);
        $response->assertSee('title="Setting"', false);
        // Items keep their own icons; the stock dropdown-per-group fallback
        // (which strips them) must not have kicked in.
        $response->assertDontSee('fi-sidebar-group-dropdown-trigger-btn', false);
    }

    public function test_every_module_and_submodule_has_its_own_distinct_icon(): void
    {
        $this->actingAsAdminWithEmployee();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $icons = [];

        foreach (Filament::getNavigation() as $group) {
            $this->assertInstanceOf(NavigationGroup::class, $group);

            if (filled($group->getLabel())) {
                $groupIcon = $this->iconName($group->getIcon());
                $this->assertNotNull($groupIcon, "Group [{$group->getLabel()}] has no module icon.");
                $icons["group:{$group->getLabel()}"] = $groupIcon;
            }

            foreach ($group->getItems() as $item) {
                $this->assertInstanceOf(NavigationItem::class, $item);

                $itemIcon = $this->iconName($item->getIcon());
                $this->assertNotNull($itemIcon, "Item [{$item->getLabel()}] has no submodule icon.");
                $icons["item:{$item->getLabel()}"] = $itemIcon;
            }
        }

        $this->assertGreaterThan(30, count($icons), 'The Admin should see the whole sidebar.');

        $duplicates = collect($icons)
            ->groupBy(fn (string $icon): string => $icon)
            ->filter(fn ($users) => $users->count() > 1)
            ->map(fn ($users) => $users->keys()->all())
            ->all();

        $this->assertSame([], $duplicates, 'Sidebar icons shared between entries: '.json_encode($duplicates));
    }

    private function iconName(mixed $icon): ?string
    {
        if ($icon instanceof BackedEnum) {
            return (string) $icon->value;
        }

        if (is_string($icon) && $icon !== '') {
            return $icon;
        }

        return null;
    }
}
