<?php

namespace Tests\Feature;

use App\Enums\EligibilityLogEvent;
use App\Enums\EligibilityRequestStatus;
use App\Filament\Imports\CustomerImporter;
use App\Filament\Resources\CustomerEligibilityRequests\CustomerEligibilityRequestResource;
use App\Filament\Resources\CustomerEligibilityRequests\Pages\CreateCustomerEligibilityRequest;
use App\Filament\Resources\CustomerEligibilityRequests\Pages\ListCustomerEligibilityRequests;
use App\Filament\Resources\CustomerEligibilityRequests\Schemas\CustomerEligibilityRequestForm;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\CustomerEligibilityLog;
use App\Models\CustomerEligibilityRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\CustomerEligibilityService;
use App\Services\OtherBankSupportService;
use Filament\Actions\Imports\Models\Import;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Eligible is final, Not Eligible is lifted only by an Admin-approved
 * request, Consent Pending can be changed any time — and every step is
 * logged for the owner's chain and the Admin.
 */
class CustomerEligibilityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $caller;

    private User $managerUser;

    private User $callerUser;

    private User $adminUser;

    private User $outsiderUser;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Manager', 'Caller', OtherBankSupportService::ROLE] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'manager_id' => $this->manager->id,
        ]);

        $this->managerUser = User::factory()->create(['employee_id' => $this->manager->id]);
        $this->managerUser->assignRole('Manager');

        $this->callerUser = User::factory()->create(['employee_id' => $this->caller->id]);
        $this->callerUser->assignRole('Caller');

        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('Admin');

        $outsider = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $this->outsiderUser = User::factory()->create(['employee_id' => $outsider->id]);
        $this->outsiderUser->assignRole('Manager');
    }

    /*
    |--------------------------------------------------------------------------
    | Consent Pending — changeable any time, logged
    |--------------------------------------------------------------------------
    */

    public function test_caller_changes_consent_pending_to_eligible_from_the_view_page(): void
    {
        $customer = $this->customer(self::consentPending());

        $this->actingAs($this->callerUser);

        Livewire::test(ViewCustomer::class, ['record' => $customer->id])
            ->callAction(
                TestAction::make('changeEligibility')->schemaComponent('customerEligibility'),
                ['eligibility_status' => 'eligible', 'remarks' => 'Customer gave consent on call.'],
            )
            ->assertHasNoActionErrors();

        $customer->refresh();
        $this->assertSame('eligible', $customer->eligibility_status);
        $this->assertSame('sfl', $customer->journey_status);

        $this->assertDatabaseHas('customer_eligibility_logs', [
            'customer_id' => $customer->id,
            'event' => EligibilityLogEvent::StatusChanged->value,
            'from_status' => 'consent_pending',
            'to_status' => 'eligible',
            'remarks' => 'Customer gave consent on call.',
            'user_id' => $this->callerUser->id,
        ]);
    }

    public function test_consent_pending_to_not_eligible_requires_a_reason(): void
    {
        $customer = $this->customer(self::consentPending());

        $this->actingAs($this->callerUser);

        Livewire::test(ViewCustomer::class, ['record' => $customer->id])
            ->callAction(
                TestAction::make('changeEligibility')->schemaComponent('customerEligibility'),
                ['eligibility_status' => 'not_eligible'],
            )
            ->assertHasActionErrors(['eligibility_reason' => 'required']);

        $this->assertSame('consent_pending', $customer->fresh()->eligibility_status);

        Livewire::test(ViewCustomer::class, ['record' => $customer->id])
            ->callAction(
                TestAction::make('changeEligibility')->schemaComponent('customerEligibility'),
                ['eligibility_status' => 'not_eligible', 'eligibility_reason' => 'cibil_score'],
            )
            ->assertHasNoActionErrors();

        $customer->refresh();
        $this->assertSame('not_eligible', $customer->eligibility_status);
        $this->assertSame('cibil_score', $customer->eligibility_reason);
        $this->assertSame('not_started', $customer->journey_status);
    }

    public function test_manager_changes_eligibility_from_the_edit_page_and_the_form_is_refreshed(): void
    {
        $customer = $this->customer(self::consentPending());

        $this->actingAs($this->managerUser);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->callAction(
                TestAction::make('changeEligibility')->schemaComponent('customerEligibility'),
                ['eligibility_status' => 'eligible'],
            )
            ->assertHasNoActionErrors()
            ->assertSchemaStateSet([
                'eligibility_status' => 'eligible',
                'journey_status' => 'sfl',
            ]);

        $this->assertSame('eligible', $customer->fresh()->eligibility_status);
    }

    public function test_change_notifies_the_owner_chain_and_admin_but_not_the_actor(): void
    {
        $customer = $this->customer(self::consentPending());

        app(CustomerEligibilityService::class)->changeStatus($this->callerUser, $customer, 'eligible');

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->managerUser->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->adminUser->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->callerUser->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->outsiderUser->id]);
    }

    public function test_someone_outside_the_owner_chain_cannot_change_eligibility(): void
    {
        $customer = $this->customer(self::consentPending());

        $this->expectException(AuthorizationException::class);

        app(CustomerEligibilityService::class)->changeStatus($this->outsiderUser, $customer, 'eligible');
    }

    /*
    |--------------------------------------------------------------------------
    | Eligible — locked
    |--------------------------------------------------------------------------
    */

    public function test_eligible_file_cannot_be_changed_by_anyone(): void
    {
        $customer = $this->customer(['eligibility_status' => 'eligible', 'journey_status' => 'sfl']);

        foreach ([$this->callerUser, $this->managerUser, $this->adminUser] as $user) {
            $this->actingAs($user);

            Livewire::test(ViewCustomer::class, ['record' => $customer->id])
                ->assertActionDoesNotExist(TestAction::make('changeEligibility')->schemaComponent('customerEligibility'))
                ->assertActionDoesNotExist(TestAction::make('requestEligibility')->schemaComponent('customerEligibility'));
        }

        $this->expectException(AuthorizationException::class);

        app(CustomerEligibilityService::class)->changeStatus($this->adminUser, $customer, 'not_eligible', 'low_salary');
    }

    public function test_eligibility_field_is_locked_on_the_edit_form(): void
    {
        $customer = $this->customer(self::consentPending());

        $this->actingAs($this->adminUser);

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->assertFormFieldDisabled('eligibility_status');
    }

    /*
    |--------------------------------------------------------------------------
    | Not Eligible — request to Admin
    |--------------------------------------------------------------------------
    */

    public function test_not_eligible_file_cannot_be_changed_directly(): void
    {
        $customer = $this->customer(self::notEligible());

        $this->actingAs($this->callerUser);

        Livewire::test(ViewCustomer::class, ['record' => $customer->id])
            ->assertActionDoesNotExist(TestAction::make('changeEligibility')->schemaComponent('customerEligibility'))
            ->assertActionExists(TestAction::make('requestEligibility')->schemaComponent('customerEligibility'));

        $this->expectException(AuthorizationException::class);

        app(CustomerEligibilityService::class)->changeStatus($this->callerUser, $customer, 'eligible');
    }

    public function test_caller_raises_a_request_and_admin_is_notified(): void
    {
        $customer = $this->customer(self::notEligible());

        $this->actingAs($this->callerUser);

        Livewire::test(ViewCustomer::class, ['record' => $customer->id])
            ->callAction(
                TestAction::make('requestEligibility')->schemaComponent('customerEligibility'),
                ['reason' => 'Salary revised to 45k, new payslip shared.'],
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('customer_eligibility_requests', [
            'customer_id' => $customer->id,
            'requested_by' => $this->callerUser->id,
            'status' => EligibilityRequestStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('customer_eligibility_logs', [
            'customer_id' => $customer->id,
            'event' => EligibilityLogEvent::RequestRaised->value,
        ]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->adminUser->id]);

        $this->assertSame('not_eligible', $customer->fresh()->eligibility_status);

        Livewire::test(ViewCustomer::class, ['record' => $customer->id])
            ->assertSee('Eligibility request waiting for the Admin')
            ->assertActionDoesNotExist(TestAction::make('requestEligibility')->schemaComponent('customerEligibility'));
    }

    public function test_request_reason_is_required(): void
    {
        $customer = $this->customer(self::notEligible());

        $this->actingAs($this->callerUser);

        Livewire::test(ViewCustomer::class, ['record' => $customer->id])
            ->callAction(
                TestAction::make('requestEligibility')->schemaComponent('customerEligibility'),
                ['reason' => ''],
            )
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertDatabaseCount('customer_eligibility_requests', 0);
    }

    public function test_only_one_pending_request_per_file(): void
    {
        $customer = $this->customer(self::notEligible());
        $service = app(CustomerEligibilityService::class);

        $service->raiseRequest($this->callerUser, $customer, 'First');

        $this->expectException(AuthorizationException::class);

        $service->raiseRequest($this->managerUser, $customer, 'Second');
    }

    public function test_admin_approves_from_the_customer_page_and_the_file_moves_to_sfl(): void
    {
        $customer = $this->customer(self::notEligible());
        $request = app(CustomerEligibilityService::class)->raiseRequest($this->callerUser, $customer, 'Please review');

        $this->actingAs($this->adminUser);

        Livewire::test(ViewCustomer::class, ['record' => $customer->id])
            ->assertActionDoesNotExist(TestAction::make('requestEligibility')->schemaComponent('customerEligibility'))
            ->callAction(
                TestAction::make('approveEligibilityRequest')->schemaComponent('customerEligibility'),
                ['review_note' => 'Payslip verified.'],
            )
            ->assertHasNoActionErrors();

        $customer->refresh();
        $this->assertSame('eligible', $customer->eligibility_status);
        $this->assertNull($customer->eligibility_reason);
        $this->assertSame('sfl', $customer->journey_status);

        $request->refresh();
        $this->assertSame(EligibilityRequestStatus::Approved, $request->status);
        $this->assertSame($this->adminUser->id, $request->reviewed_by);

        $this->assertDatabaseHas('customer_eligibility_logs', [
            'customer_id' => $customer->id,
            'event' => EligibilityLogEvent::RequestApproved->value,
            'to_status' => 'eligible',
            'user_id' => $this->adminUser->id,
        ]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->callerUser->id]);
    }

    public function test_admin_rejects_from_the_requests_list_and_a_new_request_can_follow(): void
    {
        $customer = $this->customer(self::notEligible());
        $request = app(CustomerEligibilityService::class)->raiseRequest($this->callerUser, $customer, 'Please review');

        $this->actingAs($this->adminUser);

        Livewire::test(ListCustomerEligibilityRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->callAction(TestAction::make('reject')->table($request), ['review_note' => 'CIBIL still below cut-off.'])
            ->assertHasNoActionErrors();

        $this->assertSame(EligibilityRequestStatus::Rejected, $request->fresh()->status);
        $this->assertSame('not_eligible', $customer->fresh()->eligibility_status);

        $this->assertTrue(app(CustomerEligibilityService::class)->canRaiseRequest($this->callerUser, $customer->fresh()));
    }

    public function test_admin_approves_from_the_requests_list(): void
    {
        $customer = $this->customer(self::notEligible());
        $request = app(CustomerEligibilityService::class)->raiseRequest($this->callerUser, $customer, 'Please review');

        $this->actingAs($this->adminUser);

        Livewire::test(ListCustomerEligibilityRequests::class)
            ->callAction(TestAction::make('approve')->table($request))
            ->assertHasNoActionErrors();

        $this->assertSame('eligible', $customer->fresh()->eligibility_status);
    }

    public function test_only_admin_can_review_a_request(): void
    {
        $customer = $this->customer(self::notEligible());
        $request = app(CustomerEligibilityService::class)->raiseRequest($this->callerUser, $customer, 'Please review');

        $this->actingAs($this->managerUser);

        Livewire::test(ViewCustomer::class, ['record' => $customer->id])
            ->assertActionDoesNotExist(TestAction::make('approveEligibilityRequest')->schemaComponent('customerEligibility'));

        Livewire::test(ListCustomerEligibilityRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->assertActionHidden(TestAction::make('approve')->table($request));

        $this->expectException(AuthorizationException::class);

        app(CustomerEligibilityService::class)->approve($this->managerUser, $request);
    }

    public function test_a_reviewed_request_cannot_be_reviewed_again(): void
    {
        $customer = $this->customer(self::notEligible());
        $service = app(CustomerEligibilityService::class);
        $request = $service->raiseRequest($this->callerUser, $customer, 'Please review');

        $service->reject($this->adminUser, $request, 'No');

        $this->expectException(AuthorizationException::class);

        $service->approve($this->adminUser, $request->fresh());
    }

    public function test_requests_list_is_scoped_to_the_viewers_branch(): void
    {
        $customer = $this->customer(self::notEligible());
        $request = app(CustomerEligibilityService::class)->raiseRequest($this->callerUser, $customer, 'Please review');

        $this->actingAs($this->outsiderUser);

        Livewire::test(ListCustomerEligibilityRequests::class)
            ->assertCanNotSeeTableRecords([$request]);
    }

    /*
    |--------------------------------------------------------------------------
    | Request menu — New Eligibility Request page
    |--------------------------------------------------------------------------
    */

    public function test_caller_raises_a_request_from_the_request_menu(): void
    {
        $customer = $this->customer(self::notEligible());

        $this->actingAs($this->callerUser);

        Livewire::test(CreateCustomerEligibilityRequest::class)
            ->fillForm(['customer_id' => $customer->id, 'reason' => 'New payslip shows 48k.'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(CustomerEligibilityRequestResource::getUrl('index'));

        $this->assertDatabaseHas('customer_eligibility_requests', [
            'customer_id' => $customer->id,
            'requested_by' => $this->callerUser->id,
            'reason' => 'New payslip shows 48k.',
            'status' => EligibilityRequestStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('customer_eligibility_logs', [
            'customer_id' => $customer->id,
            'event' => EligibilityLogEvent::RequestRaised->value,
        ]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->adminUser->id]);
    }

    public function test_request_menu_form_requires_customer_and_reason(): void
    {
        $this->actingAs($this->callerUser);

        Livewire::test(CreateCustomerEligibilityRequest::class)
            ->fillForm(['customer_id' => null, 'reason' => ''])
            ->call('create')
            ->assertHasFormErrors(['customer_id' => 'required', 'reason' => 'required']);

        $this->assertDatabaseCount('customer_eligibility_requests', 0);
    }

    public function test_request_menu_picker_lists_only_requestable_files_in_the_branch(): void
    {
        $requestable = $this->customer(self::notEligible());
        $alreadyWaiting = $this->customer(self::notEligible());
        $eligible = $this->customer(['eligibility_status' => 'eligible', 'journey_status' => 'sfl']);
        $consentPending = $this->customer(self::consentPending());
        $otherBranch = Customer::factory()->create([
            ...self::notEligible(),
            'assign_to' => $this->outsiderUser->employee_id,
            'employee_id' => $this->outsiderUser->employee_id,
        ]);

        app(CustomerEligibilityService::class)->raiseRequest($this->callerUser, $alreadyWaiting, 'Waiting');

        $this->actingAs($this->managerUser);

        $ids = CustomerEligibilityRequestForm::requestableCustomers()->pluck('id')->all();

        $this->assertSame([$requestable->id], $ids);
        $this->assertNotContains($eligible->id, $ids);
        $this->assertNotContains($consentPending->id, $ids);
        $this->assertNotContains($otherBranch->id, $ids);
    }

    public function test_request_menu_refuses_a_crafted_customer_from_another_branch(): void
    {
        $otherBranch = Customer::factory()->create([
            ...self::notEligible(),
            'assign_to' => $this->outsiderUser->employee_id,
            'employee_id' => $this->outsiderUser->employee_id,
        ]);

        $this->actingAs($this->callerUser);

        Livewire::test(CreateCustomerEligibilityRequest::class)
            ->set('data.customer_id', $otherBranch->id)
            ->set('data.reason', 'Sneaky')
            ->call('create')
            ->assertHasErrors(['data.customer_id']);

        $this->assertDatabaseCount('customer_eligibility_requests', 0);
    }

    /**
     * The sidebar is a hand-written list (AdminPanelProvider::buildNavigation()),
     * so a registered resource is only reachable by URL until it is added there.
     */
    public function test_eligibility_requests_appear_in_the_request_sidebar_group(): void
    {
        $url = CustomerEligibilityRequestResource::getUrl('index');

        foreach ([$this->adminUser, $this->callerUser] as $user) {
            // A caller with no monthly target is bounced to another page; the
            // sidebar is on every page, so follow it.
            $this->actingAs($user)
                ->followingRedirects()
                ->get('/admin')
                ->assertOk()
                ->assertSee('Eligibility Requests')
                ->assertSee($url, escape: false);
        }

        $support = User::factory()->create();
        $support->assignRole(OtherBankSupportService::ROLE);

        $this->actingAs($support)
            ->followingRedirects()
            ->get('/admin')
            ->assertDontSee($url, escape: false);
    }

    public function test_admin_reviews_but_does_not_raise_from_the_request_menu(): void
    {
        $this->actingAs($this->adminUser);

        $this->assertFalse(CustomerEligibilityRequestResource::canCreate());

        Livewire::test(ListCustomerEligibilityRequests::class)
            ->assertActionHidden('create');

        $this->actingAs($this->callerUser);

        Livewire::test(ListCustomerEligibilityRequests::class)
            ->assertActionVisible('create');
    }

    /*
    |--------------------------------------------------------------------------
    | Log visibility
    |--------------------------------------------------------------------------
    */

    public function test_log_is_visible_to_the_owner_chain_and_admin(): void
    {
        $customer = $this->customer(self::consentPending());

        app(CustomerEligibilityService::class)->changeStatus($this->callerUser, $customer, 'not_eligible', 'low_salary', 'Salary below 25k.');

        foreach ([$this->callerUser, $this->managerUser, $this->adminUser] as $viewer) {
            $this->actingAs($viewer);

            Livewire::test(ViewCustomer::class, ['record' => $customer->id])
                ->assertOk()
                ->assertSee('Eligibility log')
                ->assertSee('Salary below 25k.');
        }

        $this->assertSame(1, CustomerEligibilityLog::where('customer_id', $customer->id)->count());
    }

    public function test_creation_is_logged_as_the_first_line(): void
    {
        $customer = $this->customer(self::consentPending());

        app(CustomerEligibilityService::class)->logCreation($customer, $this->callerUser);

        $this->assertDatabaseHas('customer_eligibility_logs', [
            'customer_id' => $customer->id,
            'event' => EligibilityLogEvent::Created->value,
            'from_status' => null,
            'to_status' => 'consent_pending',
        ]);
    }

    /**
     * Two people, one file: journey_status is disabled but dehydrated, so a
     * save from an edit page opened BEFORE an eligibility change would write
     * the stale stage back. EditCustomer::mutateFormDataBeforeSave drops it.
     */
    public function test_a_stale_edit_page_cannot_write_the_old_journey_stage_back(): void
    {
        $customer = $this->customer(self::consentPending());

        $this->actingAs($this->managerUser);

        $page = Livewire::test(EditCustomer::class, ['record' => $customer->id])->instance();

        // Somebody else makes the file eligible while that page is open; the
        // next request to the page resolves the record fresh, as Livewire does.
        app(CustomerEligibilityService::class)->changeStatus($this->adminUser, $customer->fresh(), 'eligible');
        $page->getRecord()->refresh();

        $mutate = new \ReflectionMethod($page, 'mutateFormDataBeforeSave');
        $mutate->setAccessible(true);

        // The stale page still holds the pre-change stage.
        $this->assertSame('sfl', $mutate->invoke($page, ['journey_status' => 'not_started'])['journey_status']);

        // And the other way round: a file taken to Not Eligible keeps not_started.
        app(CustomerEligibilityService::class)->changeStatus(
            $this->adminUser,
            $this->customer(self::consentPending()),
            'not_eligible',
            'low_salary',
        );
        $notEligible = $this->customer(self::notEligible());
        $notEligiblePage = Livewire::test(EditCustomer::class, ['record' => $notEligible->id])->instance();

        $this->assertSame('not_started', $mutate->invoke($notEligiblePage, ['journey_status' => 'sfl'])['journey_status']);

        // A normal stage move on an eligible file is untouched.
        $this->assertSame('underwriting', $mutate->invoke($page, ['journey_status' => 'underwriting'])['journey_status']);
    }

    public function test_an_imported_file_starts_its_log_too(): void
    {
        $import = Import::create([
            'user_id' => $this->managerUser->id,
            'file_name' => 'customers.csv',
            'file_path' => 'imports/customers.csv',
            'importer' => CustomerImporter::class,
            'total_rows' => 1,
            'processed_rows' => 0,
            'successful_rows' => 0,
        ]);

        $columns = ['customer_name', 'mobile_no', 'pan_number', 'eligibility_status'];
        $row = [
            'customer_name' => 'Imported Customer',
            'mobile_no' => '9876500011',
            'pan_number' => 'ABCDE1234F',
            'eligibility_status' => 'not_eligible',
        ];

        (new CustomerImporter($import, array_combine($columns, $columns), []))($row);

        $customer = Customer::query()->where('mobile_no', '9876500011')->firstOrFail();

        $this->assertDatabaseHas('customer_eligibility_logs', [
            'customer_id' => $customer->id,
            'event' => EligibilityLogEvent::Created->value,
            'to_status' => 'not_eligible',
            'user_id' => $this->managerUser->id,
        ]);
    }

    public function test_request_factory_builds_a_pending_request_on_a_not_eligible_file(): void
    {
        $request = CustomerEligibilityRequest::factory()->create();

        $this->assertTrue($request->isPending());
        $this->assertSame('not_eligible', $request->customer->eligibility_status);
    }

    /**
     * @return array<string, mixed>
     */
    private static function consentPending(): array
    {
        return ['eligibility_status' => 'consent_pending', 'eligibility_reason' => null, 'journey_status' => 'not_started'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function notEligible(): array
    {
        return ['eligibility_status' => 'not_eligible', 'eligibility_reason' => 'low_salary', 'journey_status' => 'not_started'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes): Customer
    {
        return Customer::factory()->create([
            'assign_to' => $this->caller->id,
            'employee_id' => $this->caller->id,
            ...$attributes,
        ]);
    }
}
