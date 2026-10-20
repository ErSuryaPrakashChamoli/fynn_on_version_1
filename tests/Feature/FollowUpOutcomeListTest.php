<?php

namespace Tests\Feature;

use App\Filament\Widgets\CustomerFollowUpCalendarWidget;
use App\Filament\Widgets\DashboardFollowUpCalendarWidget;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The calendars' On time / Late / Missed / Open legend chips open a list of
 * every follow-up with that verdict, filtered by due date — split into Lead
 * and Customer tabs on the Dashboard.
 */
class FollowUpOutcomeListTest extends TestCase
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

        $this->actingAs(User::factory()->create(['employee_id' => $this->caller->id]));
    }

    public function test_the_legend_chips_open_the_outcome_list(): void
    {
        Livewire::test(DashboardFollowUpCalendarWidget::class)
            ->assertSeeHtml('followup-outcome--clickable')
            ->assertSeeHtml("\$wire.openOutcomeList('on_time')")
            ->assertSeeHtml("\$wire.openOutcomeList('late')")
            ->assertSeeHtml("\$wire.openOutcomeList('missed')")
            ->assertSeeHtml("\$wire.openOutcomeList('open')");

        Livewire::test(CustomerFollowUpCalendarWidget::class)
            ->assertSeeHtml("\$wire.openOutcomeList('missed')");
    }

    public function test_missed_lists_the_callers_own_lead_and_customer_follow_ups_due_in_the_month_on_screen(): void
    {
        $this->missedLead('Missed Lead', '2026-09-11 11:00');
        $this->logFollowUp($this->customer('Missed Customer'), '2026-09-10 15:00', createdAt: '2026-09-08 09:00');
        $this->onTimeFollowUp($this->customer('Kept Customer'), '2026-09-05 10:00');
        $this->logFollowUp($this->customer('August Customer'), '2026-08-20 15:00', createdAt: '2026-08-18 09:00');
        $this->logFollowUp($this->customer('Someone Else', $this->otherCaller), '2026-09-10 15:00', createdAt: '2026-09-08 09:00', employee: $this->otherCaller);

        $component = Livewire::test(DashboardFollowUpCalendarWidget::class)
            ->call('openOutcomeList', 'missed')
            ->assertSet('outcomeListFilter', 'missed')
            ->assertSet('outcomeListFrom', '2026-09-01')
            ->assertSet('outcomeListTo', '2026-09-30')
            ->assertSee('Missed follow-ups')
            ->assertSee('Missed Lead')
            ->assertSee('Missed Customer')
            ->assertSee('10 Sep 2026 03:00 PM')
            ->assertDontSee('Kept Customer')
            ->assertDontSee('August Customer')
            ->assertDontSee('Someone Else');

        $list = $this->outcomeList($component);

        $this->assertSame(['lead', 'customer'], array_keys($list));
        $this->assertSame(['Missed Lead'], $this->names($list['lead']));
        $this->assertSame(['Missed Customer'], $this->names($list['customer']));
        $this->assertSame(1, $list['customer']['total']);
    }

    public function test_the_due_date_filter_narrows_and_widens_the_list(): void
    {
        $this->logFollowUp($this->customer('Early September'), '2026-09-02 10:00', createdAt: '2026-09-01 09:00');
        $this->logFollowUp($this->customer('Mid September'), '2026-09-10 10:00', createdAt: '2026-09-08 09:00');
        $this->logFollowUp($this->customer('August Customer'), '2026-08-20 15:00', createdAt: '2026-08-18 09:00');

        $component = Livewire::test(DashboardFollowUpCalendarWidget::class)
            ->call('openOutcomeList', 'missed')
            ->set('outcomeListFrom', '2026-09-05');

        $this->assertSame(['Mid September'], $this->names($this->outcomeList($component)['customer']));

        $component->set('outcomeListFrom', '2026-08-01');

        $this->assertSame(['August Customer', 'Early September', 'Mid September'], $this->names($this->outcomeList($component)['customer']));

        // A "to" before "from" is read as the single "from" day.
        $component->set('outcomeListFrom', '2026-09-02')->set('outcomeListTo', '2026-08-25');

        $this->assertSame(['Early September'], $this->names($this->outcomeList($component)['customer']));
        $this->assertSame('2026-09-02', $component->instance()->outcomeListPeriod()[1]->toDateString());
    }

    public function test_switching_chips_keeps_the_picked_dates_and_open_covers_pending_and_upcoming(): void
    {
        $this->logFollowUp($this->customer('Missed Customer'), '2026-09-10 15:00', createdAt: '2026-09-08 09:00');
        $this->logFollowUp($this->customer('Pending Customer'), '2026-09-15 15:00');
        $this->logFollowUp($this->customer('Upcoming Customer'), '2026-09-25 15:00');
        $this->onTimeFollowUp($this->customer('Kept Customer'), '2026-09-05 10:00');
        $this->lateFollowUp($this->customer('Late Customer'), '2026-09-03 10:00');

        $component = Livewire::test(DashboardFollowUpCalendarWidget::class)
            ->call('openOutcomeList', 'missed')
            ->set('outcomeListFrom', '2026-09-04')
            ->call('openOutcomeList', 'open')
            ->assertSet('outcomeListFrom', '2026-09-04');

        $this->assertSame(['Pending Customer', 'Upcoming Customer'], $this->names($this->outcomeList($component)['customer']));

        $component->call('openOutcomeList', 'on_time');
        $this->assertSame(['Kept Customer'], $this->names($this->outcomeList($component)['customer']));

        $component->call('openOutcomeList', 'late');
        $this->assertSame([], $this->names($this->outcomeList($component)['customer']), 'Due 3 Sep, before the picked dates.');

        $component->set('outcomeListFrom', '2026-09-01');
        $this->assertSame(['Late Customer'], $this->names($this->outcomeList($component)['customer']));
    }

    public function test_show_more_adds_a_page_of_cards(): void
    {
        foreach (range(1, 30) as $day) {
            $this->logFollowUp($this->customer('Customer '.$day), '2026-09-01 10:00', createdAt: '2026-08-30 09:00');
        }

        $component = Livewire::test(DashboardFollowUpCalendarWidget::class)->call('openOutcomeList', 'missed');
        $customer = $this->outcomeList($component)['customer'];

        $this->assertSame(30, $customer['total']);
        $this->assertCount(25, $customer['followUps']);
        $component->assertSee('Showing 25 of 30')->assertSee('Show more');

        $component->call('showMoreOutcomeList');

        $this->assertCount(30, $this->outcomeList($component)['customer']['followUps']);
        $component->assertDontSee('Show more');
    }

    public function test_closing_the_list_clears_it_and_bad_input_is_ignored(): void
    {
        $this->logFollowUp($this->customer('Missed Customer'), '2026-09-10 15:00', createdAt: '2026-09-08 09:00');

        $component = Livewire::test(DashboardFollowUpCalendarWidget::class)
            ->call('openOutcomeList', 'bogus')
            ->assertSet('outcomeListFilter', null);

        $this->assertSame([], $this->outcomeList($component));

        $component->call('openOutcomeList', 'missed')
            ->set('outcomeListFrom', 'not-a-date')
            ->set('outcomeListTo', '0002-09-30');

        // Unreadable dates fall back to the month on screen.
        [$from, $to] = $component->instance()->outcomeListPeriod();
        $this->assertSame(['2026-09-01', '2026-09-30'], [$from->toDateString(), $to->toDateString()]);
        $this->assertSame(['Missed Customer'], $this->names($this->outcomeList($component)['customer']));

        $component->call('closeOutcomeList')
            ->assertSet('outcomeListFilter', null)
            ->assertSet('outcomeListFrom', null)
            ->assertDontSee('Missed Customer');
    }

    public function test_the_dashboard_list_opens_on_the_week_on_screen(): void
    {
        Livewire::test(DashboardFollowUpCalendarWidget::class)
            ->call('selectCalendarDate', '2026-09-16')
            ->call('switchCalendarView', 'week')
            ->call('openOutcomeList', 'open')
            ->assertSet('outcomeListFrom', '2026-09-14')
            ->assertSet('outcomeListTo', '2026-09-20');
    }

    public function test_the_customer_calendar_lists_its_own_follow_ups_in_one_list(): void
    {
        $this->logFollowUp($this->customer('Missed Customer'), '2026-09-10 15:00', createdAt: '2026-09-08 09:00');

        $component = Livewire::test(CustomerFollowUpCalendarWidget::class)
            ->call('openOutcomeList', 'missed')
            ->assertSet('outcomeListFrom', '2026-09-01')
            ->assertSet('outcomeListTo', '2026-09-30')
            ->assertSee('Missed Customer')
            ->assertDontSeeHtml('x-data="{ outcomeTab:');

        $this->assertSame(['follow-ups'], array_keys($this->outcomeList($component)));
    }

    /**
     * @return array<string, array{label: string, total: int, followUps: Collection<int, FollowUp>}>
     */
    private function outcomeList(Testable $component): array
    {
        return $component->instance()->outcomeList();
    }

    /**
     * @param  array{followUps: Collection<int, FollowUp>}  $list
     * @return list<string>
     */
    private function names(array $list): array
    {
        return $list['followUps']->map(fn (FollowUp $followUp): string => $followUp->display_name)->all();
    }

    private function customer(string $name, ?Employee $owner = null): Customer
    {
        $owner ??= $this->caller;

        return Customer::factory()->create(['customer_name' => $name, 'assign_to' => $owner->id, 'employee_id' => $owner->id]);
    }

    private function missedLead(string $name, string $nextDate): Lead
    {
        return Lead::create([
            'employee_id' => $this->caller->id,
            'customer_name' => $name,
            'mobile_no' => '98765'.random_int(10000, 99999),
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'call later',
            'next_follow_up_date' => Carbon::parse($nextDate),
        ]);
    }

    /** Due on $due and acted on the same day. */
    private function onTimeFollowUp(Customer $customer, string $due): void
    {
        $this->logFollowUp($customer, $due, createdAt: Carbon::parse($due)->subDays(2)->toDateTimeString());
        $this->logFollowUp($customer, null, createdAt: Carbon::parse($due)->addHour()->toDateTimeString());
    }

    /** Due on $due and acted on three days later, past the grace period. */
    private function lateFollowUp(Customer $customer, string $due): void
    {
        $this->logFollowUp($customer, $due, createdAt: Carbon::parse($due)->subDays(2)->toDateTimeString());
        $this->logFollowUp($customer, null, createdAt: Carbon::parse($due)->addDays(3)->toDateTimeString());
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
