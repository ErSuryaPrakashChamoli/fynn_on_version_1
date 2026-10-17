<?php

namespace Tests\Feature;

use App\Enums\CibilReportStatus;
use App\Enums\CibilScoreBand;
use App\Filament\Pages\CibilAccessSettings;
use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Filament\Resources\CibilReports\Pages\CreateCibilReport;
use App\Filament\Resources\CibilReports\Pages\ListCibilReports;
use App\Filament\Resources\CibilReports\Pages\ViewCibilReport;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Models\CibilReport;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\Cibil\CibilApplicant;
use App\Services\Cibil\CibilClient;
use App\Services\Cibil\CibilFetchResult;
use App\Services\Cibil\CibilReportService;
use App\Services\Cibil\FakeCibilClient;
use App\Services\Cibil\UnconfiguredCibilClient;
use App\Support\CibilAccess;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The CIBIL module: the Admin grants access by role and/or by user; anyone
 * with access fetches a report (with the customer's consent) and later
 * finds it again in their history; the Admin sees every report.
 */
class CibilReportModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caller;

    private User $manager;

    private Employee $callerEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Caller', 'Manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->app->instance(CibilClient::class, new FakeCibilClient);

        // The month's unset commitment targets would send every request to
        // the target screen first; that gate has its own tests.
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);

        $this->admin = User::factory()->create(['name' => 'Asha Admin']);
        $this->admin->assignRole('Admin');

        $this->callerEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no', 'emp_id' => 'EMP-0101']);
        $this->caller = User::factory()->create(['name' => 'Ravi Caller', 'employee_id' => $this->callerEmployee->id]);
        $this->caller->assignRole('Caller');

        $managerEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER, 'exit_status' => 'no']);
        $this->manager = User::factory()->create(['name' => 'Meera Manager', 'employee_id' => $managerEmployee->id]);
        $this->manager->assignRole('Manager');
    }

    /**
     * @return array<string, mixed>
     */
    private function applicant(array $overrides = []): array
    {
        return [
            'customer_name' => 'Kiran Rao',
            'pan_number' => 'ABCDE1234K',
            'mobile_no' => '9876543210',
            'email' => 'kiran@example.com',
            'date_of_birth' => '1990-05-14',
            'gender' => 'female',
            'pincode' => '110001',
            'consent' => true,
            ...$overrides,
        ];
    }

    private function grantRole(string $role): void
    {
        CibilAccess::sync([$role], []);
    }

    // ---- Access ---------------------------------------------------------

    public function test_the_migration_creates_the_permission(): void
    {
        $this->assertDatabaseHas('permissions', ['name' => CibilAccess::PERMISSION, 'guard_name' => 'web']);
    }

    public function test_admin_can_open_the_module_and_the_access_page(): void
    {
        $this->actingAs($this->admin);

        $this->assertTrue(CibilReportResource::canAccess());
        $this->assertTrue(CibilAccessSettings::canAccess());
        $this->get(CibilReportResource::getUrl('index'))->assertOk();
        $this->get(CibilAccessSettings::getUrl())->assertOk();
    }

    public function test_a_login_without_a_grant_cannot_open_either_screen(): void
    {
        $this->actingAs($this->caller);

        $this->assertFalse(CibilReportResource::canAccess());
        $this->assertFalse(CibilAccessSettings::canAccess());
        $this->get(CibilReportResource::getUrl('index'))->assertForbidden();
        $this->get(CibilAccessSettings::getUrl())->assertForbidden();
    }

    public function test_granting_a_role_opens_the_module_to_everyone_holding_it(): void
    {
        $this->grantRole('Caller');

        $this->assertTrue(CibilAccess::canUse($this->caller->fresh()));
        $this->assertFalse(CibilAccess::canUse($this->manager->fresh()));

        $this->actingAs($this->caller->fresh());
        $this->get(CibilReportResource::getUrl('index'))->assertOk();
        $this->get(CibilAccessSettings::getUrl())->assertForbidden();
    }

    public function test_granting_an_individual_user_opens_the_module_to_that_user_only(): void
    {
        $otherCaller = User::factory()->create();
        $otherCaller->assignRole('Caller');

        CibilAccess::sync([], [$this->caller->id]);

        $this->assertTrue(CibilAccess::canUse($this->caller->fresh()));
        $this->assertFalse(CibilAccess::canUse($otherCaller->fresh()));
        $this->assertSame([$this->caller->id], CibilAccess::grantedUserIds());
    }

    public function test_the_admin_role_is_never_a_grant_because_admins_always_have_access(): void
    {
        CibilAccess::sync(['Admin', 'Caller'], []);

        $this->assertSame(['Caller'], CibilAccess::grantedRoleNames());
        $this->assertTrue(CibilAccess::canUse($this->admin->fresh()));
    }

    public function test_revoking_access_hides_the_module_and_the_users_earlier_reports(): void
    {
        $this->grantRole('Caller');
        $report = app(CibilReportService::class)->fetch($this->caller->fresh(), $this->applicant());

        CibilAccess::sync([], []);
        $caller = $this->caller->fresh();

        $this->assertFalse(CibilAccess::canUse($caller));
        $this->assertFalse(app(CibilReportService::class)->canView($report, $caller));
        $this->assertSame(0, app(CibilReportService::class)->scopeVisible(CibilReport::query(), $caller)->count());
        $this->assertTrue(app(CibilReportService::class)->canView($report, $this->admin));
    }

    public function test_the_access_page_saves_roles_and_users_and_logs_the_change(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CibilAccessSettings::class)
            ->set('data.roles', ['Manager'])
            ->set('data.users', [$this->caller->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('CIBIL access saved');

        $this->assertSame(['Manager'], CibilAccess::grantedRoleNames());
        $this->assertSame([$this->caller->id], CibilAccess::grantedUserIds());
        $this->assertTrue(CibilAccess::canUse($this->manager->fresh()));
        $this->assertTrue(CibilAccess::canUse($this->caller->fresh()));

        $log = Activity::query()->where('description', 'CIBIL access updated')->firstOrFail();
        $this->assertSame($this->admin->id, (int) $log->causer_id);
        $this->assertSame(['Manager'], $log->getProperty('roles'));
    }

    public function test_the_access_page_loads_the_current_grant_and_lists_who_has_access_and_why(): void
    {
        CibilAccess::sync(['Manager'], [$this->caller->id]);
        $this->actingAs($this->admin);

        $page = Livewire::test(CibilAccessSettings::class)
            ->assertSet('data.roles', ['Manager'])
            ->assertSet('data.users', [$this->caller->id])
            ->assertSee('Ravi Caller');

        $via = $page->instance()->usersWithAccess()->pluck('via', 'name');

        $this->assertSame('Admin', $via['Asha Admin']);
        $this->assertSame('Individually', $via['Ravi Caller']);
        $this->assertSame('Role: Manager', $via['Meera Manager']);
    }

    // ---- Fetching -------------------------------------------------------

    public function test_a_successful_fetch_stores_the_score_the_report_and_who_asked(): void
    {
        $this->grantRole('Caller');

        $report = app(CibilReportService::class)->fetch($this->caller->fresh(), $this->applicant(['pan_number' => 'abcde1234k']));

        $this->assertSame(CibilReportStatus::Success, $report->status);
        $this->assertSame('ABCDE1234K', $report->pan_number);
        $this->assertSame($this->caller->id, $report->requested_by);
        $this->assertNotNull($report->score);
        $this->assertNotNull($report->consent_given_at);
        $this->assertNotNull($report->fetched_at);
        $this->assertSame('Kiran Rao', $report->fresh()->response_payload['name']);
        $this->assertSame('1990-05-14', $report->date_of_birth->toDateString());

        // The report is encrypted at rest.
        $raw = (string) CibilReport::query()->toBase()->where('id', $report->id)->value('response_payload');
        $this->assertStringNotContainsString('Kiran Rao', $raw);

        $log = Activity::query()->where('subject_type', $report->getMorphClass())->where('subject_id', $report->id)->firstOrFail();
        $this->assertSame('CIBIL report fetched for Kiran Rao', $log->description);
        $this->assertSame($this->caller->id, (int) $log->causer_id);
    }

    public function test_a_bureau_error_is_kept_as_a_failed_report_with_the_reason(): void
    {
        $report = app(CibilReportService::class)->fetch($this->admin, $this->applicant(['pan_number' => 'FAILX1234X']));

        $this->assertSame(CibilReportStatus::Failed, $report->fresh()->status);
        $this->assertNull($report->score);
        $this->assertSame('The bureau could not find a record for this PAN.', $report->error_message);
    }

    public function test_an_unexpected_error_is_kept_as_failed_with_a_safe_message(): void
    {
        $this->app->instance(CibilClient::class, new class implements CibilClient
        {
            public function fetch(CibilApplicant $applicant): CibilFetchResult
            {
                throw new RuntimeException('cURL error 28 with secret token abc');
            }
        });

        $report = app(CibilReportService::class)->fetch($this->admin, $this->applicant());

        $this->assertSame(CibilReportStatus::Failed, $report->status);
        $this->assertStringNotContainsString('secret', (string) $report->error_message);
        $this->assertStringContainsString('could not be reached', (string) $report->error_message);
    }

    public function test_without_a_connected_api_every_pull_fails_with_a_clear_reason(): void
    {
        $this->app->forgetInstance(CibilClient::class);
        config(['cibil.driver' => 'unconfigured']);

        $this->assertInstanceOf(UnconfiguredCibilClient::class, app(CibilClient::class));

        $report = app(CibilReportService::class)->fetch($this->admin, $this->applicant());

        $this->assertSame(CibilReportStatus::Failed, $report->status);
        $this->assertStringContainsString('not connected', (string) $report->error_message);
    }

    public function test_the_fake_bureau_is_never_bound_in_production_but_always_in_demo(): void
    {
        $this->app->forgetInstance(CibilClient::class);
        config(['cibil.driver' => 'fake']);

        $this->assertInstanceOf(FakeCibilClient::class, app(CibilClient::class));

        $this->app['env'] = 'production';
        $this->assertInstanceOf(UnconfiguredCibilClient::class, app(CibilClient::class));

        config(['cibil.driver' => 'unconfigured', 'demo.runtime.active' => true]);
        $this->assertInstanceOf(FakeCibilClient::class, app(CibilClient::class));

        $this->app['env'] = 'testing';
        config(['demo.runtime.active' => false]);
    }

    public function test_the_customers_consent_is_required(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(CibilReportService::class)->fetch($this->admin, $this->applicant(['consent' => false]));
        } finally {
            $this->assertSame(0, CibilReport::query()->count());
        }
    }

    public function test_an_invalid_pan_mobile_or_pincode_is_rejected(): void
    {
        try {
            app(CibilReportService::class)->fetch($this->admin, $this->applicant([
                'pan_number' => 'ABC123',
                'mobile_no' => '12345',
                'pincode' => '0123',
            ]));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertEqualsCanonicalizing(['pan_number', 'mobile_no', 'pincode'], array_keys($exception->errors()));
        }
    }

    public function test_a_login_without_access_cannot_fetch(): void
    {
        $this->expectException(AuthorizationException::class);

        app(CibilReportService::class)->fetch($this->caller, $this->applicant());
    }

    // ---- History & visibility -------------------------------------------

    public function test_a_user_sees_only_their_own_reports_and_the_admin_sees_all(): void
    {
        CibilAccess::sync(['Caller', 'Manager'], []);

        $mine = CibilReport::factory()->create(['requested_by' => $this->caller->id]);
        $theirs = CibilReport::factory()->create(['requested_by' => $this->manager->id]);

        $this->actingAs($this->caller->fresh());
        Livewire::test(ListCibilReports::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertTableColumnHidden('requester.name');

        $this->actingAs($this->admin);
        Livewire::test(ListCibilReports::class)
            ->assertCanSeeTableRecords([$mine, $theirs])
            ->assertTableColumnVisible('requester.name');
    }

    public function test_a_user_cannot_open_another_users_report(): void
    {
        CibilAccess::sync(['Caller', 'Manager'], []);
        $theirs = CibilReport::factory()->create(['requested_by' => $this->manager->id]);

        $this->actingAs($this->caller->fresh());

        $this->assertFalse(CibilReportResource::canView($theirs));
        $this->get(CibilReportResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
    }

    public function test_deleting_a_login_keeps_the_reports_it_fetched(): void
    {
        $report = CibilReport::factory()->create(['requested_by' => $this->manager->id]);

        $this->manager->delete();

        $this->assertNull($report->fresh()->requested_by);

        $this->actingAs($this->admin);
        Livewire::test(ViewCibilReport::class, ['record' => $report->getRouteKey()])
            ->assertOk()
            ->assertSee('Former user');
    }

    public function test_reports_are_never_edited_or_deleted(): void
    {
        $this->actingAs($this->admin);
        $report = CibilReport::factory()->create();

        $this->assertFalse(CibilReportResource::canEdit($report));
        $this->assertFalse(CibilReportResource::canDelete($report));
        $this->assertFalse(CibilReportResource::canDeleteAny());
    }

    public function test_the_view_page_shows_the_applicants_earlier_reports_and_audits_the_view(): void
    {
        $this->grantRole('Caller');
        $caller = $this->caller->fresh();

        $older = CibilReport::factory()->create(['requested_by' => $caller->id, 'pan_number' => 'ABCDE1234K', 'score' => 612, 'created_at' => now()->subMonths(3)]);
        $otherUsers = CibilReport::factory()->create(['requested_by' => $this->manager->id, 'pan_number' => 'ABCDE1234K', 'score' => 801]);
        $latest = CibilReport::factory()->create(['requested_by' => $caller->id, 'pan_number' => 'ABCDE1234K', 'score' => 745, 'response_payload' => ['accounts' => [['lender' => 'Sample Bank', 'status' => 'Active']]]]);

        $this->actingAs($caller);

        Livewire::test(ViewCibilReport::class, ['record' => $latest->getRouteKey()])
            ->assertOk()
            ->assertSee('745')
            ->assertSee('Sample Bank')
            ->assertSee($older->created_at->format('d M Y, h:i A'))
            ->assertDontSee('801');

        $this->assertSame(
            [$older->id],
            app(CibilReportService::class)->historyFor($latest, $caller)->pluck('id')->all(),
        );
        $this->assertSame(
            [$otherUsers->id, $older->id],
            app(CibilReportService::class)->historyFor($latest, $this->admin)->pluck('id')->all(),
        );

        $this->assertTrue(Activity::query()
            ->where('event', 'viewed')
            ->where('subject_id', $latest->id)
            ->where('causer_id', $caller->id)
            ->exists());
    }

    // ---- Screens --------------------------------------------------------

    public function test_fetching_through_the_form_links_the_customer_file_and_opens_the_report(): void
    {
        $this->grantRole('Caller');
        $customer = Customer::factory()->create(['assign_to' => $this->callerEmployee->id, 'employee_id' => $this->callerEmployee->id, 'pan_number' => 'PQRST6789L']);

        $this->actingAs($this->caller->fresh());

        $page = Livewire::test(CreateCibilReport::class)
            ->fillForm(['fetch_for' => 'customer'])
            ->fillForm(['customer_id' => $customer->id])
            ->assertSchemaStateSet([
                'customer_name' => $customer->customer_name,
                'pan_number' => 'PQRST6789L',
                'mobile_no' => $customer->mobile_no,
            ])
            ->fillForm([
                'mobile_no' => '9876543210',
                'date_of_birth' => '1988-02-11',
                'consent' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('CIBIL report fetched');

        $report = CibilReport::query()->sole();
        $page->assertRedirect(CibilReportResource::getUrl('view', ['record' => $report]));

        $this->assertSame($customer->id, $report->customer_id);
        $this->assertSame('PQRST6789L', $report->pan_number);
        $this->assertSame(CibilReportStatus::Success, $report->status);
    }

    public function test_the_form_refuses_a_customer_file_the_user_cannot_see(): void
    {
        $this->grantRole('Caller');
        $someoneElses = Customer::factory()->create(['assign_to' => $this->manager->employee_id, 'employee_id' => $this->manager->employee_id]);

        $this->actingAs($this->caller->fresh());

        Livewire::test(CreateCibilReport::class)
            ->fillForm(['fetch_for' => 'customer', ...$this->applicant(), 'customer_id' => $someoneElses->id])
            ->call('create')
            ->assertHasFormErrors(['customer_id']);

        $this->assertSame(0, CibilReport::query()->count());
    }

    public function test_the_form_requires_consent_and_valid_details(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateCibilReport::class)
            ->fillForm($this->applicant(['consent' => false, 'pan_number' => 'BAD', 'mobile_no' => '123']))
            ->call('create')
            ->assertHasFormErrors(['consent' => 'accepted', 'pan_number', 'mobile_no']);

        $this->assertSame(0, CibilReport::query()->count());
    }

    public function test_the_form_points_to_an_existing_report_for_the_same_pan(): void
    {
        $this->actingAs($this->admin);
        CibilReport::factory()->create(['requested_by' => $this->admin->id, 'pan_number' => 'ABCDE1234K', 'score' => 777]);

        Livewire::test(CreateCibilReport::class)
            ->fillForm(['pan_number' => 'ABCDE1234K'])
            ->assertSee('Already fetched on')
            ->assertSee('score 777');
    }

    public function test_fetch_again_adds_a_new_report_and_keeps_the_old_one(): void
    {
        $this->actingAs($this->admin);
        $old = CibilReport::factory()->create(['requested_by' => $this->admin->id, 'pan_number' => 'ABCDE1234K', 'score' => 650]);

        Livewire::test(ViewCibilReport::class, ['record' => $old->getRouteKey()])
            ->callAction(TestAction::make('fetchAgain'), ['consent' => true])
            ->assertHasNoActionErrors()
            ->assertNotified('CIBIL report fetched');

        $this->assertSame(2, CibilReport::query()->count());
        $this->assertSame(650, $old->fresh()->score);

        $new = CibilReport::query()->latest('id')->first();
        $this->assertSame('ABCDE1234K', $new->pan_number);
        $this->assertSame($old->customer_name, $new->customer_name);
    }

    public function test_fetch_again_requires_consent(): void
    {
        $this->actingAs($this->admin);
        $old = CibilReport::factory()->create(['requested_by' => $this->admin->id]);

        Livewire::test(ViewCibilReport::class, ['record' => $old->getRouteKey()])
            ->callAction(TestAction::make('fetchAgain'), ['consent' => false])
            ->assertHasActionErrors(['consent' => 'accepted']);

        $this->assertSame(1, CibilReport::query()->count());
    }

    public function test_the_listing_filters_by_score_band_and_status(): void
    {
        $this->actingAs($this->admin);

        $excellent = CibilReport::factory()->create(['score' => 812]);
        $poor = CibilReport::factory()->create(['score' => 540]);
        $failed = CibilReport::factory()->failed()->create();

        Livewire::test(ListCibilReports::class)
            ->filterTable('score_band', CibilScoreBand::Excellent->value)
            ->assertCanSeeTableRecords([$excellent])
            ->assertCanNotSeeTableRecords([$poor, $failed])
            ->resetTableFilters()
            ->filterTable('status', CibilReportStatus::Failed->value)
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$excellent, $poor]);
    }

    public function test_score_bands_follow_the_cibil_ranges(): void
    {
        $this->assertSame(CibilScoreBand::Excellent, CibilScoreBand::fromScore(750));
        $this->assertSame(CibilScoreBand::Good, CibilScoreBand::fromScore(749));
        $this->assertSame(CibilScoreBand::Good, CibilScoreBand::fromScore(700));
        $this->assertSame(CibilScoreBand::Fair, CibilScoreBand::fromScore(650));
        $this->assertSame(CibilScoreBand::Poor, CibilScoreBand::fromScore(649));
        $this->assertSame(CibilScoreBand::Poor, CibilScoreBand::fromScore(300));
        $this->assertSame(CibilScoreBand::NoHistory, CibilScoreBand::fromScore(-1));
    }
}
