<?php

namespace Tests\Feature;

use App\Enums\CustomerEditRequestStatus;
use App\Filament\Resources\CustomerEditRequests\CustomerEditRequestResource;
use App\Filament\Resources\CustomerEditRequests\Pages\CreateCustomerEditRequest;
use App\Filament\Resources\CustomerEditRequests\Pages\ListCustomerEditRequests;
use App\Filament\Resources\CustomerEditRequests\Pages\ViewCustomerEditRequest;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\CustomerEditRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\CustomerEditRequestService;
use App\Support\CustomerEditableFields;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Request → Customer Edit Requests: the owner's chain asks for new values
 * on one journey section with a reason; only the Admin approves, which
 * writes the values onto the file and logs the before/after.
 */
class CustomerEditRequestTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    private User $managerUser;

    private User $callerUser;

    private User $adminUser;

    private User $outsiderUser;

    private Customer $customer;

    private CustomerEditRequestService $requests;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Manager', 'Caller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $manager = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $this->caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'manager_id' => $manager->id]);

        $this->managerUser = User::factory()->create(['employee_id' => $manager->id]);
        $this->managerUser->assignRole('Manager');
        $this->callerUser = User::factory()->create(['employee_id' => $this->caller->id]);
        $this->callerUser->assignRole('Caller');
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('Admin');
        $outsider = Employee::factory()->create(['designation' => Employee::DESIGNATION_MANAGER]);
        $this->outsiderUser = User::factory()->create(['employee_id' => $outsider->id]);
        $this->outsiderUser->assignRole('Manager');

        $this->customer = Customer::factory()->create([
            'assign_to' => $this->caller->id,
            'employee_id' => $this->caller->id,
            'customer_name' => 'Ramesh Kulkarni',
            'salary' => 50000,
            'email' => 'old@example.test',
            'sanctioned_bank' => 'HDFC Bank',
            'disbursal_date' => '2026-09-01',
            'cashback' => 1000,
        ]);

        $this->requests = app(CustomerEditRequestService::class);
    }

    public function test_the_registry_groups_fields_by_section_and_leaves_out_the_controlled_ones(): void
    {
        $this->assertArrayHasKey('step_4_disbursal', CustomerEditableFields::sectionOptions());
        $this->assertArrayHasKey('cashback', CustomerEditableFields::fieldOptions('step_4_disbursal'));
        $this->assertArrayNotHasKey('cashback', CustomerEditableFields::fieldOptions('basic_details'));

        $all = collect(CustomerEditableFields::sections())->flatMap(fn (array $section): array => array_keys($section['fields']));
        $this->assertNotContains('eligibility_status', $all);
        $this->assertNotContains('journey_status', $all);
        $this->assertNotContains('assign_to', $all);

        $this->assertArrayHasKey('BFL Prime', CustomerEditableFields::choices('sanctioned_bank'));
        $this->assertArrayHasKey('other', CustomerEditableFields::choices('sanctioned_bank'));
    }

    public function test_a_caller_raises_a_request_that_snapshots_current_and_requested_values_and_changes_nothing_yet(): void
    {
        $request = $this->requests->raise($this->callerUser, $this->customer, 'basic_details', 'Customer shared a new payslip.', [
            ['field' => 'salary', 'value' => '65000'],
            ['field' => 'email', 'value' => 'new@example.test'],
        ]);

        $this->assertSame(CustomerEditRequestStatus::Pending, $request->status);
        $this->assertSame(['salary', 'email'], $request->items->pluck('field')->all());
        $this->assertSame('50000', $request->items[0]->current_value);
        $this->assertSame('65000', $request->items[0]->requested_value);
        $this->assertSame('Salary, Email Address', $request->fieldsLabel());

        $this->assertSame('old@example.test', $this->customer->fresh()->email);
        $this->assertSame(1, $this->adminUser->notifications()->count());
        $this->assertSame('customer_edit', $this->adminUser->notifications()->first()->category);
    }

    public function test_the_admin_approves_and_the_values_are_written_and_logged(): void
    {
        $request = $this->requests->raise($this->callerUser, $this->customer, 'step_4_disbursal', 'MIS shows a different cashback and date.', [
            ['field' => 'cashback', 'value' => '2500'],
            ['field' => 'disbursal_date', 'value' => '2026-09-05'],
        ]);

        $this->requests->approve($request, $this->adminUser, 'Checked against MIS.');

        $customer = $this->customer->fresh();
        $this->assertSame(2500.0, (float) $customer->cashback);
        $this->assertSame('2026-09-05', $customer->disbursal_date->toDateString());

        $request->refresh();
        $this->assertSame(CustomerEditRequestStatus::Approved, $request->status);
        $this->assertSame($this->adminUser->id, $request->reviewed_by);
        $this->assertNotNull($request->applied_at);
        $this->assertSame('1000', $request->items->firstWhere('field', 'cashback')->overwritten_value);
        $this->assertNotNull($request->items->firstWhere('field', 'cashback')->applied_at);

        $log = Activity::query()->where('description', 'like', 'Customer details changed through edit request #'.$request->id)->sole();
        $this->assertSame($this->adminUser->id, $log->causer_id);
        $this->assertSame(['old' => '1000', 'new' => '2500'], $log->properties['changes']['cashback']);

        $this->assertTrue($this->callerUser->notifications()->get()->contains(fn ($notification): bool => str_contains($notification->data['title'], 'approved and applied')));
    }

    public function test_the_overwritten_value_is_what_was_on_the_file_at_approval(): void
    {
        $request = $this->requests->raise($this->callerUser, $this->customer, 'basic_details', 'Typo in the name.', [
            ['field' => 'customer_name', 'value' => 'Ramesh Kulkarni Jr'],
        ]);

        $this->customer->update(['customer_name' => 'Ramesh K']);

        $this->requests->approve($request, $this->adminUser);

        $item = $request->fresh()->items->sole();
        $this->assertSame('Ramesh Kulkarni', $item->current_value);
        $this->assertSame('Ramesh K', $item->overwritten_value);
        $this->assertSame('Ramesh Kulkarni Jr', $this->customer->fresh()->customer_name);
    }

    public function test_rejecting_changes_nothing_and_needs_a_note(): void
    {
        $request = $this->requests->raise($this->callerUser, $this->customer, 'basic_details', 'New salary.', [
            ['field' => 'salary', 'value' => '65000'],
        ]);

        try {
            $this->requests->reject($request, $this->adminUser, '   ');
            $this->fail('A rejection without a note was accepted.');
        } catch (ValidationException) {
        }

        $this->requests->reject($request, $this->adminUser, 'Payslip not attached.');

        $this->assertSame(CustomerEditRequestStatus::Rejected, $request->fresh()->status);
        $this->assertSame(50000.0, (float) $this->customer->fresh()->salary);
        $this->assertNull($request->fresh()->items->sole()->applied_at);

        $this->expectException(AuthorizationException::class);
        $this->requests->approve($request->fresh(), $this->adminUser);
    }

    public function test_only_the_admin_reviews_and_only_the_owners_chain_raises(): void
    {
        $request = $this->requests->raise($this->managerUser, $this->customer, 'basic_details', 'New salary.', [
            ['field' => 'salary', 'value' => '65000'],
        ]);

        try {
            $this->requests->approve($request, $this->managerUser);
            $this->fail('A Manager approved an edit request.');
        } catch (AuthorizationException) {
        }

        $this->expectException(AuthorizationException::class);
        $this->requests->raise($this->outsiderUser, $this->customer, 'basic_details', 'Not my file.', [
            ['field' => 'salary', 'value' => '1'],
        ]);
    }

    public function test_invalid_requests_are_refused(): void
    {
        $cases = [
            'field outside the section' => ['basic_details', [['field' => 'cashback', 'value' => '1']]],
            'controlled field' => ['basic_details', [['field' => 'eligibility_status', 'value' => 'eligible']]],
            'unlisted dropdown choice' => ['step_3_credit_approval', [['field' => 'sanctioned_bank', 'value' => 'Made-up Bank']]],
            'non-numeric amount' => ['step_4_disbursal', [['field' => 'cashback', 'value' => 'lots']]],
            'unchanged value' => ['basic_details', [['field' => 'salary', 'value' => '50000.00']]],
            'same field twice' => ['basic_details', [['field' => 'salary', 'value' => '1'], ['field' => 'salary', 'value' => '2']]],
            'no fields' => ['basic_details', []],
            'unknown section' => ['made_up', [['field' => 'salary', 'value' => '1']]],
        ];

        foreach ($cases as $label => [$section, $changes]) {
            try {
                $this->requests->raise($this->callerUser, $this->customer, $section, 'Reason.', $changes);
                $this->fail("Accepted: {$label}");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, CustomerEditRequest::query()->count());
    }

    public function test_a_field_with_a_pending_request_cannot_be_requested_again(): void
    {
        $this->requests->raise($this->callerUser, $this->customer, 'basic_details', 'New salary.', [
            ['field' => 'salary', 'value' => '65000'],
        ]);

        $this->expectException(ValidationException::class);
        $this->requests->raise($this->managerUser, $this->customer, 'basic_details', 'Other salary.', [
            ['field' => 'salary', 'value' => '70000'],
        ]);
    }

    public function test_the_create_page_raises_a_request_from_the_section_and_field_dropdowns(): void
    {
        $this->actingAs($this->callerUser);

        Livewire::test(CreateCustomerEditRequest::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'section' => 'step_3_credit_approval',
            ])
            ->fillForm([
                'changes' => [
                    ['field' => 'sanctioned_bank', 'value_select' => 'BFL Prime'],
                    ['field' => 'approved_remarks', 'value_textarea' => 'Sanctioned under BFL Prime, not HDFC.'],
                ],
                'reason' => 'Wrong bank was selected at approval.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $request = CustomerEditRequest::query()->sole();
        $this->assertSame('step_3_credit_approval', $request->section);
        $this->assertSame('HDFC Bank', $request->items->firstWhere('field', 'sanctioned_bank')->current_value);
        $this->assertSame('BFL Prime', $request->items->firstWhere('field', 'sanctioned_bank')->requested_value);
        $this->assertSame($this->callerUser->id, $request->requested_by);
    }

    public function test_an_amount_is_typed_with_indian_commas_shown_in_words_and_saved_as_digits(): void
    {
        $this->actingAs($this->callerUser);

        Livewire::test(CreateCustomerEditRequest::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'section' => 'step_4_disbursal',
            ])
            ->fillForm([
                'changes' => [['field' => 'sanctioned_loan_amount', 'value_number' => '1250000']],
                'reason' => 'Final disbursed amount per MIS.',
            ])
            ->assertSee('Twelve Lakh Fifty Thousand')
            ->call('create')
            ->assertHasNoFormErrors();

        $item = CustomerEditRequest::query()->sole()->items->sole();
        $this->assertSame('1250000', $item->requested_value);
        $this->assertSame('₹12,50,000 (Twelve Lakh Fifty Thousand)', $item->display($item->requested_value));
    }

    public function test_the_admin_approves_from_the_listing_and_the_request_page_shows_the_changes(): void
    {
        $request = $this->requests->raise($this->callerUser, $this->customer, 'basic_details', 'New payslip.', [
            ['field' => 'salary', 'value' => '65000'],
        ]);

        $this->actingAs($this->callerUser);
        Livewire::test(ListCustomerEditRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->assertActionHidden(TestAction::make('approve')->table($request));

        $this->actingAs($this->adminUser);
        Livewire::test(ViewCustomerEditRequest::class, ['record' => $request->id])
            ->assertOk()
            ->assertSee('Customer Basic Details')
            ->assertSee('₹50,000 (Fifty Thousand)')
            ->assertSee('₹65,000 (Sixty Five Thousand)');

        Livewire::test(ListCustomerEditRequests::class)
            ->callAction(TestAction::make('approve')->table($request), ['review_note' => 'OK'])
            ->assertNotified('Approved — the file has been updated');

        $this->assertSame(65000.0, (float) $this->customer->fresh()->salary);
    }

    public function test_visibility_and_the_customer_page_button(): void
    {
        $request = $this->requests->raise($this->callerUser, $this->customer, 'basic_details', 'New salary.', [
            ['field' => 'salary', 'value' => '65000'],
        ]);

        $this->actingAs($this->outsiderUser);
        $this->assertSame([], CustomerEditRequestResource::getEloquentQuery()->pluck('id')->all());

        $this->actingAs($this->managerUser);
        $this->assertSame([$request->id], CustomerEditRequestResource::getEloquentQuery()->pluck('id')->all());

        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])
            ->assertActionVisible('requestEdit');
    }

    public function test_the_request_menu_shows_the_submodule(): void
    {
        $this->actingAs($this->adminUser)
            ->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSee(CustomerEditRequestResource::getUrl('index'), escape: false);
    }
}
