<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Leads and Customers listings must show whose case each row is (owner
 * name plus employee ID), every header they expose must sort, and every
 * dropdown in the panel must offer a type-to-filter search box instead of a
 * long list the user has to scroll.
 */
class ListingSortingAndSearchableFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * @return array{0: Employee, 1: Employee}
     */
    private function twoOwners(): array
    {
        return [
            Employee::factory()->create([
                'emp_name' => 'Aarav Sharma',
                'emp_id' => 'EMP-0001',
                'designation' => Employee::DESIGNATION_CALLER,
            ]),
            Employee::factory()->create([
                'emp_name' => 'Zoya Khan',
                'emp_id' => 'EMP-0002',
                'designation' => Employee::DESIGNATION_CALLER,
            ]),
        ];
    }

    public function test_customer_listing_shows_the_case_owner_name_and_employee_id(): void
    {
        $this->actingAsAdmin();

        [$owner] = $this->twoOwners();

        $customer = Customer::factory()->create([
            'employee_id' => $owner->id,
            'disbursal_date' => null,
        ]);

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$customer])
            ->assertTableColumnStateSet('employee.emp_name', 'Aarav Sharma', $customer)
            ->assertTableColumnStateSet('employee.emp_id', 'EMP-0001', $customer);
    }

    public function test_lead_listing_shows_the_case_owner_name_and_employee_id(): void
    {
        $this->actingAsAdmin();

        [$owner] = $this->twoOwners();

        $lead = $this->createLead($owner, 'Rohit Verma');

        Livewire::test(ListLeads::class)
            ->assertCanSeeTableRecords([$lead])
            ->assertTableColumnStateSet('employee.emp_name', 'Aarav Sharma', $lead)
            ->assertTableColumnStateSet('employee.emp_id', 'EMP-0001', $lead);
    }

    public function test_customer_listing_sorts_by_case_owner(): void
    {
        $this->actingAsAdmin();

        [$first, $second] = $this->twoOwners();

        $ownedByAarav = Customer::factory()->create([
            'employee_id' => $first->id,
            'disbursal_date' => null,
        ]);

        $ownedByZoya = Customer::factory()->create([
            'employee_id' => $second->id,
            'disbursal_date' => null,
        ]);

        Livewire::test(ListCustomers::class)
            ->sortTable('employee.emp_name')
            ->assertCanSeeTableRecords([$ownedByAarav, $ownedByZoya], inOrder: true)
            ->sortTable('employee.emp_name', 'desc')
            ->assertCanSeeTableRecords([$ownedByZoya, $ownedByAarav], inOrder: true);
    }

    public function test_lead_listing_sorts_by_case_owner(): void
    {
        $this->actingAsAdmin();

        [$first, $second] = $this->twoOwners();

        $ownedByAarav = $this->createLead($first, 'Rohit Verma');
        $ownedByZoya = $this->createLead($second, 'Sana Iqbal');

        Livewire::test(ListLeads::class)
            ->sortTable('employee.emp_name')
            ->assertCanSeeTableRecords([$ownedByAarav, $ownedByZoya], inOrder: true)
            ->sortTable('employee.emp_name', 'desc')
            ->assertCanSeeTableRecords([$ownedByZoya, $ownedByAarav], inOrder: true);
    }

    /**
     * Sorting a relationship or plain column must produce runnable SQL, so
     * every sortable header is exercised rather than only spot-checked.
     */
    public function test_every_sortable_header_on_the_customer_listing_runs(): void
    {
        $this->actingAsAdmin();

        [$owner] = $this->twoOwners();

        Customer::factory()->create([
            'employee_id' => $owner->id,
            'disbursal_date' => null,
        ]);

        $this->assertEverySortableHeaderRuns(
            Livewire::test(ListCustomers::class)->instance(),
            CustomerResource::getEloquentQuery(...),
        );
    }

    public function test_every_sortable_header_on_the_lead_listing_runs(): void
    {
        $this->actingAsAdmin();

        [$owner] = $this->twoOwners();

        $this->createLead($owner, 'Rohit Verma');

        $this->assertEverySortableHeaderRuns(
            Livewire::test(ListLeads::class)->instance(),
            LeadResource::getEloquentQuery(...),
        );
    }

    public function test_customer_and_lead_listings_expose_a_case_owner_filter(): void
    {
        $this->actingAsAdmin();

        [$first, $second] = $this->twoOwners();

        $ownedByAarav = Customer::factory()->create([
            'employee_id' => $first->id,
            'disbursal_date' => null,
        ]);

        $ownedByZoya = Customer::factory()->create([
            'employee_id' => $second->id,
            'disbursal_date' => null,
        ]);

        Livewire::test(ListCustomers::class)
            ->filterTable('employee_id', [$first->id])
            ->assertCanSeeTableRecords([$ownedByAarav])
            ->assertCanNotSeeTableRecords([$ownedByZoya]);

        $leadOfAarav = $this->createLead($first, 'Rohit Verma');
        $leadOfZoya = $this->createLead($second, 'Sana Iqbal');

        Livewire::test(ListLeads::class)
            ->filterTable('employee_id', [$first->id])
            ->assertCanSeeTableRecords([$leadOfAarav])
            ->assertCanNotSeeTableRecords([$leadOfZoya]);
    }

    public function test_employee_filter_options_carry_the_employee_id(): void
    {
        $this->actingAsAdmin();

        [$owner] = $this->twoOwners();

        $filter = collect(CustomersTable::configure(Table::make(Livewire::test(ListCustomers::class)->instance()))->getFilters())
            ->first(fn ($filter): bool => $filter->getName() === 'employee_id');

        $this->assertSame(
            'Aarav Sharma (EMP-0001)',
            $filter->getOptions()[$owner->id] ?? null,
        );
    }

    public function test_dropdowns_are_searchable_by_default(): void
    {
        $this->assertTrue(
            Select::make('anything')->isSearchable(),
            'Form selects should offer a search box without opting in per field.',
        );

        $this->assertTrue(
            SelectFilter::make('anything')->getSearchable(),
            'Table select filters should offer a search box without opting in per filter.',
        );
    }

    public function test_ternary_filters_stay_plain_dropdowns(): void
    {
        $this->assertFalse(
            (bool) TernaryFilter::make('anything')->getSearchable(),
            'A three-option yes/no/all filter gains nothing from a search box.',
        );
    }

    private function createLead(Employee $owner, string $name): Lead
    {
        return Lead::create([
            'employee_id' => $owner->id,
            'customer_name' => $name,
            'mobile_no' => (string) fake()->numerify('9#########'),
            'follow_up_date' => now(),
            'follow_up_type' => 'Call',
            'status' => 'Pending',
            'remarks' => 'Initial call',
            'is_converted' => false,
        ]);
    }

    /**
     * Runs the query behind every sortable header, which is the only way to
     * prove the generated SQL is valid — a header that cannot sort throws.
     */
    private function assertEverySortableHeaderRuns(object $livewire, callable $baseQuery): void
    {
        $columns = collect($livewire->getTable()->getColumns())
            ->filter(fn ($column): bool => $column->isSortable());

        $this->assertNotEmpty($columns);

        foreach ($columns as $column) {
            foreach (['asc', 'desc'] as $direction) {
                $query = $baseQuery();
                $column->applySort($query, $direction);

                $this->assertNotNull(
                    $query->get(),
                    "Sorting by [{$column->getName()}] failed.",
                );
            }

            // SQLite (the test driver) silently treats an unknown quoted
            // identifier in ORDER BY as a string literal, so running the query
            // alone would not catch a header pointed at a column that does not
            // exist. Check the schema for plain columns as well.
            if (str_contains($column->getName(), '.')) {
                continue;
            }

            $model = $baseQuery()->getModel();

            $this->assertTrue(
                Schema::hasColumn($model->getTable(), $column->getName()),
                "Header [{$column->getName()}] is sortable but is not a column on [{$model->getTable()}]."
            );
        }
    }
}
