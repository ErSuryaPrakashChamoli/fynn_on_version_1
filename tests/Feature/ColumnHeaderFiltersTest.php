<?php

namespace Tests\Feature;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\FollowUps\Pages\ListFollowUps;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Support\ColumnHeaderFilters\ColumnHeaderFilter;
use App\Support\ColumnHeaderFilters\ColumnHeaderFilters;
use App\Support\SelectedMonth;
use Filament\Facades\Filament;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Throwable;

/**
 * Excel-style filters in listing column headers: what each column offers,
 * how a ticked value narrows the rows, and the panel-wide guard that every
 * table has them.
 */
class ColumnHeaderFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Employee $alice;

    private Employee $bob;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->alice = Employee::factory()->create([
            'emp_name' => 'Alice Rao',
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        $this->bob = Employee::factory()->create([
            'emp_name' => 'Bob Das',
            'designation' => Employee::DESIGNATION_CALLER,
        ]);
    }

    public function test_every_table_component_uses_the_header_filter_trait(): void
    {
        $missing = [];

        foreach (['app/Filament', 'app/Livewire'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory))) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(base_path('app/'))));

                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if ($reflection->implementsInterface(HasTable::class) && ! in_array(HasColumnHeaderFilters::class, class_uses_recursive($class), true)) {
                    $missing[] = $class;
                }
            }
        }

        $this->assertSame([], $missing, 'Every table component needs `use HasColumnHeaderFilters;`.');
    }

    public function test_the_listing_header_shows_a_funnel_on_filterable_columns(): void
    {
        Customer::factory()->create(['employee_id' => $this->alice->id]);

        // A real request: the funnel cells and the popover come from render
        // hooks, and the script and styles are panel assets.
        $html = $this->followingRedirects()->get(ListCustomers::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('data-fynn-header-filter-trigger', $html);
        $this->assertStringContainsString('data-column="customer_name"', $html);
        $this->assertStringContainsString('data-column="employee.emp_name"', $html);
        $this->assertStringContainsString('x-data="fynnColumnHeaderFilter(', $html);
        $this->assertStringContainsString('column-header-filter.js', $html);
        $this->assertStringContainsString('column-header-filters.css', $html);
        $this->assertFileExists(public_path('js/app/column-header-filter.js'));

        // Sorting is offered only from the dropdown, never by clicking a label.
        $this->assertStringNotContainsString('fi-ta-header-cell-sort-btn', $html);
        $this->assertStringNotContainsString("wire:click=\"sortTable('customer_name')\"", $html);
    }

    public function test_a_column_lists_its_distinct_values_and_ticking_one_narrows_the_rows(): void
    {
        $sfl = Customer::factory()->create(['journey_status' => 'sfl', 'employee_id' => $this->alice->id]);
        $sanctioned = Customer::factory()->create(['journey_status' => 'sanctioned', 'employee_id' => $this->alice->id]);

        $component = Livewire::test(ListCustomers::class);

        $options = $component->instance()->getTableHeaderFilterOptions('journey_status');

        $this->assertTrue($options['available']);
        $this->assertSame('values', $options['kind']);
        // Listed in stored-value order (sanctioned < sfl), shown as labels.
        $this->assertSame(['Disbursed', 'SFL'], array_column($options['options'], 'label'));
        $this->assertTrue(collect($options['options'])->every(fn (array $option): bool => $option['checked']));

        $component
            ->call('applyTableHeaderFilter', 'journey_status', ['values' => $this->tokensFor($options, 'Disbursed')])
            ->assertCanSeeTableRecords([$sanctioned])
            ->assertCanNotSeeTableRecords([$sfl])
            ->assertSee('Journey: Disbursed');

        $reopened = $component->instance()->getTableHeaderFilterOptions('journey_status');

        // Excel lists a column's values under the OTHER columns' filters only.
        $this->assertSame(['Disbursed', 'SFL'], array_column($reopened['options'], 'label'));
        $this->assertSame([true, false], array_column($reopened['options'], 'checked'));
    }

    public function test_relationship_columns_filter_through_the_related_record(): void
    {
        $alices = Customer::factory()->create(['employee_id' => $this->alice->id]);
        $bobs = Customer::factory()->create(['employee_id' => $this->bob->id]);

        $component = Livewire::test(ListCustomers::class);

        $options = $component->instance()->getTableHeaderFilterOptions('employee.emp_name');

        $this->assertSame(['Alice Rao', 'Bob Das'], array_column($options['options'], 'label'));
        $this->assertSame(['r:Bob Das'], $this->tokensFor($options, 'Bob Das'));

        $component
            ->call('applyTableHeaderFilter', 'employee.emp_name', ['values' => ['r:Bob Das']])
            ->assertCanSeeTableRecords([$bobs])
            ->assertCanNotSeeTableRecords([$alices]);
    }

    public function test_blanks_cover_rows_with_no_related_record(): void
    {
        $owned = Customer::factory()->create(['employee_id' => $this->alice->id]);
        $unowned = Customer::factory()->create(['employee_id' => null]);

        $component = Livewire::test(ListCustomers::class);

        $options = $component->instance()->getTableHeaderFilterOptions('employee.emp_name');

        $this->assertSame(['checked' => true], $options['blanks']);

        $component
            ->call('applyTableHeaderFilter', 'employee.emp_name', ['blanks' => true])
            ->assertCanSeeTableRecords([$unowned])
            ->assertCanNotSeeTableRecords([$owned])
            ->assertSee('Case Owner: (Blanks)', escape: false);
    }

    public function test_masked_values_never_reach_the_browser_but_still_filter(): void
    {
        $first = Customer::factory()->create(['mobile_no' => '9876543210', 'employee_id' => $this->alice->id]);
        $second = Customer::factory()->create(['mobile_no' => '9123456789', 'employee_id' => $this->alice->id]);

        $component = Livewire::test(ListCustomers::class);

        $options = $component->instance()->getTableHeaderFilterOptions('mobile_no');
        $payload = json_encode($options);

        $this->assertSame(['XXXXXX3210', 'XXXXXX6789'], collect($options['options'])->pluck('label')->sort()->values()->all());
        $this->assertStringNotContainsString('9876543210', $payload);
        $this->assertStringNotContainsString('9123456789', $payload);

        $component
            ->call('applyTableHeaderFilter', 'mobile_no', ['values' => $this->tokensFor($options, 'XXXXXX3210')])
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$second])
            ->assertSee('XXXXXX3210')
            ->assertDontSee('9876543210');
    }

    public function test_searching_a_long_list_keeps_every_match_with_contains(): void
    {
        $kumar = Customer::factory()->create(['customer_name' => 'Prince Kumar', 'employee_id' => $this->alice->id]);
        $kumari = Customer::factory()->create(['customer_name' => 'Asha Kumari', 'employee_id' => $this->alice->id]);
        $other = Customer::factory()->create(['customer_name' => 'Naveen Basa', 'employee_id' => $this->alice->id]);

        $component = Livewire::test(ListCustomers::class);

        $options = $component->instance()->getTableHeaderFilterOptions('customer_name', 'kum');

        $this->assertSame(['Asha Kumari', 'Prince Kumar'], array_column($options['options'], 'label'));

        $component
            ->call('applyTableHeaderFilter', 'customer_name', ['contains' => 'kum'])
            ->assertCanSeeTableRecords([$kumar, $kumari])
            ->assertCanNotSeeTableRecords([$other])
            ->assertSee('Customer Name contains “kum”', escape: false);
    }

    public function test_date_columns_filter_by_a_from_to_range(): void
    {
        $september = Customer::factory()->create(['employee_id' => $this->alice->id]);
        $september->forceFill(['created_at' => Carbon::parse('2026-09-10 11:00')])->save();

        $october = Customer::factory()->create(['employee_id' => $this->alice->id]);
        $october->forceFill(['created_at' => Carbon::parse('2026-10-05 18:30')])->save();

        // The listing shows the files added in the topbar period; take all
        // time so both months are on it.
        $component = Livewire::withCookies([SelectedMonth::PERIOD_COOKIE => SelectedMonth::MODE_ALL])->test(ListCustomers::class);

        $options = $component->instance()->getTableHeaderFilterOptions('created_at');

        $this->assertSame('date', $options['kind']);
        $this->assertSame(['min' => '2026-09-10', 'max' => '2026-10-05'], $options['bounds']);

        // A reversed range is swapped rather than matching nothing.
        $component
            ->call('applyTableHeaderFilter', 'created_at', ['from' => '2026-10-31', 'to' => '2026-10-01'])
            ->assertSet('tableHeaderFilters.created_at', ['from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertCanSeeTableRecords([$october])
            ->assertCanNotSeeTableRecords([$september]);
    }

    public function test_filters_combine_and_can_be_removed_one_by_one_or_all_together(): void
    {
        $match = Customer::factory()->create(['journey_status' => 'sfl', 'employee_id' => $this->bob->id]);
        $wrongOwner = Customer::factory()->create(['journey_status' => 'sfl', 'employee_id' => $this->alice->id]);
        $wrongStage = Customer::factory()->create(['journey_status' => 'approved', 'employee_id' => $this->bob->id]);

        $component = Livewire::test(ListCustomers::class);
        $stages = $component->instance()->getTableHeaderFilterOptions('journey_status');

        $component
            ->call('applyTableHeaderFilter', 'employee.emp_name', ['values' => ['r:Bob Das']])
            ->call('applyTableHeaderFilter', 'journey_status', ['values' => $this->tokensFor($stages, 'SFL')])
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$wrongOwner, $wrongStage]);

        // The owner list is narrowed by the stage filter, not by its own.
        $owners = $component->instance()->getTableHeaderFilterOptions('employee.emp_name');
        $this->assertSame(['Alice Rao', 'Bob Das'], array_column($owners['options'], 'label'));
        $this->assertSame([false, true], array_column($owners['options'], 'checked'));

        $component
            ->call('removeTableHeaderFilter', 'journey_status')
            ->assertCanSeeTableRecords([$match, $wrongStage])
            ->assertCanNotSeeTableRecords([$wrongOwner])
            ->call('removeTableHeaderFilters')
            ->assertSet('tableHeaderFilters', [])
            ->assertCanSeeTableRecords([$match, $wrongOwner, $wrongStage]);
    }

    public function test_state_from_the_url_or_browser_is_never_trusted_as_is(): void
    {
        $customer = Customer::factory()->create(['customer_name' => 'Prince Kumar', 'employee_id' => $this->alice->id]);

        $component = Livewire::test(ListCustomers::class)
            // Unknown columns, junk tokens and impossible dates are dropped.
            ->call('applyTableHeaderFilter', 'no_such_column', ['values' => ['r:x']])
            ->call('applyTableHeaderFilter', 'customer_name', ['values' => ['Prince Kumar', ['nested']]])
            ->call('applyTableHeaderFilter', 'created_at', ['from' => '2026-02-30'])
            ->assertSet('tableHeaderFilters', [])
            ->assertCanSeeTableRecords([$customer]);

        // A forged encrypted token decrypts to nothing and matches nothing.
        $component
            ->set('tableHeaderFilters', ['customer_name' => ['values' => ['e:forged']]])
            ->assertCanNotSeeTableRecords([$customer]);
    }

    public function test_header_filters_are_read_from_the_page_url(): void
    {
        $bobs = Customer::factory()->create(['employee_id' => $this->bob->id]);
        $alices = Customer::factory()->create(['employee_id' => $this->alice->id]);

        Livewire::withQueryParams(['columnFilters' => ['employee.emp_name' => ['values' => ['r:Bob Das']]]])
            ->test(ListCustomers::class)
            ->assertCanSeeTableRecords([$bobs])
            ->assertCanNotSeeTableRecords([$alices]);
    }

    public function test_a_sortable_column_without_a_filter_still_gets_a_sort_only_dropdown(): void
    {
        $table = Livewire::test(ListFollowUps::class)->instance()->getTable();
        $followUpCount = $table->getColumn('follow_up_count');

        $this->assertTrue($followUpCount->isSortable());
        $this->assertNull(ColumnHeaderFilter::for($followUpCount));

        $cell = ColumnHeaderFilters::renderHeaderCell($followUpCount);

        $this->assertStringContainsString("kind: 'sort'", $cell);
        $this->assertStringContainsString('data-fynn-header-filter-trigger', $cell);
        $this->assertStringNotContainsString('sortTable(', $cell);

        // Reordering rows leaves the header to Filament.
        $this->assertSame('', ColumnHeaderFilters::renderHeaderCell($followUpCount, isReordering: true));
    }

    public function test_columns_without_a_database_column_or_search_get_no_header_filter(): void
    {
        $table = Livewire::test(ListCustomers::class)->instance()->getTable();

        $this->assertNotNull(ColumnHeaderFilter::for($table->getColumn('customer_name')));
        $this->assertNull(ColumnHeaderFilters::filterFor(Livewire::test(ListCustomers::class)->instance(), 'not_a_column'));
    }

    /**
     * Panel-wide guard: on every listing, every column that offers a header
     * filter lists its values and applying its first value runs.
     */
    public function test_every_header_filter_on_every_listing_lists_and_applies(): void
    {
        foreach ([
            Employee::DESIGNATION_ADMIN,
            Employee::DESIGNATION_MANAGER,
            Employee::DESIGNATION_TEAM_LEADER,
            Employee::DESIGNATION_CLUSTER,
        ] as $designation) {
            Employee::factory()->create(['designation' => $designation]);
        }

        Customer::factory()->count(2)->create(['employee_id' => $this->alice->id]);

        $failures = [];
        $checked = 0;

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $page = ($resource::getPages()['index'] ?? null)?->getPage();

            if ($page === null) {
                continue;
            }

            try {
                $component = Livewire::test($page);
            } catch (Throwable $exception) {
                $failures[] = "{$page} did not render :: {$exception->getMessage()}";

                continue;
            }

            foreach ($component->instance()->getTable()->getColumns() as $column) {
                if (ColumnHeaderFilter::for($column) === null) {
                    continue;
                }

                $name = $column->getName();

                try {
                    $options = $component->instance()->getTableHeaderFilterOptions($name);

                    if (! ($options['available'] ?? false)) {
                        $failures[] = "{$page} [{$name}] :: ".($options['message'] ?? 'unavailable');

                        continue;
                    }

                    $this->applyFirstChoice($component, $name, $options);
                    $component->instance()->getFilteredTableQuery()?->limit(1)->get();
                    $component->call('removeTableHeaderFilters');

                    $checked++;
                } catch (Throwable $exception) {
                    $failures[] = "{$page} [{$name}] :: {$exception->getMessage()}";
                }
            }
        }

        $this->assertSame([], $failures);
        $this->assertGreaterThan(150, $checked);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function applyFirstChoice(Testable $component, string $column, array $options): void
    {
        $state = match ($options['kind']) {
            'date' => ['from' => '2026-01-01', 'to' => '2026-12-31'],
            'text' => ['contains' => 'a'],
            default => ($options['options'][0]['values'] ?? null)
                ? ['values' => $options['options'][0]['values']]
                : ['blanks' => true],
        };

        $component->call('applyTableHeaderFilter', $column, $state);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function tokensFor(array $options, string $label): array
    {
        return collect($options['options'])->firstWhere('label', $label)['values'] ?? [];
    }
}
