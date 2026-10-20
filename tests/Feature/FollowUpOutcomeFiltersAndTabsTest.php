<?php

namespace Tests\Feature;

use App\Filament\Pages\CustomerFollowUpOutcomes;
use App\Filament\Pages\LeadFollowUpOutcomes;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The follow-up outcome listings can be narrowed by status, type, record,
 * manager and team leader, and show On time / Late / Missed / Open as
 * pills in the table toolbar whose counts follow those filters.
 */
class FollowUpOutcomeFiltersAndTabsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->travelTo(Carbon::parse('2026-09-16 12:00:00'));

        $this->caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);

        $this->actingAs(User::factory()->create(['employee_id' => $this->caller->id]));
    }

    public function test_the_outcome_tabs_are_colour_coded_pills_in_the_table_toolbar_between_per_page_and_search(): void
    {
        $this->leadFollowUp('Missed One', '2026-09-10 11:00');
        $this->leadFollowUp('Missed Two', '2026-09-11 11:00');
        $this->leadFollowUp('Open One', '2026-09-25 11:00');

        // A real request: the tabs are a panel render hook, which
        // Livewire::test() does not boot.
        $this->actingAs(User::factory()->create()->assignRole('Admin'));

        $tabs = $this->outcomeTabsHtml($this->get(LeadFollowUpOutcomes::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['fi-ta-header-toolbar', 'fynn-table-records-per-page-top', 'fynn-outcome-tabs', 'fi-ta-search-field'], escape: false)
            ->getContent());

        $this->assertStringContainsString('role="tablist"', $tabs);
        $this->assertStringContainsString("\$set('activeTab', 'on_time')", $tabs);
        $this->assertStringContainsString("\$set('activeTab', 'open')", $tabs);
        $this->assertStringContainsString('title="Not acted on and overdue"', $tabs);
        $this->assertMatchesRegularExpression('/On time.*>0<.*Late.*>0<.*Missed.*>2<.*Open.*>1</s', $tabs);

        // Each pill wears its own colour; only the active one (Missed, the
        // default) is filled in and ringed.
        $this->assertMatchesRegularExpression('/aria-selected="true"[^>]*class="[^"]*bg-rose-600[^"]*ring-2/s', $tabs);
        $this->assertMatchesRegularExpression('/aria-selected="false"[^>]*class="[^"]*bg-emerald-50/s', $tabs);
        $this->assertMatchesRegularExpression('/aria-selected="false"[^>]*class="[^"]*bg-sky-50/s', $tabs);
        $this->assertSame(1, substr_count($tabs, 'aria-selected="true"'));

        $tabs = $this->outcomeTabsHtml($this->get(LeadFollowUpOutcomes::getUrl(['activeTab' => 'open']))->assertOk()->getContent());

        $this->assertMatchesRegularExpression('/aria-selected="true"[^>]*class="[^"]*bg-sky-600[^"]*ring-2/s', $tabs);
    }

    public function test_the_customer_page_shows_the_tabs_in_its_toolbar_too(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('Admin'));

        $this->get(CustomerFollowUpOutcomes::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['fi-ta-header-toolbar', 'fynn-table-records-per-page-top', 'fynn-outcome-tabs', 'role="tablist"', 'fi-ta-search-field'], escape: false);
    }

    public function test_other_listings_do_not_get_the_outcome_tabs(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('Admin'));

        $this->get(ComplaintResource::getUrl('index'))
            ->assertOk()
            ->assertSee('fynn-table-records-per-page-top', escape: false)
            ->assertDontSee('fynn-outcome-tabs', escape: false);
    }

    public function test_the_status_filter_narrows_the_table_and_the_tab_counts(): void
    {
        $callBack = $this->leadFollowUp('Call Back Lead', '2026-09-10 11:00', status: 'Call Back');
        $interested = $this->leadFollowUp('Interested Lead', '2026-09-10 11:00', status: 'Interested');
        $this->leadFollowUp('Open Interested', '2026-09-25 11:00', status: 'Interested');

        $component = Livewire::test(LeadFollowUpOutcomes::class)
            ->assertCanSeeTableRecords([$callBack, $interested]);

        $this->assertSame(2, $component->instance()->outcomeCount('missed'));

        $component->filterTable('status', ['Interested'])
            ->assertCanSeeTableRecords([$interested])
            ->assertCanNotSeeTableRecords([$callBack]);

        $this->assertSame(1, $component->instance()->outcomeCount('missed'));
        $this->assertSame(1, $component->instance()->outcomeCount('open'));
        $this->assertSame('1', $component->instance()->getCachedTabs()['missed']->getBadge());
    }

    public function test_the_type_filter_narrows_the_table(): void
    {
        $call = $this->leadFollowUp('Called Lead', '2026-09-10 11:00', type: 'Call');
        $whatsApp = $this->leadFollowUp('Messaged Lead', '2026-09-10 11:00', type: 'WhatsApp');

        Livewire::test(LeadFollowUpOutcomes::class)
            ->filterTable('follow_up_type', ['WhatsApp'])
            ->assertCanSeeTableRecords([$whatsApp])
            ->assertCanNotSeeTableRecords([$call]);
    }

    public function test_the_record_filter_is_offered_only_where_there_is_more_than_one_kind(): void
    {
        $lead = $this->leadFollowUp('Raw Lead', '2026-09-10 11:00');

        Livewire::test(LeadFollowUpOutcomes::class)
            ->assertTableFilterVisible('source')
            ->filterTable('source', ['lead'])
            ->assertCanSeeTableRecords([$lead])
            ->filterTable('source', ['ai_record'])
            ->assertCanNotSeeTableRecords([$lead]);

        Livewire::test(CustomerFollowUpOutcomes::class)
            ->assertTableFilterHidden('source');
    }

    public function test_the_manager_and_team_leader_filters_keep_their_teams_follow_ups(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('Admin'));

        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER, 'exit_status' => 'no']);
        $teamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER, 'exit_status' => 'no', 'manager_id' => $manager->id]);
        $teamCaller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'exit_status' => 'no',
            'superviser_id' => $teamLeader->id,
            'manager_id' => $manager->id,
        ]);

        $inTeam = $this->leadFollowUp('Team Lead', '2026-09-10 11:00', owner: $teamCaller);
        $outsideTeam = $this->leadFollowUp('Other Lead', '2026-09-10 11:00');

        Livewire::test(LeadFollowUpOutcomes::class)
            ->assertCanSeeTableRecords([$inTeam, $outsideTeam])
            ->filterTable('manager_id', [$manager->id])
            ->assertCanSeeTableRecords([$inTeam])
            ->assertCanNotSeeTableRecords([$outsideTeam]);

        Livewire::test(LeadFollowUpOutcomes::class)
            ->filterTable('team_leader_id', [$teamLeader->id])
            ->assertCanSeeTableRecords([$inTeam])
            ->assertCanNotSeeTableRecords([$outsideTeam]);
    }

    /** The outcome tabs' markup, from the tabs block up to the search field after it. */
    private function outcomeTabsHtml(string $html): string
    {
        $start = strpos($html, 'class="fynn-outcome-tabs"');
        $end = strpos($html, 'fi-ta-search-field', (int) $start);

        $this->assertNotFalse($start, 'The outcome tabs are missing.');
        $this->assertNotFalse($end, 'The search field does not follow the outcome tabs.');

        return substr($html, $start, $end - $start);
    }

    /** A raw lead logs its own follow-up row when it is created. */
    private function leadFollowUp(string $name, string $nextDate, string $status = 'Call Back', string $type = 'Call', ?Employee $owner = null): FollowUp
    {
        $lead = Lead::create([
            'employee_id' => ($owner ?? $this->caller)->id,
            'customer_name' => $name,
            'mobile_no' => '98765'.random_int(10000, 99999),
            'follow_up_type' => $type,
            'status' => $status,
            'remarks' => 'call later',
            'next_follow_up_date' => Carbon::parse($nextDate),
        ]);

        return FollowUp::query()->where('lead_id', $lead->id)->latest('id')->firstOrFail();
    }
}
