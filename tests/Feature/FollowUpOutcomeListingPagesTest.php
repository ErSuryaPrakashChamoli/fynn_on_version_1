<?php

namespace Tests\Feature;

use App\Filament\Pages\CustomerFollowUpOutcomes;
use App\Filament\Pages\LeadFollowUpOutcomes;
use App\Filament\Widgets\DashboardFollowUpCalendarWidget;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Leads → Lead Follow-up Outcomes and Customers → Customer Follow-up
 * Outcomes: every follow-up due in the chosen dates, tabbed On time / Late
 * / Missed / Open, each page holding the same follow-ups as its calendar.
 */
class FollowUpOutcomeListingPagesTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    private Employee $otherCaller;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Wednesday noon.
        $this->travelTo(Carbon::parse('2026-09-16 12:00:00'));

        $this->caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $this->otherCaller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
    }

    public function test_both_listings_are_in_the_sidebar_and_open(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('Admin'));

        $this->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee(LeadFollowUpOutcomes::getUrl())
            ->assertSee(CustomerFollowUpOutcomes::getUrl());

        $this->get(LeadFollowUpOutcomes::getUrl())->assertOk()->assertSee('Lead Follow-up Outcomes')->assertSee('Missed');
        $this->get(CustomerFollowUpOutcomes::getUrl())->assertOk()->assertSee('Customer Follow-up Outcomes')->assertSee('Due from');
    }

    public function test_the_lead_listing_opens_on_missed_for_the_month_and_tabs_by_outcome(): void
    {
        $this->actingAsCaller();

        $missed = $this->leadFollowUp('Missed Lead', '2026-09-10 11:00');
        $open = $this->leadFollowUp('Open Lead', '2026-09-25 11:00');
        $customerOnly = $this->logFollowUp($this->customer('Customer Only'), '2026-09-10 15:00', createdAt: '2026-09-08 09:00');
        $someoneElses = $this->leadFollowUp('Other Lead', '2026-09-10 11:00', $this->otherCaller);

        $component = Livewire::test(LeadFollowUpOutcomes::class)
            ->assertOk()
            ->assertSet('activeTab', 'missed')
            ->assertSee('Follow-ups due 01 Sep 2026 – 30 Sep 2026')
            ->assertCanSeeTableRecords([$missed])
            ->assertCanNotSeeTableRecords([$open, $customerOnly, $someoneElses])
            ->assertTableColumnStateSet('outcome', 'missed', $missed)
            ->assertTableColumnStateSet('source', 'Lead', $missed);

        $this->assertPeriod($component, '2026-09-01', '2026-09-30');

        $tabs = $component->instance()->getCachedTabs();
        $this->assertSame(['on_time', 'late', 'missed', 'open'], array_keys($tabs));
        $this->assertSame('1', $tabs['missed']->getBadge());
        $this->assertSame('1', $tabs['open']->getBadge());
        $this->assertSame('0', $tabs['on_time']->getBadge());

        $component->set('activeTab', 'open')
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$missed]);
    }

    public function test_the_customer_listing_holds_customer_follow_ups_only(): void
    {
        $this->actingAsCaller();

        $customer = $this->customer('Kept Customer');
        $kept = $this->logFollowUp($customer, '2026-09-05 10:00', createdAt: '2026-09-03 09:00');
        $this->logFollowUp($customer, null, createdAt: '2026-09-05 11:00');
        $missed = $this->logFollowUp($this->customer('Missed Customer'), '2026-09-10 15:00', createdAt: '2026-09-08 09:00');
        $lead = $this->leadFollowUp('Raw Lead', '2026-09-10 11:00');

        Livewire::test(CustomerFollowUpOutcomes::class)
            ->assertCanSeeTableRecords([$missed])
            ->assertCanNotSeeTableRecords([$kept, $lead])
            ->set('activeTab', 'on_time')
            ->assertCanSeeTableRecords([$kept])
            ->assertTableColumnStateSet('acted_at', '2026-09-05 11:00:00', $kept)
            ->assertCanNotSeeTableRecords([$missed]);
    }

    public function test_the_due_dates_narrow_and_widen_the_listing(): void
    {
        $this->actingAsCaller();

        $august = $this->logFollowUp($this->customer('August Customer'), '2026-08-20 15:00', createdAt: '2026-08-18 09:00');
        $early = $this->logFollowUp($this->customer('Early September'), '2026-09-02 10:00', createdAt: '2026-09-01 09:00');
        $mid = $this->logFollowUp($this->customer('Mid September'), '2026-09-10 10:00', createdAt: '2026-09-08 09:00');

        $component = Livewire::test(CustomerFollowUpOutcomes::class)
            ->assertCanSeeTableRecords([$early, $mid])
            ->assertCanNotSeeTableRecords([$august])
            // As the non-native date picker holds it.
            ->set('filters.from', '2026-09-05 12:00:00')
            ->assertCanSeeTableRecords([$mid])
            ->assertCanNotSeeTableRecords([$early, $august])
            ->set('filters.from', '2026-08-01')
            ->assertCanSeeTableRecords([$august, $early, $mid]);

        $this->assertSame('3', $component->instance()->getCachedTabs()['missed']->getBadge());
    }

    public function test_an_admin_narrows_the_listing_to_one_owner_and_searches_by_name(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('Admin'));

        $mine = $this->logFollowUp($this->customer('Asha Customer'), '2026-09-10 15:00', createdAt: '2026-09-08 09:00');
        $theirs = $this->logFollowUp($this->customer('Ravi Customer', $this->otherCaller), '2026-09-10 15:00', createdAt: '2026-09-08 09:00', employee: $this->otherCaller);

        Livewire::test(CustomerFollowUpOutcomes::class)
            ->assertCanSeeTableRecords([$mine, $theirs])
            ->searchTable('Ravi')
            ->assertCanSeeTableRecords([$theirs])
            ->assertCanNotSeeTableRecords([$mine])
            ->searchTable(null)
            ->set('filters.employee_id', $this->caller->id)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_the_tab_and_dates_can_be_given_in_the_link_and_bad_values_fall_back(): void
    {
        $this->actingAsCaller();

        $open = $this->logFollowUp($this->customer('Upcoming Customer'), '2026-10-05 15:00');

        $linked = Livewire::withQueryParams(['activeTab' => 'open', 'filters' => ['from' => '2026-10-01', 'to' => '2026-10-31']])
            ->test(CustomerFollowUpOutcomes::class)
            ->assertSet('activeTab', 'open')
            ->assertCanSeeTableRecords([$open]);

        $this->assertPeriod($linked, '2026-10-01', '2026-10-31');

        $fallback = Livewire::withQueryParams(['activeTab' => 'bogus', 'filters' => ['from' => 'nonsense', 'to' => '0002-01-01']])
            ->test(CustomerFollowUpOutcomes::class)
            ->assertSet('activeTab', 'missed');

        $this->assertPeriod($fallback, '2026-09-01', '2026-09-30');
    }

    public function test_the_calendar_outcome_list_links_to_each_full_listing(): void
    {
        $this->actingAsCaller();

        $this->logFollowUp($this->customer('Missed Customer'), '2026-09-10 15:00', createdAt: '2026-09-08 09:00');

        $leadUrl = LeadFollowUpOutcomes::getUrl(['activeTab' => 'missed', 'filters' => ['from' => '2026-09-01', 'to' => '2026-09-30']]);
        $customerUrl = CustomerFollowUpOutcomes::getUrl(['activeTab' => 'missed', 'filters' => ['from' => '2026-09-01', 'to' => '2026-09-30']]);

        Livewire::test(DashboardFollowUpCalendarWidget::class)
            ->call('openOutcomeList', 'missed')
            ->assertSee('Open full listing')
            ->assertSee($leadUrl)
            ->assertSee($customerUrl);
    }

    private function assertPeriod(Testable $component, string $from, string $to): void
    {
        [$start, $end] = $component->instance()->period();

        $this->assertSame([$from, $to], [$start->toDateString(), $end->toDateString()]);
    }

    private function actingAsCaller(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => $this->caller->id]));
    }

    private function customer(string $name, ?Employee $owner = null): Customer
    {
        $owner ??= $this->caller;

        return Customer::factory()->create(['customer_name' => $name, 'assign_to' => $owner->id, 'employee_id' => $owner->id]);
    }

    /** A raw lead logs its own follow-up row when it is created. */
    private function leadFollowUp(string $name, string $nextDate, ?Employee $owner = null): FollowUp
    {
        $lead = Lead::create([
            'employee_id' => ($owner ?? $this->caller)->id,
            'customer_name' => $name,
            'mobile_no' => '98765'.random_int(10000, 99999),
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'call later',
            'next_follow_up_date' => Carbon::parse($nextDate),
        ]);

        return FollowUp::query()->where('lead_id', $lead->id)->latest('id')->firstOrFail();
    }

    private function logFollowUp(Customer $customer, ?string $nextDate, ?string $createdAt = null, ?Employee $employee = null): FollowUp
    {
        $followUp = FollowUp::create([
            'customer_id' => $customer->id,
            'employee_id' => ($employee ?? $this->caller)->id,
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'logged',
            'next_follow_up_date' => $nextDate ? Carbon::parse($nextDate) : null,
        ]);

        if ($createdAt) {
            $followUp->forceFill(['created_at' => Carbon::parse($createdAt)])->saveQuietly();
        }

        return $followUp;
    }
}
