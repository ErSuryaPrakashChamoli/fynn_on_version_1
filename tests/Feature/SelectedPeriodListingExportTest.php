<?php

namespace Tests\Feature;

use App\Filament\Actions\DataDownloadApproval;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Models\Customer;
use App\Models\DataDownloadAuthenticator;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use App\Support\SelectedMonth;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The topbar period (month, financial year or all time) scopes the My
 * Leads and Customers listings and their "Export" buttons — not only on
 * the first page load but on every Livewire round-trip, which is where
 * pagination, filters and exports run.
 */
class SelectedPeriodListingExportTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    private DataDownloadAuthenticator $authenticator;

    /**
     * The topbar cookies the browser would send with every Livewire request.
     *
     * @var array<string, string>
     */
    private array $periodCookies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-24 10:00:00'));

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);

        $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $this->authenticator = DataDownloadAuthenticator::factory()->create();

        Bus::fake();
    }

    public function test_the_period_cookies_survive_the_web_middleware_that_livewire_requests_use(): void
    {
        // Livewire's update route runs under the plain `web` group, whose
        // stock EncryptCookies used to drop the client-set selected_period
        // cookie, sending every export and table round-trip back to the month.
        Route::middleware('web')->get('/_selected-period-probe', fn (): array => [
            'mode' => SelectedMonth::mode(),
            'label' => SelectedMonth::label(),
        ]);

        $this->withUnencryptedCookie('selected_period', 'fy:2025')
            ->get('/_selected-period-probe')
            ->assertExactJson(['mode' => 'fy', 'label' => 'FY 2025-26']);

        $this->withUnencryptedCookie('selected_period', 'all')
            ->get('/_selected-period-probe')
            ->assertExactJson(['mode' => 'all', 'label' => 'All time']);

        $this->withUnencryptedCookie('selected_period', 'month')
            ->withUnencryptedCookie('selected_month', '2026-05')
            ->get('/_selected-period-probe')
            ->assertExactJson(['mode' => 'month', 'label' => 'May 2026']);
    }

    public function test_the_leads_listing_and_export_follow_the_selected_month(): void
    {
        [$thisMonth, $earlierThisYear, $lastYear] = $this->leadsAcrossPeriods();

        $this->listing(ListLeads::class)
            ->assertCanSeeTableRecords([$thisMonth])
            ->assertCanNotSeeTableRecords([$earlierThisYear, $lastYear]);

        $this->assertSame(1, $this->exportLeads());

        $this->selectPeriod('month', '2026-05');

        $this->listing(ListLeads::class)
            ->assertCanSeeTableRecords([$earlierThisYear])
            ->assertCanNotSeeTableRecords([$thisMonth, $lastYear]);

        $this->assertSame(1, $this->exportLeads());
    }

    public function test_the_leads_listing_and_export_follow_the_selected_financial_year(): void
    {
        [$thisMonth, $earlierThisYear, $lastYear] = $this->leadsAcrossPeriods();

        $this->selectPeriod('fy:2026');

        $this->listing(ListLeads::class)
            ->assertCanSeeTableRecords([$thisMonth, $earlierThisYear])
            ->assertCanNotSeeTableRecords([$lastYear]);

        $this->assertSame(2, $this->exportLeads());

        $this->selectPeriod('fy:2025');

        $this->listing(ListLeads::class)
            ->assertCanSeeTableRecords([$lastYear])
            ->assertCanNotSeeTableRecords([$thisMonth, $earlierThisYear]);

        $this->assertSame(1, $this->exportLeads());
    }

    public function test_all_time_lists_and_exports_every_lead(): void
    {
        $leads = $this->leadsAcrossPeriods();

        $this->selectPeriod('all');

        $this->listing(ListLeads::class)
            ->assertCanSeeTableRecords($leads);

        $this->assertSame(3, $this->exportLeads());
    }

    public function test_the_status_beside_export_narrows_the_leads_listing_and_export(): void
    {
        $pending = $this->createLead('2026-11-10 12:00:00', 'Pending');
        $interested = $this->createLead('2026-11-12 12:00:00', 'Interested');
        $freeText = $this->createLead('2026-11-15 12:00:00', 'after 7PM');
        $callBackInSeptember = $this->createLead('2026-09-05 12:00:00', 'Call Back');

        $this->listing(ListLeads::class)
            ->assertSee('Status: All statuses')
            ->assertSee('Leads added in Nov 2026, every status.')
            ->assertCanSeeTableRecords([$pending, $interested, $freeText])
            ->assertCanNotSeeTableRecords([$callBackInSeptember])
            ->callAction(TestAction::make('leadStatus_interested')->table())
            ->assertSet('leadStatus', 'Interested')
            ->assertSee('Status: Interested')
            ->assertSee('Interested leads added in Nov 2026.')
            ->assertCanSeeTableRecords([$interested])
            ->assertCanNotSeeTableRecords([$pending, $freeText, $callBackInSeptember])
            // "Other": statuses outside the list, so the statuses add up.
            ->callAction(TestAction::make('leadStatus_other')->table())
            ->assertCanSeeTableRecords([$freeText])
            ->assertCanNotSeeTableRecords([$pending, $interested])
            ->callAction(TestAction::make('leadStatus_call_back')->table())
            ->assertSet('leadStatus', 'Call Back')
            ->assertCanNotSeeTableRecords([$pending, $interested, $freeText, $callBackInSeptember]);

        $this->assertSame(3, $this->exportLeads());
        $this->assertSame(1, $this->exportLeads('Interested'));
        $this->assertSame(1, $this->exportLeads('other'));
        $this->assertSame(0, $this->exportLeads('Call Back'));

        $this->selectPeriod('fy:2026');
        $this->assertSame(1, $this->exportLeads('Call Back'));
        $this->assertSame(4, $this->exportLeads());
    }

    public function test_an_unknown_lead_status_in_the_url_falls_back_to_all_statuses(): void
    {
        $pending = $this->createLead('2026-11-10 12:00:00', 'Pending');
        $interested = $this->createLead('2026-11-12 12:00:00', 'Interested');

        Livewire::withQueryParams(['status' => 'Converted'])
            ->test(ListLeads::class)
            ->assertSee('Status: All statuses')
            ->assertCanSeeTableRecords([$pending, $interested]);
    }

    public function test_all_statuses_list_and_export_the_files_added_in_the_period(): void
    {
        [$addedInNovember, $addedInSeptember, $addedInSeptemberDisbursedInNovember, $disbursedLastYear] = $this->customersAcrossPeriods();

        $this->listing(ListCustomers::class)
            ->assertCanSeeTableRecords([$addedInNovember])
            ->assertCanNotSeeTableRecords([$addedInSeptember, $addedInSeptemberDisbursedInNovember, $disbursedLastYear])
            ->assertSee('Files added in Nov 2026, every status. Pick Disbursed to see files disbursed in Nov 2026.');

        $this->assertSame(1, $this->exportCustomers());

        // Added in September, disbursed in November: listed under September.
        // A file still in progress no longer shows under every month either.
        $this->selectPeriod('month', '2026-09');

        $this->listing(ListCustomers::class)
            ->assertCanSeeTableRecords([$addedInSeptember, $addedInSeptemberDisbursedInNovember])
            ->assertCanNotSeeTableRecords([$addedInNovember, $disbursedLastYear]);

        $this->assertSame(2, $this->exportCustomers());

        // The months of the year add up to the year, and the years to all time.
        $this->selectPeriod('fy:2026');
        $this->assertSame(3, $this->exportCustomers());

        $this->selectPeriod('fy:2025');
        $this->assertSame(1, $this->exportCustomers());

        $this->selectPeriod('all');

        $this->listing(ListCustomers::class)
            ->assertCanSeeTableRecords([$addedInNovember, $addedInSeptember, $addedInSeptemberDisbursedInNovember, $disbursedLastYear])
            ->assertSee('All customer files, every status.');

        $this->assertSame(4, $this->exportCustomers());
    }

    public function test_disbursed_lists_and_exports_the_files_disbursed_in_the_period(): void
    {
        [$addedInNovember, $addedInSeptember, $addedInSeptemberDisbursedInNovember, $disbursedLastYear] = $this->customersAcrossPeriods();

        // November + Disbursed: the file added in September shows here.
        $this->listing(ListCustomers::class)
            ->set('customerStatus', 'sanctioned')
            ->assertSee('Files disbursed in Nov 2026.')
            ->assertCanSeeTableRecords([$addedInSeptemberDisbursedInNovember])
            ->assertCanNotSeeTableRecords([$addedInNovember, $addedInSeptember, $disbursedLastYear]);

        $this->assertSame(1, $this->exportCustomers('sanctioned'));

        // September + Disbursed: nothing was disbursed in September.
        $this->selectPeriod('month', '2026-09');
        $this->assertSame(0, $this->exportCustomers('sanctioned'));

        $this->selectPeriod('fy:2025');
        $this->assertSame(1, $this->exportCustomers('sanctioned'));
    }

    public function test_a_disbursed_file_without_a_disbursal_date_counts_by_the_date_added(): void
    {
        $legacy = $this->createCustomer('sanctioned', '2026-11-03 12:00:00');

        $this->listing(ListCustomers::class)
            ->set('customerStatus', 'sanctioned')
            ->assertCanSeeTableRecords([$legacy]);
    }

    public function test_the_status_beside_export_narrows_the_listing_and_the_export(): void
    {
        [$addedInNovember, $addedInSeptember, $disbursedInNovember] = $this->customersAcrossPeriods();

        $this->selectPeriod('fy:2026');

        $this->listing(ListCustomers::class)
            ->assertSee('Status: All statuses')
            ->callAction(TestAction::make('customerStatus_sanctioned')->table())
            ->assertSet('customerStatus', 'sanctioned')
            ->assertSee('Status: Disbursed')
            ->assertSee('Files disbursed in FY 2026-27.')
            ->assertCanSeeTableRecords([$disbursedInNovember])
            ->assertCanNotSeeTableRecords([$addedInNovember, $addedInSeptember])
            ->callAction(TestAction::make('customerStatus_sfl')->table())
            ->assertSee('SFL files added in FY 2026-27.')
            ->assertCanSeeTableRecords([$addedInSeptember])
            ->assertCanNotSeeTableRecords([$addedInNovember, $disbursedInNovember])
            ->callAction(TestAction::make('customerStatus_all')->table())
            ->assertCanSeeTableRecords([$addedInNovember, $addedInSeptember, $disbursedInNovember]);

        $this->assertSame(1, $this->exportCustomers('sanctioned'));
        $this->assertSame(1, $this->exportCustomers('not_started'));
        $this->assertSame(0, $this->exportCustomers('approved'));
        $this->assertSame(3, $this->exportCustomers());
    }

    public function test_an_unknown_status_in_the_url_falls_back_to_all_statuses(): void
    {
        [$addedInNovember, $addedInSeptember] = $this->customersAcrossPeriods();

        Livewire::withQueryParams(['status' => 'finalized'])
            ->withCookies($this->periodCookies)
            ->test(ListCustomers::class)
            ->assertSee('Status: All statuses')
            ->assertCanSeeTableRecords([$addedInNovember])
            ->assertCanNotSeeTableRecords([$addedInSeptember]);
    }

    /**
     * @return array{0: Customer, 1: Customer, 2: Customer, 3: Customer}
     */
    private function customersAcrossPeriods(): array
    {
        return [
            $this->createCustomer('not_started', '2026-11-10 12:00:00'),
            $this->createCustomer('sfl', '2026-09-05 12:00:00'),
            // Added in September, disbursed in November.
            $this->createCustomer('sanctioned', '2026-09-01 12:00:00', '2026-11-05'),
            $this->createCustomer('sanctioned', '2025-06-01 12:00:00', '2025-07-01'),
        ];
    }

    private function createCustomer(string $journeyStatus, string $createdAt, ?string $disbursalDate = null): Customer
    {
        return Customer::factory()->create([
            'employee_id' => $this->caller->id,
            'journey_status' => $journeyStatus,
            'disbursal_status' => $disbursalDate ? 'disbursed' : null,
            'disbursal_date' => $disbursalDate,
            'created_at' => Carbon::parse($createdAt),
        ]);
    }

    /**
     * @return array{0: Lead, 1: Lead, 2: Lead} this month, earlier in FY 2026-27, FY 2025-26
     */
    private function leadsAcrossPeriods(): array
    {
        return [
            $this->createLead('2026-11-10 12:00:00'),
            $this->createLead('2026-05-15 12:00:00'),
            $this->createLead('2025-06-01 12:00:00'),
        ];
    }

    /**
     * Picks a period in the topbar: the cookies go with the listing's
     * first render and every later Livewire call (filters, exports).
     */
    private function selectPeriod(string $period, ?string $month = null): void
    {
        $this->periodCookies[SelectedMonth::PERIOD_COOKIE] = $period;

        if ($month !== null) {
            $this->periodCookies[SelectedMonth::COOKIE] = $month;
        }
    }

    /**
     * @param  class-string  $page
     */
    private function listing(string $page): Testable
    {
        return Livewire::withCookies($this->periodCookies)->test($page);
    }

    private function exportLeads(string $status = 'all'): int
    {
        return $this->runExport(ListLeads::class, ['leadStatus' => $status]);
    }

    private function exportCustomers(string $status = 'all'): int
    {
        return $this->runExport(ListCustomers::class, ['customerStatus' => $status]);
    }

    /**
     * @param  class-string  $page
     * @param  array<string, mixed>  $state  page properties to set first
     */
    private function runExport(string $page, array $state = []): int
    {
        Export::query()->delete();

        // Each owner code approves one download; free it for the next one.
        DataDownloadAuthenticator::query()->update(['last_used_timestep' => 0]);

        $this->listing($page)
            ->set($state)
            ->callAction(TestAction::make('export')->table(), [
                DataDownloadApproval::CODE_FIELD => (new Google2FA)->getCurrentOtp($this->authenticator->secret),
            ])
            ->assertHasNoActionErrors();

        return Export::sole()->total_rows;
    }

    private function createLead(string $createdAt, string $status = 'Pending'): Lead
    {
        $lead = Lead::create([
            'employee_id' => $this->caller->id,
            'customer_name' => fake()->name(),
            'mobile_no' => (string) fake()->numerify('9#########'),
            'follow_up_date' => now(),
            'follow_up_type' => 'Call',
            'status' => $status,
            'remarks' => 'Initial call',
            'is_converted' => false,
        ]);

        Lead::query()->whereKey($lead->getKey())->update(['created_at' => Carbon::parse($createdAt)]);

        return $lead->refresh();
    }
}
