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

    public function test_the_customers_export_follows_the_selected_period(): void
    {
        // Undisbursed files show under every period; disbursed ones under
        // the period they were disbursed in.
        Customer::factory()->create(['employee_id' => $this->caller->id, 'disbursal_date' => null]);
        Customer::factory()->create(['employee_id' => $this->caller->id, 'disbursal_date' => '2026-11-05']);
        Customer::factory()->create(['employee_id' => $this->caller->id, 'disbursal_date' => '2025-07-01']);

        $this->assertSame(2, $this->exportCustomers());

        $this->selectPeriod('fy:2025');
        $this->assertSame(2, $this->exportCustomers());

        $this->selectPeriod('fy:2024');
        $this->assertSame(1, $this->exportCustomers());

        $this->selectPeriod('all');
        $this->assertSame(3, $this->exportCustomers());
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

    private function exportLeads(): int
    {
        return $this->runExport(ListLeads::class);
    }

    private function exportCustomers(): int
    {
        return $this->runExport(ListCustomers::class);
    }

    /**
     * @param  class-string  $page
     */
    private function runExport(string $page): int
    {
        Export::query()->delete();

        // Each owner code approves one download; free it for the next one.
        DataDownloadAuthenticator::query()->update(['last_used_timestep' => 0]);

        $this->listing($page)
            ->callAction(TestAction::make('export')->table(), [
                DataDownloadApproval::CODE_FIELD => (new Google2FA)->getCurrentOtp($this->authenticator->secret),
            ])
            ->assertHasNoActionErrors();

        return Export::sole()->total_rows;
    }

    private function createLead(string $createdAt): Lead
    {
        $lead = Lead::create([
            'employee_id' => $this->caller->id,
            'customer_name' => fake()->name(),
            'mobile_no' => (string) fake()->numerify('9#########'),
            'follow_up_date' => now(),
            'follow_up_type' => 'Call',
            'status' => 'Pending',
            'remarks' => 'Initial call',
            'is_converted' => false,
        ]);

        Lead::query()->whereKey($lead->getKey())->update(['created_at' => Carbon::parse($createdAt)]);

        return $lead->refresh();
    }
}
