<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Support\SidebarShortcuts;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sidebar modules open as an accordion behind a "+" toggle (user decision
 * 2026-11-14): see resources/js/sidebar-accordion.js, the published
 * sidebar/group.blade.php view and "Sidebar module accordion" in theme.css.
 */
class SidebarModuleAccordionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An Admin with an employee record sees every sidebar module.
     */
    private function actingAsAdminWithEmployee(): User
    {
        $employee = Employee::factory()->create();

        $user = User::factory()->create(['employee_id' => $employee->id]);
        $user->assignRole(Role::findOrCreate('Admin'));
        $this->actingAs($user);

        return $user;
    }

    public function test_every_module_has_a_plus_toggle_wired_to_the_accordion(): void
    {
        $this->actingAsAdminWithEmployee();

        $html = $this->followingRedirects()->get('/admin')->assertOk()->getContent();

        $moduleCount = preg_match_all('/<li[^>]*data-group-label="[^"]+"/', $html);

        $this->assertSame(9, $moduleCount, 'The Admin should see all 9 labelled modules.');
        $this->assertSame($moduleCount, substr_count($html, 'class="fynn-sidebar-group-toggle"'));
        $this->assertStringContainsString('window.fynnSidebarAccordion ? window.fynnSidebarAccordion.toggle(label) : $store.sidebar.toggleCollapsedGroup(label)', $html);
        // Filament's stock chevron collapse button is replaced by the toggle.
        $this->assertStringNotContainsString('fi-sidebar-group-collapse-btn', $html);
    }

    /**
     * User decision 2026-11-14: Dashboard stays on top, then every module A to Z.
     */
    public function test_modules_are_listed_alphabetically_after_the_dashboard(): void
    {
        $this->actingAsAdminWithEmployee();

        $html = $this->followingRedirects()->get('/admin')->assertOk()->getContent();

        preg_match_all('/<li[^>]*data-group-label="([^"]+)"/', $html, $matches);

        $modules = array_map(fn (string $label): string => html_entity_decode($label, ENT_QUOTES), $matches[1]);
        $alphabetical = $modules;
        sort($alphabetical, SORT_STRING | SORT_FLAG_CASE);

        $this->assertCount(9, $modules);
        $this->assertSame($alphabetical, $modules);
        $this->assertSame('Credit & Accounts', $modules[0]);
        $this->assertSame('Settings', $modules[8]);

        $dashboardPosition = strpos($html, 'href="'.url('/admin').'"');
        $firstModulePosition = strpos($html, 'data-group-label="Credit &amp; Accounts"');

        $this->assertNotFalse($dashboardPosition);
        $this->assertLessThan($firstModulePosition, $dashboardPosition, 'Dashboard should stay above every module.');
    }

    public function test_the_collapsed_rail_marker_is_a_button_that_opens_its_module(): void
    {
        $this->actingAsAdminWithEmployee();

        $html = $this->followingRedirects()->get('/admin')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<button[^>]*title="Leads"[^>]*window\.fynnSidebarAccordion\.toggle\(label\)[^>]*class="fynn-sidebar-group-rail-icon"/s',
            $html,
        );
        // In the rail only the open module's submodule icons show, like in the open sidebar.
        $this->assertStringNotContainsString('$store.sidebar.isOpen ? ! $store.sidebar.groupIsCollapsed(label) : ! false', $html);
    }

    public function test_only_the_module_holding_the_current_page_is_marked_active(): void
    {
        $this->actingAsAdminWithEmployee();

        $html = $this->followingRedirects()->get('/admin/leads')->assertOk()->getContent();

        preg_match_all('/<li[^>]*data-group-label="([^"]+)"[^>]*class="fi-sidebar-group([^"]*)"/', $html, $groups, PREG_SET_ORDER);

        $activeModules = collect($groups)
            ->filter(fn (array $group): bool => str_contains($group[2], 'fi-active'))
            ->map(fn (array $group): string => $group[1])
            ->values()
            ->all();

        $this->assertSame(['Leads'], $activeModules);
    }

    public function test_dashboard_leaves_every_module_inactive(): void
    {
        $this->actingAsAdminWithEmployee();

        $html = $this->followingRedirects()->get('/admin')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<li[^>]*data-group-label="[^"]+"[^>]*class="fi-sidebar-group fi-active/', $html);
    }

    /**
     * Filament serves the published copy in public/js/app (see js.md).
     */
    public function test_the_accordion_script_is_registered_and_published(): void
    {
        $this->actingAsAdminWithEmployee();

        $this->followingRedirects()->get('/admin')->assertOk()->assertSee('js/app/sidebar-accordion.js', false);

        $published = public_path('js/app/sidebar-accordion.js');

        $this->assertFileExists($published);
        $this->assertFileEquals(resource_path('js/sidebar-accordion.js'), $published);

        $script = file_get_contents($published);

        $this->assertStringContainsString('window.fynnSidebarAccordion = { toggle, openActiveModule }', $script);
        $this->assertStringContainsString('livewire:navigating', $script);
        $this->assertStringContainsString('--fynn-nav-open-items', $script);
    }

    /**
     * theme.css sizes the rows so the Admin's whole module list fits the
     * sidebar unscrolled; that sum is only right while it counts the rows
     * buildNavigation() actually produces. The Favourites / Recent sections
     * are left out: sidebar-accordion.js counts their rows at runtime.
     */
    public function test_the_row_fit_in_theme_css_counts_every_sidebar_row(): void
    {
        $this->actingAsAdminWithEmployee();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $rows = collect(Filament::getNavigation())
            ->filter(fn (mixed $group): bool => $group instanceof NavigationGroup && ! SidebarShortcuts::isShortcutSection($group))
            ->count();

        $css = file_get_contents(resource_path('css/filament/admin/theme.css'));

        $this->assertSame(1, preg_match('/calc\(var\(--fynn-nav-fit-h\) \/ (\d+)\)/', $css, $collapsedRows));
        $this->assertSame(1, preg_match('/\/ \((\d+) \+ var\(--fynn-nav-open-items, 0\) \* 0\.875\)/', $css, $openRows));
        $this->assertSame($rows, (int) $collapsedRows[1], 'Update the row count in theme.css ("Sidebar module accordion").');
        $this->assertSame($rows, (int) $openRows[1], 'Update the row count in theme.css ("Sidebar module accordion").');
    }

    /**
     * The sidebar a login with the given role sees, as module => submodule labels.
     *
     * @return array<string, list<string>>
     */
    private function sidebarFor(string $role, int $designation): array
    {
        $employee = Employee::factory()->create(['designation' => $designation]);
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $user->assignRole(Role::findOrCreate($role));
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return collect(Filament::getNavigation())
            ->filter(fn (NavigationGroup $group): bool => filled($group->getLabel()))
            ->mapWithKeys(fn (NavigationGroup $group): array => [
                (string) $group->getLabel() => collect($group->getItems())->map->getLabel()->values()->all(),
            ])
            ->all();
    }

    /**
     * User decision 2026-11-14: the sixteen modules were merged into nine.
     * Merging must never widen access — each submodule keeps its own role
     * check, so a Caller sees the shared modules with only their own screens.
     */
    public function test_merged_modules_show_a_caller_only_the_submodules_their_role_opens(): void
    {
        $sidebar = $this->sidebarFor('Caller', Employee::DESIGNATION_CALLER);

        $this->assertSame([
            'Customer Journey Continuity',
            'Customers',
            'Daily Commitment',
            'Help Desk',
            'Leads',
            'People & Organisation',
            'Performance',
            'Settings',
        ], array_keys($sidebar));

        $this->assertSame(['Login & Screen Time', 'Reporting Hierarchy'], $sidebar['People & Organisation']);
        $this->assertSame(['Complaints & Tickets'], $sidebar['Help Desk']);
        $this->assertNotContains('Monitoring & Reports', $sidebar['Leads']);

        foreach (['AI Documents', 'Customer Data', 'Data Fields'] as $adminOnly) {
            $this->assertNotContains($adminOnly, $sidebar['Customers']);
        }

        $this->assertContains('Duplicate PAN Request', $sidebar['Customers']);
    }

    /**
     * User decision 2026-11-14: submodules A to Z inside every module.
     */
    public function test_submodules_are_listed_alphabetically_inside_every_module(): void
    {
        $sidebar = $this->sidebarFor('Admin', Employee::DESIGNATION_ADMIN);

        foreach ($sidebar as $module => $submodules) {
            $alphabetical = $submodules;
            usort($alphabetical, strnatcasecmp(...));

            $this->assertSame($alphabetical, $submodules, "Submodules of [{$module}] are not A to Z.");
        }

        $this->assertSame('Activity Logs', $sidebar['People & Organisation'][0]);
    }

    public function test_the_accounts_role_sees_only_settlements_under_credit_and_accounts(): void
    {
        $sidebar = $this->sidebarFor('Accounts', Employee::DESIGNATION_CALLER);

        $this->assertSame(['Customer Settlement'], $sidebar['Credit & Accounts']);
    }

    public function test_the_admin_sees_every_submodule_of_the_merged_modules(): void
    {
        $sidebar = $this->sidebarFor('Admin', Employee::DESIGNATION_ADMIN);

        $this->assertCount(13, $sidebar['Credit & Accounts']);
        $this->assertCount(12, $sidebar['Customers']);
        $this->assertContains('Cashback Forms', $sidebar['Customers']);
        $this->assertContains('Cashback Analysis', $sidebar['Customers']);
        $this->assertCount(5, $sidebar['Leads']);
        $this->assertCount(13, $sidebar['People & Organisation']);
        $this->assertContains('Monitoring & Reports', $sidebar['Leads']);
        $this->assertContains('Designations', $sidebar['People & Organisation']);
    }
}
