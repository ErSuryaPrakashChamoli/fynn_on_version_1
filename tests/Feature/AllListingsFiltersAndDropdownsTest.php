<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Throwable;

/**
 * Panel-wide guard for the listing screens: every resource index must render,
 * every select filter on it must produce a runnable query, and every one of
 * those dropdowns must offer a type-to-filter search box rather than a list
 * the user has to scroll through option by option.
 */
class AllListingsFiltersAndDropdownsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        foreach ([
            Employee::DESIGNATION_ADMIN,
            Employee::DESIGNATION_MANAGER,
            Employee::DESIGNATION_TEAM_LEADER,
            Employee::DESIGNATION_CLUSTER,
            Employee::DESIGNATION_CALLER,
        ] as $designation) {
            Employee::factory()->create(['designation' => $designation]);
        }

        Customer::factory()->count(2)->create([
            'employee_id' => Employee::query()->value('id'),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_every_listing_renders_and_every_select_filter_runs(): void
    {
        $failures = [];
        $filtersChecked = 0;

        foreach ($this->indexPages() as $resource => $page) {
            try {
                $component = Livewire::test($page)->assertOk();
            } catch (Throwable $exception) {
                $failures[] = "{$page} did not render :: {$exception->getMessage()}";

                continue;
            }

            foreach ($component->instance()->getTable()->getFilters() as $filter) {
                if (! $filter instanceof SelectFilter) {
                    continue;
                }

                $optionKey = array_key_first($filter->getOptions());

                if ($optionKey === null) {
                    continue;
                }

                try {
                    $query = $resource::getEloquentQuery();

                    $filter->apply($query, $filter->isMultiple()
                        ? ['values' => [$optionKey]]
                        : ['value' => $optionKey]);

                    $query->limit(1)->get();

                    $filtersChecked++;
                } catch (Throwable $exception) {
                    $failures[] = "{$page} filter [{$filter->getName()}] :: {$exception->getMessage()}";
                }
            }
        }

        $this->assertSame([], $failures);
        $this->assertGreaterThan(40, $filtersChecked);
    }

    public function test_every_select_filter_in_the_panel_is_searchable(): void
    {
        $notSearchable = [];

        foreach ($this->indexPages() as $page) {
            foreach (Livewire::test($page)->instance()->getTable()->getFilters() as $filter) {
                if (! $filter instanceof SelectFilter || $filter instanceof TernaryFilter) {
                    continue;
                }

                if (! $filter->getSearchable()) {
                    $notSearchable[] = "{$page}::{$filter->getName()}";
                }
            }
        }

        $this->assertSame([], $notSearchable);
    }

    public function test_applying_filters_closes_the_filter_panel_and_returns_to_the_listing(): void
    {
        $checked = 0;

        foreach ($this->indexPages() as $page) {
            $table = Livewire::test($page)->instance()->getTable();

            if (! $table->hasDeferredFilters()) {
                // Undeferred filters apply as they are changed, so there is
                // no apply button to come back from.
                continue;
            }

            $action = $table->getFiltersApplyAction();

            $this->assertSame(
                'applyTableFilters',
                $action->getLivewireClickHandler(),
                "{$page} would no longer apply its filters on the server.",
            );

            $handler = (string) $action->getAlpineClickHandler();

            $this->assertStringContainsString('close?.()', $handler, $page);
            $this->assertStringContainsString('scrollIntoView', $handler, $page);

            $checked++;
        }

        $this->assertGreaterThan(20, $checked);
    }

    /**
     * @return array<class-string<resource>, class-string>
     */
    private function indexPages(): array
    {
        $pages = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $index = $resource::getPages()['index'] ?? null;

            if ($index) {
                $pages[$resource] = $index->getPage();
            }
        }

        return $pages;
    }
}
