<?php

namespace Tests\Feature;

use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Leads listing shows each prospect's Job Location and Salary (in place
 * of the old Location and Bank columns) and can be narrowed to a salary
 * range typed with Indian grouping.
 */
class LeadListingSalaryAndJobLocationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->owner = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
        ]);
    }

    public function test_listing_shows_job_location_and_salary_instead_of_location_and_bank(): void
    {
        $lead = $this->createLead('Rohit Verma', 125000, 'Noida');

        Livewire::test(ListLeads::class)
            ->assertTableColumnExists('job_location')
            ->assertTableColumnExists('salary')
            ->assertTableColumnDoesNotExist('current_location')
            ->assertTableColumnDoesNotExist('bank.bank_name')
            ->assertTableColumnStateSet('job_location', 'Noida', $lead)
            ->assertTableColumnFormattedStateSet('salary', '₹1,25,000', $lead);
    }

    public function test_salary_filter_keeps_leads_inside_the_range_typed_with_commas(): void
    {
        $low = $this->createLead('Low Earner', 20000);
        $middle = $this->createLead('Middle Earner', 50000);
        $high = $this->createLead('High Earner', 150000);
        $unknown = $this->createLead('No Salary', null);

        Livewire::test(ListLeads::class)
            ->filterTable('salary', ['salary_from' => '25,000', 'salary_until' => '1,00,000'])
            ->assertCanSeeTableRecords([$middle])
            ->assertCanNotSeeTableRecords([$low, $high, $unknown]);
    }

    public function test_salary_filter_works_with_only_one_bound(): void
    {
        $low = $this->createLead('Low Earner', 20000);
        $high = $this->createLead('High Earner', 150000);

        Livewire::test(ListLeads::class)
            ->filterTable('salary', ['salary_from' => '50,000'])
            ->assertCanSeeTableRecords([$high])
            ->assertCanNotSeeTableRecords([$low]);

        Livewire::test(ListLeads::class)
            ->filterTable('salary', ['salary_until' => '50000'])
            ->assertCanSeeTableRecords([$low])
            ->assertCanNotSeeTableRecords([$high]);
    }

    public function test_salary_bounds_are_inclusive(): void
    {
        $exact = $this->createLead('Exact Earner', 50000);

        Livewire::test(ListLeads::class)
            ->filterTable('salary', ['salary_from' => '50,000', 'salary_until' => '50,000'])
            ->assertCanSeeTableRecords([$exact]);
    }

    public function test_an_empty_or_non_numeric_salary_filter_leaves_the_listing_alone(): void
    {
        $low = $this->createLead('Low Earner', 20000);
        $unknown = $this->createLead('No Salary', null);

        Livewire::test(ListLeads::class)
            ->filterTable('salary', ['salary_from' => '', 'salary_until' => 'abc'])
            ->assertCanSeeTableRecords([$low, $unknown]);
    }

    public function test_salary_filter_shows_indicators(): void
    {
        $component = Livewire::test(ListLeads::class)
            ->filterTable('salary', ['salary_from' => '25,000', 'salary_until' => '1,00,000']);

        $labels = collect($component->instance()->getTable()->getFilter('salary')->getIndicators())
            ->map(fn ($indicator): string => $indicator->getLabel())
            ->all();

        $this->assertSame(['Salary from: ₹25,000', 'Salary to: ₹1,00,000'], $labels);
    }

    public function test_listing_sorts_by_salary(): void
    {
        $low = $this->createLead('Low Earner', 20000);
        $high = $this->createLead('High Earner', 150000);

        Livewire::test(ListLeads::class)
            ->sortTable('salary')
            ->assertCanSeeTableRecords([$low, $high], inOrder: true)
            ->sortTable('salary', 'desc')
            ->assertCanSeeTableRecords([$high, $low], inOrder: true);
    }

    private function createLead(string $name, ?int $salary, ?string $jobLocation = null): Lead
    {
        return Lead::create([
            'employee_id' => $this->owner->id,
            'customer_name' => $name,
            'mobile_no' => (string) fake()->numerify('9#########'),
            'job_location' => $jobLocation,
            'salary' => $salary,
            'follow_up_date' => now(),
            'follow_up_type' => 'Call',
            'status' => 'Pending',
            'remarks' => 'Initial call',
            'is_converted' => false,
        ]);
    }
}
