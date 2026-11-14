<?php

namespace Tests\Feature;

use App\Filament\Actions\DataDownloadApproval;
use App\Filament\Exports\CustomerExporter;
use App\Filament\Exports\EmployeePerformanceReportExporter;
use App\Filament\Exports\LeadAssignmentReportExporter;
use App\Filament\Exports\LeadExporter;
use App\Filament\Pages\DataDownload;
use App\Filament\Pages\EmployeeHierarchy;
use App\Filament\Pages\MyProfile;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\EmployeePerformanceReports\Pages\ListEmployeePerformanceReports;
use App\Filament\Resources\LeadAssignmentReports\Pages\ListLeadAssignmentReports;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\UserLoginSessions\Pages\ListUserLoginSessions;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Models\Customer;
use App\Models\DataDownloadAuthenticator;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use App\Services\DataDownloadAuthenticatorService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Bus\ChainedBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Data Download: Admin-only downloads of leads and customers by date added,
 * every field available, each approved with the code from the owner's
 * authenticator app (set up once, replaceable only with a current code).
 * Every export of leads, customers or assigned leads — including the
 * Leads / Customers list buttons and the lead assignment report — is
 * Admin-only and needs the same code; no other role can export them.
 */
class DataDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caller;

    private Employee $callerEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);

        $this->admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $this->admin->assignRole('Admin');

        $this->callerEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $this->caller = User::factory()->create(['employee_id' => $this->callerEmployee->id]);
        $this->caller->assignRole('Caller');

        Bus::fake();
    }

    // ---- Access -------------------------------------------------------------

    public function test_the_admin_sees_the_page_in_the_sidebar_and_can_open_it(): void
    {
        $this->actingAs($this->admin);

        $this->followingRedirects()->get('/admin')->assertOk()->assertSee(e(DataDownload::getUrl()), false);
        $this->get(DataDownload::getUrl())->assertOk()->assertSee('Owner approval is not set up yet');
    }

    public function test_other_roles_neither_see_nor_open_the_page(): void
    {
        $this->actingAs($this->caller);

        $this->followingRedirects()->get('/admin')->assertOk()->assertDontSee(e(DataDownload::getUrl()), false);
        $this->get(DataDownload::getUrl())->assertForbidden();
    }

    // ---- Owner authenticator setup ------------------------------------------

    public function test_downloads_stay_disabled_until_the_owner_sets_up_the_authenticator(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(DataDownload::class)
            ->assertActionVisible('setUpAuthenticator')
            ->assertActionHidden('replaceAuthenticator')
            ->assertActionDisabled('downloadLeads')
            ->assertActionDisabled('downloadCustomers');
    }

    public function test_the_owner_sets_up_the_authenticator_once_with_a_code_from_the_app(): void
    {
        $this->actingAs($this->admin);

        $component = Livewire::test(DataDownload::class)->mountAction('setUpAuthenticator');
        $secret = $this->mountedSecret($component);

        $component
            ->setActionData(['owner_name' => 'Ramesh Gupta', 'code' => $this->currentCode($secret)])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Owner authenticator set up');

        $authenticator = DataDownloadAuthenticator::sole();
        $this->assertSame('Ramesh Gupta', $authenticator->owner_name);
        $this->assertSame($secret, $authenticator->secret);
        $this->assertSame($this->admin->id, $authenticator->configured_by);
        $this->assertNotSame($secret, DB::table('data_download_authenticators')->value('secret'), 'The secret is stored encrypted.');

        $this->assertTrue(Activity::query()->inLog(DataDownloadAuthenticatorService::LOG_NAME)
            ->where('description', 'Set up the owner authenticator for data downloads')
            ->exists());

        Livewire::test(DataDownload::class)
            ->assertActionHidden('setUpAuthenticator')
            ->assertActionVisible('replaceAuthenticator')
            ->assertActionEnabled('downloadLeads')
            ->assertActionEnabled('downloadCustomers');
    }

    public function test_setup_rejects_a_code_that_does_not_match_the_qr_code(): void
    {
        $this->actingAs($this->admin);

        $component = Livewire::test(DataDownload::class)->mountAction('setUpAuthenticator');
        $secret = $this->mountedSecret($component);

        $component
            ->setActionData(['owner_name' => 'Ramesh Gupta', 'code' => $this->wrongCode($secret)])
            ->callMountedAction()
            ->assertHasActionErrors(['code']);

        $this->assertFalse(DataDownloadAuthenticator::query()->exists());
    }

    public function test_setup_is_refused_once_an_authenticator_exists(): void
    {
        DataDownloadAuthenticator::factory()->create();
        $service = app(DataDownloadAuthenticatorService::class);
        $secret = $service->generateSecret();

        $this->expectException(RuntimeException::class);

        $service->setUp('Someone Else', $secret, $this->currentCode($secret), $this->admin);
    }

    public function test_moving_to_a_new_phone_needs_a_code_from_the_current_one(): void
    {
        $this->actingAs($this->admin);
        $old = DataDownloadAuthenticator::factory()->create(['owner_name' => 'Ramesh Gupta']);
        $oldSecret = $old->secret;

        $component = Livewire::test(DataDownload::class)->mountAction('replaceAuthenticator');
        $newSecret = $this->mountedSecret($component);

        $component
            ->setActionData([
                DataDownloadApproval::CODE_FIELD => $this->wrongCode($oldSecret),
                'owner_name' => 'Ramesh Gupta',
                'code' => $this->currentCode($newSecret),
            ])
            ->callMountedAction()
            ->assertHasActionErrors([DataDownloadApproval::CODE_FIELD]);

        $this->assertSame($oldSecret, $old->fresh()->secret);

        $component
            ->setActionData([
                DataDownloadApproval::CODE_FIELD => $this->currentCode($oldSecret),
                'owner_name' => 'Ramesh Gupta',
                'code' => $this->currentCode($newSecret),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Owner authenticator moved');

        $this->assertSame($newSecret, $old->fresh()->secret);
        $this->assertFalse(app(DataDownloadAuthenticatorService::class)->accepts($this->currentCode($oldSecret)), 'Codes from the old phone stop working.');
    }

    public function test_the_reset_command_removes_the_authenticator(): void
    {
        DataDownloadAuthenticator::factory()->create(['owner_name' => 'Ramesh Gupta']);

        $this->artisan('data-download:reset-authenticator', ['--force' => true])->assertSuccessful();

        $this->assertFalse(DataDownloadAuthenticator::query()->exists());
        $this->assertTrue(Activity::query()->inLog(DataDownloadAuthenticatorService::LOG_NAME)
            ->where('description', 'Removed the owner authenticator for data downloads')
            ->exists());
    }

    // ---- Downloads ----------------------------------------------------------

    public function test_a_valid_owner_code_downloads_the_leads_added_in_the_chosen_dates(): void
    {
        $this->actingAs($this->admin);
        $authenticator = DataDownloadAuthenticator::factory()->create();

        $this->createLead('2026-10-31 23:59:00');
        $this->createLead('2026-11-01 00:00:00');
        $this->createLead('2026-11-10 12:00:00');
        $this->createLead('2026-11-11 00:00:01');

        Livewire::test(DataDownload::class)
            ->set('data.from', '2026-11-01')
            ->set('data.until', '2026-11-10')
            ->assertSee('Leads · 2')
            ->callAction('downloadLeads', [DataDownloadApproval::CODE_FIELD => $this->currentCode($authenticator->secret)])
            ->assertHasNoActionErrors();

        $export = Export::sole();
        $this->assertSame(LeadExporter::class, $export->exporter);
        $this->assertSame(2, $export->total_rows);
        $this->assertSame($this->admin->id, $export->user_id);
        $this->assertNotNull($authenticator->fresh()->last_used_at);

        $prepareJob = Bus::dispatched(ChainedBatch::class)->sole()->jobs->sole();
        $this->assertInstanceOf(PrepareCsvExport::class, $prepareJob);
        $this->assertArrayNotHasKey(
            DataDownloadApproval::CODE_FIELD,
            (fn (): array => $this->options)->call($prepareJob),
            'The owner\'s code never reaches the queued export job.',
        );

        $activity = Activity::query()->inLog(DataDownloadAuthenticatorService::LOG_NAME)->latest('id')->first();
        $this->assertSame("Downloaded leads with the owner's approval", $activity->description);
        $this->assertSame($this->admin->id, $activity->causer_id);
        $this->assertSame('2026-11-01', $activity->getProperty('from'));
        $this->assertSame('2026-11-10', $activity->getProperty('until'));
        $this->assertContains('mobile_no', $activity->getProperty('columns'));
    }

    public function test_customers_download_with_open_dates_covers_every_customer(): void
    {
        $this->actingAs($this->admin);
        $authenticator = DataDownloadAuthenticator::factory()->create();

        Customer::factory()->count(3)->create(['employee_id' => $this->callerEmployee->id]);

        Livewire::test(DataDownload::class)
            ->set('data.from', null)
            ->set('data.until', null)
            ->callAction('downloadCustomers', [DataDownloadApproval::CODE_FIELD => $this->currentCode($authenticator->secret)])
            ->assertHasNoActionErrors();

        $export = Export::sole();
        $this->assertSame(CustomerExporter::class, $export->exporter);
        $this->assertSame(3, $export->total_rows);
    }

    public function test_a_wrong_or_missing_code_downloads_nothing(): void
    {
        $this->actingAs($this->admin);
        $authenticator = DataDownloadAuthenticator::factory()->create();
        $this->createLead('2026-11-05 10:00:00');

        Livewire::test(DataDownload::class)
            ->callAction('downloadLeads', [DataDownloadApproval::CODE_FIELD => $this->wrongCode($authenticator->secret)])
            ->assertHasActionErrors([DataDownloadApproval::CODE_FIELD]);

        Livewire::test(DataDownload::class)
            ->callAction('downloadLeads', [DataDownloadApproval::CODE_FIELD => null])
            ->assertHasActionErrors([DataDownloadApproval::CODE_FIELD => 'required']);

        $this->assertSame(0, Export::query()->count());
        $this->assertNull($authenticator->fresh()->last_used_at);
    }

    public function test_each_code_approves_only_one_download(): void
    {
        $this->actingAs($this->admin);
        $authenticator = DataDownloadAuthenticator::factory()->create();
        $code = $this->currentCode($authenticator->secret);

        Livewire::test(DataDownload::class)
            ->callAction('downloadLeads', [DataDownloadApproval::CODE_FIELD => $code])
            ->assertHasNoActionErrors()
            ->callAction('downloadCustomers', [DataDownloadApproval::CODE_FIELD => $code])
            ->assertHasActionErrors([DataDownloadApproval::CODE_FIELD]);

        $this->assertSame(1, Export::query()->count());
    }

    public function test_too_many_wrong_codes_lock_the_admin_out_for_a_while(): void
    {
        $this->actingAs($this->admin);
        $authenticator = DataDownloadAuthenticator::factory()->create();

        foreach (range(1, DataDownloadAuthenticatorService::MAX_FAILED_ATTEMPTS) as $attempt) {
            Livewire::test(DataDownload::class)
                ->callAction('downloadLeads', [DataDownloadApproval::CODE_FIELD => $this->wrongCode($authenticator->secret)])
                ->assertHasActionErrors([DataDownloadApproval::CODE_FIELD]);
        }

        Livewire::test(DataDownload::class)
            ->callAction('downloadLeads', [DataDownloadApproval::CODE_FIELD => $this->currentCode($authenticator->secret)])
            ->assertHasActionErrors([DataDownloadApproval::CODE_FIELD]);

        $this->assertSame(0, Export::query()->count());
        $this->assertTrue(Activity::query()->inLog(DataDownloadAuthenticatorService::LOG_NAME)
            ->where('description', 'Locked out of data downloads after too many wrong owner codes')
            ->where('causer_id', $this->admin->id)
            ->exists());
    }

    public function test_the_admin_exports_the_leads_list_with_the_owner_code(): void
    {
        $this->actingAs($this->admin);
        $authenticator = DataDownloadAuthenticator::factory()->create();

        $this->createLead(now()->toDateTimeString());
        $this->createLead(now()->toDateTimeString(), Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']));

        Livewire::test(ListLeads::class)
            ->assertActionVisible(TestAction::make('export')->table())
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => $this->wrongCode($authenticator->secret)])
            ->assertHasActionErrors([DataDownloadApproval::CODE_FIELD]);

        $this->assertSame(0, Export::query()->count());

        Livewire::test(ListLeads::class)
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => $this->currentCode($authenticator->secret)])
            ->assertHasNoActionErrors();

        $export = Export::sole();
        $this->assertSame(LeadExporter::class, $export->exporter);
        $this->assertSame($this->admin->id, $export->user_id);
        $this->assertSame(2, $export->total_rows);
    }

    public function test_the_admin_exports_selected_leads_with_the_owner_code(): void
    {
        $this->actingAs($this->admin);
        $authenticator = DataDownloadAuthenticator::factory()->create();

        $selected = $this->createLead(now()->toDateTimeString());
        $this->createLead(now()->toDateTimeString());

        Livewire::test(ListLeads::class)
            ->selectTableRecords([$selected->getKey()])
            ->callAction(TestAction::make('export')->table()->bulk(), [DataDownloadApproval::CODE_FIELD => $this->currentCode($authenticator->secret)])
            ->assertHasNoActionErrors();

        $this->assertSame(1, Export::sole()->total_rows);
    }

    public function test_the_admin_exports_the_customers_list_with_the_owner_code(): void
    {
        $this->actingAs($this->admin);
        $authenticator = DataDownloadAuthenticator::factory()->create();
        Customer::factory()->count(2)->create(['employee_id' => $this->callerEmployee->id]);

        Livewire::test(ListCustomers::class)
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => $this->wrongCode($authenticator->secret)])
            ->assertHasActionErrors([DataDownloadApproval::CODE_FIELD]);

        $this->assertSame(0, Export::query()->count());

        Livewire::test(ListCustomers::class)
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => $this->currentCode($authenticator->secret)])
            ->assertHasNoActionErrors();

        $this->assertSame(CustomerExporter::class, Export::sole()->exporter);
    }

    public function test_the_admin_downloads_the_lead_assignment_report_with_the_owner_code(): void
    {
        $this->actingAs($this->admin);
        $authenticator = DataDownloadAuthenticator::factory()->create();

        Livewire::test(ListLeadAssignmentReports::class)
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => $this->wrongCode($authenticator->secret)])
            ->assertHasActionErrors([DataDownloadApproval::CODE_FIELD]);

        Livewire::test(ListLeadAssignmentReports::class)
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => $this->currentCode($authenticator->secret)])
            ->assertHasNoActionErrors();

        $this->assertSame(LeadAssignmentReportExporter::class, Export::sole()->exporter);
    }

    /**
     * Only the Admin exports: no other role sees an export of leads,
     * customers or assigned leads, even with the owner authenticator set up.
     */
    public function test_nobody_but_the_admin_can_export_leads_customers_or_assigned_leads(): void
    {
        DataDownloadAuthenticator::factory()->create();

        $teamLeaderEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER, 'exit_status' => 'no']);
        Role::firstOrCreate(['name' => 'Team Leader', 'guard_name' => 'web']);
        $teamLeader = User::factory()->create(['employee_id' => $teamLeaderEmployee->id]);
        $teamLeader->assignRole('Team Leader');

        foreach ([$this->caller, $teamLeader] as $user) {
            $this->actingAs($user);

            Livewire::test(ListLeads::class)
                ->assertActionHidden(TestAction::make('export')->table())
                ->assertActionHidden(TestAction::make('export')->table()->bulk());

            Livewire::test(ListCustomers::class)
                ->assertActionHidden(TestAction::make('export')->table())
                ->assertActionHidden(TestAction::make('export')->table()->bulk());

            $this->get(DataDownload::getUrl())->assertForbidden();
        }

        $this->actingAs($teamLeader);
        Livewire::test(ListLeadAssignmentReports::class)
            ->assertOk()
            ->assertActionHidden(TestAction::make('export')->table());

        $this->assertSame(0, Export::query()->count());
    }

    public function test_admin_export_buttons_stay_disabled_until_the_owner_sets_up(): void
    {
        $this->actingAs($this->admin);

        foreach ([ListLeads::class, ListCustomers::class, ListLeadAssignmentReports::class] as $list) {
            Livewire::test($list)
                ->assertActionVisible(TestAction::make('export')->table())
                ->assertActionDisabled(TestAction::make('export')->table());
        }
    }

    // ---- My Profile ---------------------------------------------------------

    public function test_the_admin_sets_up_the_owner_authenticator_from_my_profile(): void
    {
        $this->actingAs($this->admin);
        $setUp = TestAction::make('setUpAuthenticator')->schemaComponent('downloadAuthenticator');

        $component = Livewire::test(MyProfile::class)
            ->assertSee('Data download approval')
            ->assertSee('Not set up.')
            ->assertActionVisible($setUp)
            ->mountAction($setUp);
        $secret = $this->mountedSecret($component);

        $component
            ->setActionData(['owner_name' => 'Ramesh Gupta', 'code' => $this->currentCode($secret)])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Owner authenticator set up');

        $this->assertSame('Ramesh Gupta', DataDownloadAuthenticator::sole()->owner_name);

        // A hidden header action cannot be resolved at all, so the set-up
        // button is checked by its label instead of assertActionHidden().
        Livewire::test(MyProfile::class)
            ->assertSee("Set up on Ramesh Gupta's phone")
            ->assertDontSee('Set up owner authenticator')
            ->assertActionVisible(TestAction::make('replaceAuthenticator')->schemaComponent('downloadAuthenticator'));
    }

    public function test_only_the_admin_sees_the_authenticator_on_my_profile(): void
    {
        $this->actingAs($this->caller);

        Livewire::test(MyProfile::class)
            ->assertOk()
            ->assertDontSee('Data download approval');
    }

    // ---- Other exports: Admin only, no owner code ---------------------------

    public function test_the_admin_uses_the_other_exports_without_an_owner_code(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListEmployeePerformanceReports::class)
            ->assertActionEnabled(TestAction::make('export')->table())
            ->callAction(TestAction::make('export')->table())
            ->assertHasNoActionErrors();

        $this->assertSame(EmployeePerformanceReportExporter::class, Export::sole()->exporter);

        Livewire::test(EmployeeHierarchy::class)->assertActionVisible('export');
        Livewire::test(ListUserLoginSessions::class)->assertActionVisible(TestAction::make('export')->table());
    }

    public function test_the_other_exports_are_hidden_from_everyone_but_the_admin(): void
    {
        Role::firstOrCreate(['name' => 'IT', 'guard_name' => 'web']);
        $itUser = User::factory()->create();
        $itUser->assignRole('IT');

        $this->actingAs($itUser);
        Livewire::test(EmployeeHierarchy::class)->assertActionHidden('export');

        $this->actingAs($this->caller);
        Livewire::test(ListEmployeePerformanceReports::class)
            ->assertActionHidden(TestAction::make('export')->table());
    }

    /**
     * "All the columns available": every database column of leads and
     * customers can be ticked in the export pop-up.
     */
    public function test_the_exports_offer_every_lead_and_customer_field(): void
    {
        foreach (['leads' => LeadExporter::class, 'customers' => CustomerExporter::class] as $table => $exporter) {
            $offered = collect($exporter::getColumns())->map(fn (ExportColumn $column): string => $column->getName())->all();

            $this->assertSame(
                [],
                array_values(array_diff(Schema::getColumnListing($table), $offered)),
                "Every {$table} column must be offered by {$exporter}.",
            );
        }
    }

    // ---- Helpers ------------------------------------------------------------

    private function mountedSecret(Testable $component): string
    {
        $mountedActions = $component->instance()->mountedActions;

        return decrypt(end($mountedActions)['arguments']['encrypted'])['secret'];
    }

    private function currentCode(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    private function wrongCode(string $secret): string
    {
        $service = app(DataDownloadAuthenticatorService::class);

        do {
            $code = sprintf('%06d', random_int(0, 999999));
        } while ($service->codeMatchesSecret($secret, $code));

        return $code;
    }

    private function createLead(string $createdAt, ?Employee $owner = null): Lead
    {
        $lead = Lead::create([
            'employee_id' => ($owner ?? $this->callerEmployee)->id,
            'customer_name' => fake()->name(),
            'mobile_no' => (string) fake()->numerify('9#########'),
            'follow_up_date' => now(),
            'follow_up_type' => 'Call',
            'status' => 'Pending',
            'remarks' => 'Initial call',
            'is_converted' => false,
        ]);

        Lead::query()->whereKey($lead->getKey())->update(['created_at' => Carbon::parse($createdAt)]);

        return $lead;
    }
}
