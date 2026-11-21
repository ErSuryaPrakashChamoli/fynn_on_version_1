<?php

namespace Tests\Feature;

use App\Filament\Resources\Cities\CityResource;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;
use App\Models\CustomerCashbackRequest;
use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Filament rebuilds the navigation whenever anything asks for it (sidebar,
 * topbar, module search, favourites star…), seven times on one page load,
 * each time re-running every submodule's canAccess() and badge count
 * queries. AdminPanelProvider builds it once per request instead.
 */
class NavigationBuiltOncePerRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $employee = Employee::factory()->create(['designation' => Employee::DESIGNATION_ADMIN]);
        $this->admin = User::factory()->create(['employee_id' => $employee->id]);
        $this->admin->assignRole(Role::findOrCreate('Admin'));

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_asking_again_in_the_same_request_runs_no_queries(): void
    {
        $first = Filament::getNavigation();

        DB::enableQueryLog();
        $second = Filament::getNavigation();
        $third = Filament::getNavigation();

        $this->assertSame([], DB::getQueryLog());
        $this->assertSame($this->labels($first), $this->labels($second));
        $this->assertSame($this->labels($first), $this->labels($third));
    }

    public function test_a_new_request_gets_fresh_badge_counts(): void
    {
        $this->assertNull($this->badgeOf(CustomerCashbackRequestResource::class));

        CustomerCashbackRequest::factory()->count(2)->create();

        $this->assertNull(
            $this->badgeOf(CustomerCashbackRequestResource::class),
            'Within one request the navigation is built once.',
        );

        $this->app->instance('request', Request::create('/admin'));

        $this->assertSame('2', $this->badgeOf(CustomerCashbackRequestResource::class));
    }

    public function test_starring_a_submodule_rebuilds_it_in_the_same_request(): void
    {
        $this->assertNotContains('Favourites', $this->groupLabels());

        $this->admin->toggleSidebarFavourite((string) parse_url(CityResource::getUrl(), PHP_URL_PATH));

        $this->assertContains('Favourites', $this->groupLabels());
    }

    public function test_another_user_never_gets_the_first_users_navigation(): void
    {
        $adminNavigation = $this->labels(Filament::getNavigation());

        $caller = User::factory()->create([
            'employee_id' => Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER])->id,
        ]);
        $caller->assignRole(Role::findOrCreate('Caller'));
        $this->actingAs($caller);

        $callerNavigation = $this->labels(Filament::getNavigation());

        $this->app->instance('request', Request::create('/admin'));

        $this->assertNotSame($adminNavigation, $callerNavigation);
        $this->assertSame($this->labels(Filament::getNavigation()), $callerNavigation);
    }

    /**
     * @return list<string>
     */
    private function groupLabels(): array
    {
        return collect(Filament::getNavigation())
            ->map(fn (NavigationGroup $group): string => (string) $group->getLabel())
            ->values()
            ->all();
    }

    /**
     * @param  array<NavigationGroup>  $navigation
     * @return array<string, list<string>>
     */
    private function labels(array $navigation): array
    {
        return collect($navigation)
            ->mapWithKeys(fn (NavigationGroup $group): array => [
                (string) ($group->getLabel() ?? 'Dashboard') => collect($group->getItems())->map->getLabel()->values()->all(),
            ])
            ->all();
    }

    /**
     * @param  class-string  $resource
     */
    private function badgeOf(string $resource): ?string
    {
        $url = $resource::getUrl();

        foreach (Filament::getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                /** @var NavigationItem $item */
                if ($item->getUrl() === $url) {
                    return $item->getBadge();
                }
            }
        }

        $this->fail("{$resource} is not in the navigation.");
    }
}
