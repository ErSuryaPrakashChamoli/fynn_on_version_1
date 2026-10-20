<?php

namespace Tests\Feature;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Models\City;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use App\Support\BureauCheckCandidates;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A lead's owner is its creator's employee record. An Admin login without
 * an employee record therefore creates leads with no owner; the Admin —
 * company-wide — must still see them, in My Leads and in the CIBIL lead
 * picker. Everyone else keeps their own reporting-tree scope.
 */
class AdminCreatedLeadVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caller;

    private Employee $callerEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Caller', 'guard_name' => 'web']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);

        // As on the live data: the Admin login has no employee record.
        $this->admin = User::factory()->create(['employee_id' => null]);
        $this->admin->assignRole('Admin');

        $this->callerEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $this->caller = User::factory()->create(['employee_id' => $this->callerEmployee->id]);
        $this->caller->assignRole('Caller');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lead(array $attributes = []): Lead
    {
        return Lead::create([
            'customer_name' => 'Admin Created Lead',
            'mobile_no' => '9811122233',
            'pan_number' => 'ADMIN1234L',
            'follow_up_type' => 'Call',
            'status' => 'Interested',
            'remarks' => 'Created from the Admin login',
            ...$attributes,
        ]);
    }

    public function test_a_lead_created_by_an_admin_without_an_employee_record_is_listed_for_the_admin(): void
    {
        // What the lead form's hidden owner field saves for this Admin.
        $this->actingAs($this->admin);
        $this->assertNull(auth()->user()->employee?->id);

        $ownerless = $this->lead(['employee_id' => null]);
        $callers = $this->lead(['customer_name' => 'Caller Lead', 'employee_id' => $this->callerEmployee->id]);
        $converted = $this->lead(['customer_name' => 'Converted Lead', 'employee_id' => null, 'is_converted' => true]);

        Livewire::test(ListLeads::class)
            ->assertCanSeeTableRecords([$ownerless, $callers])
            ->assertCanNotSeeTableRecords([$converted])
            ->searchTable('Admin Created')
            ->assertCanSeeTableRecords([$ownerless]);
    }

    public function test_everyone_else_keeps_their_own_scope(): void
    {
        $ownerless = $this->lead(['employee_id' => null]);
        $callers = $this->lead(['customer_name' => 'Caller Lead', 'employee_id' => $this->callerEmployee->id]);

        $this->actingAs($this->caller);

        $this->assertSame([$callers->id], LeadResource::getEloquentQuery()->pluck('id')->all());
        $this->assertNotContains($ownerless->id, LeadResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_the_admin_can_pick_an_ownerless_lead_for_a_cibil_check(): void
    {
        $ownerless = $this->lead(['employee_id' => null, 'status' => CustomerAssignment::BUREAU_CHECK_STATUS]);

        $this->actingAs($this->admin);

        $this->assertArrayHasKey('lead:'.$ownerless->id, BureauCheckCandidates::leadOptions()['Waiting for bureau check'] ?? []);
        $this->assertTrue(BureauCheckCandidates::resolveLead('lead:'.$ownerless->id)?->is($ownerless));
    }

    // ---- Who owns a lead the Admin creates -------------------------------

    public function test_the_admin_assigns_a_new_lead_to_an_employee_who_then_sees_it(): void
    {
        City::query()->create(['city' => 'Jaipur', 'state' => 'Rajasthan', 'is_active' => 1]);
        $leftCompany = Employee::factory()->create(['emp_name' => 'Gone Employee', 'exit_status' => 'yes']);

        $this->actingAs($this->admin);

        Livewire::test(CreateLead::class)
            ->assertFormFieldExists('employee_id', fn (Select $field): bool => $field->isRequired()
                && array_key_exists($this->callerEmployee->id, $field->getOptions())
                && ! array_key_exists($leftCompany->id, $field->getOptions()))
            ->fillForm([
                'customer_name' => 'Arohi Mehta',
                'mobile_no' => '9811122127',
                'job_location' => 'Jaipur',
                'follow_up_type' => 'Call',
                'status' => 'Not Interested',
                'remarks' => 'Created from the Admin login',
            ])
            ->call('create')
            ->assertHasFormErrors(['employee_id' => 'required'])
            ->fillForm(['employee_id' => $this->callerEmployee->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $lead = Lead::query()->where('customer_name', 'Arohi Mehta')->sole();
        $this->assertSame($this->callerEmployee->id, $lead->employee_id);

        // The caller now has the lead (and its first follow-up) to work.
        $this->actingAs($this->caller);
        $this->assertContains($lead->id, LeadResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame($this->callerEmployee->id, $lead->followUps()->value('employee_id'));
    }

    public function test_the_admin_can_give_an_existing_ownerless_lead_an_owner(): void
    {
        City::query()->create(['city' => 'Jaipur', 'state' => 'Rajasthan', 'is_active' => 1]);
        $ownerless = $this->lead(['employee_id' => null, 'job_location' => 'Jaipur', 'status' => 'Not Interested']);
        $owned = $this->lead(['customer_name' => 'Owned Lead', 'pan_number' => 'OWNED1234L', 'employee_id' => $this->callerEmployee->id]);

        $this->actingAs($this->admin);

        Livewire::test(EditLead::class, ['record' => $owned->getRouteKey()])
            ->assertFormFieldIsHidden('employee_id');

        Livewire::test(EditLead::class, ['record' => $ownerless->getRouteKey()])
            ->assertFormFieldIsVisible('employee_id')
            ->fillForm(['employee_id' => $this->callerEmployee->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->callerEmployee->id, $ownerless->fresh()->employee_id);
    }

    public function test_an_employee_never_sees_the_owner_picker_and_keeps_owning_their_leads(): void
    {
        $this->actingAs($this->caller);

        Livewire::test(CreateLead::class)
            ->assertFormFieldIsHidden('employee_id')
            ->assertSchemaStateSet(['employee_id' => $this->callerEmployee->id]);

        $this->assertFalse(LeadForm::asksForOwner(null));
    }
}
